<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\OpsAuditLog;
use App\Models\ProductPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrialIssuanceService
{
    public function issue(Instance $instance, int $planId, User $actor, string $reason, bool $exception, bool $productionAddon, ?string $ip = null, ?string $userAgent = null): void
    {
        $reason = trim($reason);
        if (! $actor->is_admin || ! $actor->is_active || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'ต้องเป็น Admin ที่ใช้งานอยู่ และระบุเหตุผล 10–500 ตัวอักษร']);
        }

        DB::transaction(function () use ($instance, $planId, $actor, $reason, $exception, $productionAddon, $ip, $userAgent): void {
            // Lock the customer across all their instances before checking the shared Trial allowance.
            $customer = Customer::query()->whereKey($instance->customer_id)->lockForUpdate()->firstOrFail();
            $target = Instance::query()->with('product')->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if (! $customer->is_active || ! $target->is_active || ! $target->product->is_active || $target->product->code !== 'MINTERP') {
                throw ValidationException::withMessages(['instance' => 'ต้องเลือก MintERP Instance และลูกค้า/ผลิตภัณฑ์ที่เปิดใช้งาน']);
            }
            if (InstanceEntitlement::query()->where('instance_id', $target->id)->exists()
                || DB::table('trial_issuances')->where('instance_id', $target->id)->exists()) {
                throw ValidationException::withMessages(['instance' => 'Instance นี้มีสิทธิ์หรือเคยได้รับ Trial แล้ว']);
            }
            $plan = ProductPlan::query()->whereKey($planId)->where('product_id', $target->product_id)
                ->where('code', 'BUSINESS')->where('is_active', true)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['product_plan_id' => 'ต้องเลือก Business Plan version ที่เปิดใช้งานของ MintERP']);
            }
            $used = DB::table('trial_issuances')->where('customer_id', $customer->id)
                ->where('product_id', $target->product_id)->exists();
            if ($used && ! $exception) {
                throw ValidationException::withMessages(['exception_approved' => 'ลูกค้ารายนี้เคยได้รับ Trial ของผลิตภัณฑ์นี้แล้ว ต้องอนุมัติข้อยกเว้นและระบุเหตุผล']);
            }
            if ($exception && ! $used) {
                throw ValidationException::withMessages(['exception_approved' => 'ยังไม่เคยได้รับ Trial ไม่ต้องใช้ข้อยกเว้น']);
            }
            $startsAt = now()->utc();
            $expiresAt = $startsAt->copy()->addDays(30);
            $entitlement = InstanceEntitlement::create([
                'instance_id' => $target->id,
                'product_plan_id' => $plan->id,
                'commercial_mode' => 'SUBSCRIPTION',
                'status' => 'TRIAL',
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'overrides' => [],
                'production_addon' => $productionAddon,
                'cancel_at_period_end' => false,
                'source_revision' => 1,
                'change_reason' => $reason,
            ]);
            DB::table('trial_issuances')->insert([
                'customer_id' => $customer->id,
                'product_id' => $target->product_id,
                'instance_id' => $target->id,
                'product_plan_id' => $plan->id,
                'actor_id' => $actor->id,
                'standard_claim' => $exception ? null : $customer->id.':'.$target->product_id,
                'is_exception' => $exception,
                'production_addon' => $productionAddon,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'reason' => $reason,
                'created_at' => $startsAt,
                'updated_at' => $startsAt,
            ]);
            OpsAuditLog::create([
                'actor_id' => $actor->id,
                'action' => $exception ? 'trial.exception_issued' : 'trial.issued',
                'subject_type' => $entitlement->getMorphClass(),
                'subject_id' => (string) $entitlement->id,
                'old_values' => [],
                'new_values' => [
                    'customer_id' => $customer->id, 'product_code' => $target->product->code,
                    'instance_ref' => $target->instance_ref, 'plan_code' => $plan->code,
                    'plan_version' => $plan->version, 'starts_at' => $startsAt->toIso8601String(),
                    'expires_at' => $expiresAt->toIso8601String(), 'production_addon' => $productionAddon,
                    'exception_approved' => $exception, 'source_revision' => 1, 'reason' => $reason,
                ],
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
        });
    }

    public function enableProduction(Instance $instance, User $actor, string $reason, ?string $ip = null, ?string $userAgent = null): void
    {
        $reason = $this->validateApproval($actor, $reason);
        DB::transaction(function () use ($instance, $actor, $reason, $ip, $userAgent): void {
            $target = Instance::query()->with(['customer', 'product'])->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $entitlement = InstanceEntitlement::query()->where('instance_id', $target->id)->lockForUpdate()->first();
            $this->requireCurrentTrial($target, $entitlement);
            if (! $entitlement->starts_at || ! $entitlement->expires_at || now()->lt($entitlement->starts_at) || now()->gte($entitlement->expires_at)) {
                throw ValidationException::withMessages(['production_addon' => 'เปิด Production ได้เฉพาะระหว่าง Trial ที่ยังไม่หมดอายุ']);
            }
            if ($entitlement->production_addon) {
                throw ValidationException::withMessages(['production_addon' => 'Production Trial เปิดใช้งานอยู่แล้ว']);
            }
            $before = $this->snapshot($entitlement);
            $entitlement->update(['production_addon' => true, 'source_revision' => $entitlement->source_revision + 1, 'change_reason' => $reason]);
            $this->recordChange($entitlement, $actor, 'trial.production_enabled', $reason, $before, $this->snapshot($entitlement), $ip, $userAgent);
        });
    }

    public function convertToPaid(Instance $instance, int $planId, Carbon $expiresAt, User $actor, string $reason, ?string $ip = null, ?string $userAgent = null): void
    {
        $reason = $this->validateApproval($actor, $reason);
        DB::transaction(function () use ($instance, $planId, $expiresAt, $actor, $reason, $ip, $userAgent): void {
            $target = Instance::query()->with(['customer', 'product'])->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $entitlement = InstanceEntitlement::query()->where('instance_id', $target->id)->lockForUpdate()->first();
            $this->requireCurrentTrial($target, $entitlement);
            $plan = ProductPlan::query()->whereKey($planId)->where('product_id', $target->product_id)
                ->where('is_active', true)->whereIn('code', ['CORE', 'BUSINESS'])->first();
            if (! $plan) {
                throw ValidationException::withMessages(['product_plan_id' => 'เลือก MintERP Core/Business Plan version ที่เปิดใช้งาน']);
            }
            $startsAt = now()->utc();
            if ($expiresAt->lte($startsAt)) {
                throw ValidationException::withMessages(['paid_expires_at' => 'วันหมดอายุของแพ็กเกจชำระเงินต้องอยู่หลังเวลาปัจจุบัน (เวลาไทย)']);
            }
            $before = $this->snapshot($entitlement);
            $entitlement->update([
                'product_plan_id' => $plan->id,
                'status' => 'ACTIVE',
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'overrides' => [],
                'cancel_at_period_end' => false,
                'source_revision' => $entitlement->source_revision + 1,
                'change_reason' => $reason,
            ]);
            $this->recordChange($entitlement, $actor, 'trial.converted_to_paid', $reason, $before, $this->snapshot($entitlement), $ip, $userAgent);
        });
    }

    private function validateApproval(User $actor, string $reason): string
    {
        $reason = trim($reason);
        if (! $actor->is_admin || ! $actor->is_active || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'ต้องเป็น Admin ที่ใช้งานอยู่ และระบุเหตุผล 10–500 ตัวอักษร']);
        }
        return $reason;
    }

    private function requireCurrentTrial(Instance $instance, ?InstanceEntitlement $entitlement): void
    {
        if (! $instance->is_active || ! $instance->customer->is_active || ! $instance->product->is_active || $instance->product->code !== 'MINTERP'
            || ! $entitlement || $entitlement->commercial_mode !== 'SUBSCRIPTION' || $entitlement->status !== 'TRIAL'
            || $entitlement->productPlan?->code !== 'BUSINESS'
            || ! DB::table('trial_issuances')->where('instance_id', $instance->id)->exists()) {
            throw ValidationException::withMessages(['instance' => 'ต้องเป็น MintERP Instance ที่มี Trial และยังไม่ถูกแปลงเป็น paid']);
        }
    }

    private function snapshot(InstanceEntitlement $entitlement): array
    {
        $entitlement->load('productPlan');
        return [
            'plan_code' => $entitlement->productPlan?->code,
            'plan_version' => $entitlement->productPlan?->version,
            'status' => $entitlement->status,
            'starts_at' => $entitlement->starts_at?->copy()->utc()->toIso8601String(),
            'expires_at' => $entitlement->expires_at?->copy()->utc()->toIso8601String(),
            'production_addon' => $entitlement->production_addon,
            'effective_values' => $entitlement->effectiveValues(),
            'source_revision' => $entitlement->source_revision,
        ];
    }

    private function recordChange(InstanceEntitlement $entitlement, User $actor, string $action, string $reason, array $before, array $after, ?string $ip, ?string $userAgent): void
    {
        OpsAuditLog::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $entitlement->getMorphClass(),
            'subject_id' => (string) $entitlement->id,
            'old_values' => $before,
            'new_values' => $after + ['reason' => $reason],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
