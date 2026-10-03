<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\InstanceRenewal;
use App\Models\OpsAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class InstanceRenewalService
{
    public function save(Instance $instance, array $input, User $actor, ?InstanceRenewal $renewal = null): InstanceRenewal
    {
        $this->admin($actor);
        if (isset($input['reason']) && is_string($input['reason'])) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, [
            'kind' => ['required', 'in:RENEWAL,GRACE,TRIAL'],
            'period_start' => ['required', 'date_format:Y-m-d\TH:i'],
            'period_end' => ['required', 'date_format:Y-m-d\TH:i', 'after:period_start'],
            'payment_due_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'agreed_amount' => ['required', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?\z/'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_version' => [$renewal ? 'required' : 'nullable', 'integer', 'min:1'],
        ])->validate();
        if ($this->money($data['agreed_amount']) === '0.00') {
            $this->reject('agreed_amount', 'ยอดตามข้อตกลงต้องมากกว่า 0');
        }
        return DB::transaction(function () use ($instance, $data, $actor, $renewal): InstanceRenewal {
            [$target, $entitlement] = $this->lock($instance->id);
            $this->eligible($target, $entitlement, $data['kind']);
            $this->revision($entitlement, (int) $data['expected_revision']);
            $current = $renewal ? $this->lockedRecord($target, $renewal) : null;
            if ($current && ($current->status !== 'PENDING' || (int) ($data['expected_version'] ?? 0) !== $current->version)) {
                $this->reject('renewal', 'รายการเปลี่ยนแล้ว กรุณาโหลดใหม่; แก้ได้เฉพาะรายการรอยืนยัน');
            }
            if (! $current && InstanceRenewal::where('open_instance_id', $target->id)->exists()) {
                $this->reject('renewal', 'Instance นี้มีรายการเปิดอยู่แล้ว กรุณาใช้รายการเดิม');
            }
            $start = $this->thai($data['period_start']);
            $end = $this->thai($data['period_end']);
            $this->period($entitlement, $data['kind'], $start, $end);
            $before = $current ? $this->recordSnapshot($current) : [];
            $current ??= new InstanceRenewal(['instance_id' => $target->id, 'open_instance_id' => $target->id, 'created_by' => $actor->id]);
            $current->fill([
                'kind' => $data['kind'], 'period_start' => $start, 'period_end' => $end,
                'payment_due_at' => $this->thai($data['payment_due_at']),
                'grace_days' => $data['kind'] === 'TRIAL' ? 0 : 15,
                'agreed_amount' => $this->money($data['agreed_amount']),
                'external_reference' => $data['external_reference'] ?? null, 'note' => $data['note'] ?? null,
                'reason' => trim($data['reason']), 'expected_revision' => $entitlement->source_revision,
                'before_values' => $entitlement->renewalSnapshot(),
            ]);
            if ($renewal) {
                $current->version++;
            }
            $current->save();
            $this->audit($current, $actor, $renewal ? 'renewal.updated' : 'renewal.created', $before);
            return $current->refresh();
        });
    }

    public function confirm(InstanceRenewal $renewal, array $input, UploadedFile $file, User $actor): void
    {
        $this->admin($actor);
        foreach (['reason', 'external_reference', 'difference_reason'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make(array_merge($input, ['evidence' => $file]), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'received_amount' => ['required', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?\z/'],
            'received_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'external_reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'difference_reason' => ['nullable', 'string', 'min:10', 'max:500'],
            'settled' => ['required', 'accepted'],
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
        ])->validate();
        $receivedAt = $this->thai($data['received_at']);
        if ($receivedAt->gt(now()) || $this->money($data['received_amount']) === '0.00') {
            $this->reject('received_at', 'ยอดรับต้องมากกว่า 0 และวันรับเงินต้องไม่อยู่ในอนาคต');
        }
        $path = null;
        try {
            DB::transaction(function () use ($renewal, $data, $file, $actor, $receivedAt, &$path): void {
                [$target, $entitlement] = $this->lock($renewal->instance_id);
                $current = $this->lockedRecord($target, $renewal);
                if ($current->status !== 'PENDING' || (int) $data['expected_version'] !== $current->version) {
                    $this->reject('renewal', 'ยืนยันได้เฉพาะรายการรอยืนยันชำระ');
                }
                $this->eligible($target, $entitlement, $current->kind);
                $this->revision($entitlement, $current->expected_revision);
                if ($this->money($data['received_amount']) !== $current->agreed_amount && empty(trim($data['difference_reason'] ?? ''))) {
                    $this->reject('difference_reason', 'ยอดไม่เท่ากัน ต้องระบุเหตุผลและให้บัญชีภายนอกยืนยันว่าเคลียร์ครบแล้ว');
                }
                $path = Storage::disk('local')->putFile('renewal-evidence', $file);
                if (! $path) {
                    $this->reject('evidence', 'บันทึกหลักฐานไม่สำเร็จ ยังไม่ได้ยืนยันรับชำระ');
                }
                $before = $this->recordSnapshot($current);
                $current->update([
                    'status' => 'CONFIRMED', 'received_amount' => $this->money($data['received_amount']),
                    'received_at' => $receivedAt, 'external_reference' => trim($data['external_reference']),
                    'reason' => trim($data['reason']), 'difference_reason' => $data['difference_reason'] ?? null,
                    'evidence_path' => $path, 'evidence_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'evidence_mime' => $file->getMimeType(), 'evidence_size' => $file->getSize(),
                    'confirmed_by' => $actor->id, 'confirmed_at' => now(),
                ]);
                $this->audit($current, $actor, 'renewal.confirmed', $before);
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function apply(InstanceRenewal $renewal, User $actor, int $expectedRevision, string $reason): void
    {
        $this->admin($actor);
        $this->reason($reason);
        DB::transaction(function () use ($renewal, $actor, $expectedRevision, $reason): void {
            [$target, $entitlement] = $this->lock($renewal->instance_id);
            $current = $this->lockedRecord($target, $renewal);
            $this->eligible($target, $entitlement, $current->kind);
            $this->requireConfirmed($current, $entitlement, $expectedRevision);
            if ($current->kind === 'TRIAL') {
                $this->reject('renewal', 'ใช้ขั้นตอนแปลง Trial เดิม โดยเลือกหลักฐานรายการนี้');
            }
            $this->period($entitlement, $current->kind, $current->period_start, $current->period_end);
            if ($current->accessEnd()->lt($entitlement->expires_at)) {
                $this->reject('period_end', 'การต่ออายุต้องไม่ลดวันสิ้นสุดสิทธิ์เดิม');
            }
            $before = $entitlement->renewalSnapshot();
            $state = [
                'paid_period_end' => $current->period_end, 'grace_days' => 15,
                'expires_at' => $current->accessEnd(), 'source_revision' => $entitlement->source_revision + 1,
                'change_reason' => trim($reason),
            ];
            if ($current->kind === 'RENEWAL' && $current->period_start->gt($entitlement->billingBoundary())) {
                if ($entitlement->expires_at->gt(now())) {
                    $this->reject('period_start', 'ยังมีสิทธิ์ใช้งานอยู่ ห้ามเริ่มรอบขาดช่วงผ่านปุ่มต่ออายุ');
                }
                $state['starts_at'] = $current->period_start;
            }
            $entitlement->update($state);
            $this->markApplied($current, $entitlement, $actor, $before, $reason);
        });
    }

    // Called inside the existing Trial transaction, after locking instance then entitlement.
    public function trialPayment(Instance $instance, InstanceEntitlement $entitlement, ?int $id, Carbon $end): InstanceRenewal
    {
        $record = $id ? InstanceRenewal::whereKey($id)->where('instance_id', $instance->id)->lockForUpdate()->first() : null;
        if (! $record || $record->kind !== 'TRIAL' || ! $record->period_end->equalTo($end)) {
            $this->reject('renewal_id', 'ต้องเลือกรายการรับชำระรอบแรกที่ยืนยันแล้ว และวันหมดอายุตรงกับรอบที่ชำระ');
        }
        $this->requireConfirmed($record, $entitlement, $record->expected_revision);
        return $record;
    }

    public function markApplied(InstanceRenewal $record, InstanceEntitlement $entitlement, User $actor, array $before, string $reason): void
    {
        $old = $this->recordSnapshot($record);
        $record->update([
            'status' => 'APPLIED', 'open_instance_id' => null, 'applied_by' => $actor->id, 'applied_at' => now(),
            'applied_revision' => $entitlement->source_revision, 'before_values' => $before,
            'after_values' => $entitlement->renewalSnapshot(), 'reason' => trim($reason),
        ]);
        $this->audit($record, $actor, 'renewal.applied', $old);
    }

    public function void(InstanceRenewal $renewal, User $actor, string $reason): void
    {
        $this->admin($actor);
        $this->reason($reason);
        DB::transaction(function () use ($renewal, $actor, $reason): void {
            [$target] = $this->lock($renewal->instance_id);
            $current = $this->lockedRecord($target, $renewal);
            if (! in_array($current->status, ['PENDING', 'CONFIRMED'], true)) {
                $this->reject('renewal', 'ยกเลิกได้เฉพาะรายการที่ยังไม่ได้นำไปใช้');
            }
            $old = $this->recordSnapshot($current);
            $current->update(['status' => 'VOID', 'open_instance_id' => null, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);
            $this->audit($current, $actor, 'renewal.voided', $old);
        });
    }

    private function lock(int $id): array
    {
        $instance = Instance::with(['customer', 'product'])->whereKey($id)->lockForUpdate()->firstOrFail();
        $entitlement = InstanceEntitlement::where('instance_id', $id)->lockForUpdate()->first();
        return [$instance, $entitlement];
    }

    private function lockedRecord(Instance $instance, InstanceRenewal $renewal): InstanceRenewal
    {
        return InstanceRenewal::whereKey($renewal->id)->where('instance_id', $instance->id)->lockForUpdate()->firstOrFail();
    }

    private function eligible(Instance $instance, ?InstanceEntitlement $entitlement, string $kind): void
    {
        if (! $instance->is_active || ! $instance->customer->is_active || ! $instance->product->is_active
            || ! $entitlement || $entitlement->commercial_mode !== 'SUBSCRIPTION'
            || $entitlement->status !== ($kind === 'TRIAL' ? 'TRIAL' : 'ACTIVE') || $entitlement->cancel_at_period_end
            || ! $entitlement->starts_at || ! $entitlement->expires_at || ! $entitlement->productPlan
            || $entitlement->productPlan->product_id !== $instance->product_id) {
            $this->reject('instance', 'ต้องเป็น Subscription ที่พร้อมใช้งาน ไม่ถูกระงับ/กำหนดยกเลิก และ Plan ตรงกับ Product');
        }
        if ($kind === 'GRACE' && ($entitlement->paid_period_end || $entitlement->expires_at->lte(now()) || $entitlement->starts_at->gt(now()))) {
            $this->reject('kind', 'เปิดผ่อนผันรอบเดิมได้ครั้งเดียว ก่อนสิทธิ์เดิมหมดอายุ');
        }
        if ($kind === 'TRIAL' && $instance->product->code !== 'MINTERP') {
            $this->reject('kind', 'Trial conversion รองรับเฉพาะ MintERP');
        }
    }

    private function period(InstanceEntitlement $entitlement, string $kind, Carbon $start, Carbon $end): void
    {
        if ($end->lte($start) || $end->lte(now()) || $start->gt(now()) && $kind !== 'RENEWAL') {
            $this->reject('period_end', 'รอบบริการต้องถูกต้อง วันสิ้นสุดอยู่ในอนาคต และวันเริ่มไม่อยู่อนาคต');
        }
        if ($kind === 'GRACE' && (! $start->equalTo($entitlement->starts_at) || ! $end->equalTo($entitlement->expires_at))) {
            $this->reject('period_end', 'เปิดผ่อนผันต้องใช้ช่วงบริการเดิมที่ตรวจยืนยันแล้ว');
        }
        if ($kind === 'RENEWAL') {
            $boundary = $entitlement->billingBoundary();
            if ($start->lt($boundary) || $end->lte($boundary) || ($start->gt($boundary) && $start->gt(now()))) {
                $this->reject('period_start', 'รอบใหม่ต้องต่อจากขอบเขตรอบที่ชำระเดิม; รอบขาดช่วงห้ามเริ่มในอนาคต');
            }
        }
    }

    private function requireConfirmed(InstanceRenewal $record, InstanceEntitlement $entitlement, int $revision): void
    {
        if ($record->status !== 'CONFIRMED' || ! $record->evidence_path || ! Storage::disk('local')->exists($record->evidence_path)) {
            $this->reject('renewal', 'ต้องเป็นรายการที่ยืนยันรับชำระและมีหลักฐานอยู่จริง ยังไม่ได้นำไปใช้');
        }
        $this->revision($entitlement, $revision);
        $this->revision($entitlement, $record->expected_revision);
    }

    private function revision(InstanceEntitlement $entitlement, int $revision): void
    {
        if ($entitlement->source_revision !== $revision) {
            $this->reject('expected_revision', 'สิทธิ์เปลี่ยนแล้ว กรุณาโหลดใหม่และทบทวนรายการ; ถ้ายืนยันแล้วให้ยกเลิกรายการและสร้างใหม่');
        }
    }

    private function admin(User $actor): void
    {
        if (! $actor->is_admin || ! $actor->is_active) {
            $this->reject('actor', 'ต้องเป็น Admin ที่เปิดใช้งาน');
        }
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:10', 'max:500']])->validate();
    }

    private function thai(string $value): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Bangkok')->utc();
    }

    private function money(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function recordSnapshot(InstanceRenewal $record): array
    {
        return $record->only(['instance_id', 'kind', 'status', 'period_start', 'period_end', 'payment_due_at', 'grace_days',
            'agreed_amount', 'received_amount', 'received_at', 'external_reference', 'note', 'reason', 'difference_reason', 'expected_revision', 'version',
            'confirmed_by', 'confirmed_at', 'applied_by', 'applied_at', 'applied_revision', 'before_values', 'after_values', 'voided_by', 'voided_at', 'void_reason']);
    }

    private function audit(InstanceRenewal $record, User $actor, string $action, array $old): void
    {
        OpsAuditLog::create([
            'actor_id' => $actor->id, 'action' => $action, 'subject_type' => $record->getMorphClass(), 'subject_id' => (string) $record->id,
            'old_values' => $old, 'new_values' => $this->recordSnapshot($record),
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
