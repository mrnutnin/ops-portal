<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\InstanceSyncCredential;
use App\Models\OpsAuditLog;
use App\Models\ProductPlan;
use App\Services\InstanceEntitlementDelivery;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminInstanceEntitlementController extends Controller
{
    public function edit(Instance $instance): View
    {
        $instance->load(['customer', 'product', 'entitlement.productPlan']);
        $plans = ProductPlan::query()->where('product_id', $instance->product_id)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $instance->entitlement?->product_plan_id))
            ->orderBy('code')->orderByDesc('version')->get();

        return view('admin.instances.entitlement', [
            'instance' => $instance,
            'entitlement' => $instance->entitlement,
            'plans' => $plans,
            'isMintErp' => $instance->product->code === 'MINTERP',
            'syncCredential' => Schema::hasTable('instance_sync_credentials')
                ? InstanceSyncCredential::query()->where('instance_id', $instance->id)->first(['id', 'instance_id', 'target_url', 'key_id', 'previous_key_id']) : null,
            'lastDelivery' => OpsAuditLog::query()->where('subject_type', $instance->getMorphClass())
                ->where('subject_id', (string) $instance->id)->whereIn('action', ['instance.sync.delivered', 'instance.sync.delivery_failed', 'instance.sync.target_changed'])
                ->latest('id')->first(),
            'hasQuotaPlans' => in_array($instance->product->code, ['MINTERP', 'MINTPOS', 'MINTHRM'], true),
            'trialIssued' => DB::table('trial_issuances')->where('instance_id', $instance->id)->exists(),
            'businessPlans' => $plans->where('code', 'BUSINESS')->where('is_active', true),
            'paidPlans' => $plans->whereIn('code', ['CORE', 'BUSINESS'])->where('is_active', true),
            'trialHistory' => DB::table('trial_issuances')->join('instances', 'instances.id', '=', 'trial_issuances.instance_id')
                ->join('users', 'users.id', '=', 'trial_issuances.actor_id')
                ->where('trial_issuances.customer_id', $instance->customer_id)
                ->where('trial_issuances.product_id', $instance->product_id)
                ->select('trial_issuances.*', 'instances.name as instance_name', 'users.name as actor_name')
                ->orderByDesc('trial_issuances.id')->paginate(10),
        ]);
    }

    public function enrollLocal(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $this->requireLocalAdmin($request);
        return $this->enrollFromRequest($request, $instance, $delivery);
    }

    public function pushLocal(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $this->requireLocalAdmin($request);
        return $this->pushFromRequest($request, $instance, $delivery);
    }

    public function enrollWeb(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $this->requireSecureAdmin($request);
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        return $this->enrollFromRequest($request, $instance, $delivery);
    }

    public function pushWeb(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $this->requireSecureAdmin($request);
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        return $this->pushFromRequest($request, $instance, $delivery);
    }

    private function enrollFromRequest(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $data = $request->validate([
            'target_url' => ['required', 'string', 'max:255'],
            'key_id' => ['required', 'string', 'max:64'],
            'secret' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $delivery->enroll($instance, $data['target_url'], $data['key_id'], $data['secret'], $request->user(), $request->input('reason'));
        return back()->with('status', 'ผูกปลายทางแล้ว แต่ยังไม่ได้ส่งสิทธิ์ กรุณาตรวจ ERP receiver และ snapshot ก่อนกดส่ง');
    }

    private function pushFromRequest(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $result = $delivery->push($instance, $request->user(), $request->input('reason'));
        return back()->with('status', 'ERP ตอบรับ '.($result['status'] === 'applied' ? 'การเปลี่ยนสิทธิ์' : 'การส่งซ้ำที่ไม่เปลี่ยนสิทธิ์').' revision '.$result['source_revision']);
    }

    private function requireSecureAdmin(Request $request): void
    {
        $local = app()->environment('local')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['127.0.0.1', 'localhost'], true);
        abort_unless(($local || $request->isSecure()) && $request->user()?->is_admin && $request->user()->is_active, 404);
    }

    private function requireLocalAdmin(Request $request): void
    {
        abort_unless(app()->environment('local')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['127.0.0.1', 'localhost'], true)
            && $request->user()?->is_admin && $request->user()->is_active, 404);
    }

    public function changeTarget(Request $request, Instance $instance, InstanceEntitlementDelivery $delivery): RedirectResponse
    {
        $data = $request->validate([
            'expected_target_url' => ['required', 'string', 'max:255'],
            'target_url' => ['required', 'string', 'max:255'],
            'target_change_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $changed = $delivery->changeTarget($instance, $data['expected_target_url'], $data['target_url'], $data['target_change_reason'], $request->user());
        return back()->with('status', $changed
            ? 'บันทึกปลายทางแล้ว แต่ปลายทางใหม่ยังไม่ได้ตอบรับจาก ERP กรุณาตรวจแล้วกดส่งสิทธิ์แยกต่างหาก'
            : 'ปลายทางไม่มีการเปลี่ยนแปลง');
    }

    public function save(Request $request, Instance $instance): RedirectResponse
    {
        $productCode = $instance->product()->value('code');
        $isMintErp = $productCode === 'MINTERP';
        $request->merge([
            'quota_overrides' => $request->input('quota_overrides', []),
            'included_modules' => $request->input('included_modules', []),
            'production_addon' => $request->input('production_addon', false),
            'cancel_at_period_end' => $request->input('cancel_at_period_end', false),
            'customize_modules' => $request->input('customize_modules', false),
        ]);

        $rules = [
            'commercial_mode' => ['required', 'in:SUBSCRIPTION,LICENSE'],
            'status' => ['required', 'in:ACTIVE,SUSPENDED'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'expires_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'cancel_at_period_end' => ['boolean'],
            'change_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];

        if ($request->input('commercial_mode') === 'SUBSCRIPTION') {
            $rules['starts_at'] = ['required', 'date_format:Y-m-d\TH:i'];
            $rules['expires_at'] = ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'];
            if (in_array($productCode, ['MINTERP', 'MINTPOS', 'MINTHRM'], true)) {
                $currentPlanId = InstanceEntitlement::query()->where('instance_id', $instance->id)->value('product_plan_id');
                $rules += [
                    'product_plan_id' => [
                        'required', 'integer', Rule::exists('product_plans', 'id')->where(fn ($query) => $query
                            ->where('product_id', $instance->product_id)
                            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentPlanId))),
                    ],
                    'quota_overrides' => ['array:quota_users,quota_branches,quota_warehouses'],
                    'quota_overrides.quota_users' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                    'quota_overrides.quota_branches' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                    'quota_overrides.quota_warehouses' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                ];
                if ($isMintErp) {
                    $rules += [
                        'customize_modules' => ['boolean'],
                        'included_modules' => ['array', 'max:2'],
                        'included_modules.*' => ['string', 'in:crm,asset', 'distinct'],
                        'production_addon' => ['boolean'],
                    ];
                } else {
                    $rules += ['included_modules' => ['array', 'size:0'], 'production_addon' => ['boolean']];
                }
            }
        }

        $data = $request->validate($rules);
        if (! $isMintErp && $request->boolean('production_addon')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['production_addon' => 'ผลิตภัณฑ์นี้ไม่มี Production add-on']);
        }
        $state = $this->stateFrom($data, $instance, $isMintErp);
        $changed = DB::transaction(function () use ($request, $instance, $state): bool {
            $lockedInstance = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $current = InstanceEntitlement::query()->where('instance_id', $lockedInstance->id)->lockForUpdate()->first();
            if (DB::table('trial_issuances')->where('instance_id', $lockedInstance->id)->exists()
                && (! $current || $current->status === 'TRIAL')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['commercial_mode' => 'Instance นี้มีประวัติ Trial; ห้ามเปลี่ยนสิทธิ์ผ่านฟอร์มทั่วไปจนกว่าจะมีขั้นตอนแปลง Trial ที่ตรวจสอบและบันทึก Audit']);
            }
            if ($current && $this->sameState($current, $state)) {
                return false;
            }

            $old = $current ? $this->snapshot($current->load('productPlan'), $lockedInstance) : [];
            $revision = ($current?->source_revision ?? 0) + 1;
            $entitlement = $current ?? new InstanceEntitlement(['instance_id' => $lockedInstance->id]);
            $entitlement->fill($state + [
                'source_revision' => $revision,
                'change_reason' => $state['change_reason'],
            ]);
            $entitlement->save();
            $new = $this->snapshot($entitlement->load('productPlan'), $lockedInstance);
            OpsAuditLog::create([
                'actor_id' => $request->user()->id,
                'action' => $current ? 'instance.entitlement.updated' : 'instance.entitlement.assigned',
                'subject_type' => $entitlement->getMorphClass(),
                'subject_id' => (string) $entitlement->getKey(),
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return true;
        });

        return back()->with('status', $changed ? 'บันทึกสิทธิ์ของ Instance แล้ว' : 'สิทธิ์ของ Instance ไม่มีการเปลี่ยนแปลง');
    }

    private function stateFrom(array $data, Instance $instance, bool $isMintErp): array
    {
        $subscription = $data['commercial_mode'] === 'SUBSCRIPTION';
        $overrides = [];
        $planId = null;
        $productionAddon = false;

        if ($subscription && isset($data['product_plan_id'])) {
            $planId = (int) $data['product_plan_id'];
            foreach ($data['quota_overrides'] ?? [] as $field => $value) {
                if ($value !== null && $value !== '') {
                    $overrides[$field] = (int) $value;
                }
            }
            if ($isMintErp && $data['customize_modules']) {
                $modules = array_values(array_unique($data['included_modules'] ?? []));
                sort($modules);
                $overrides['included_modules'] = $modules;
            }
            $productionAddon = $isMintErp && (bool) ($data['production_addon'] ?? false);
        }

        return [
            'instance_id' => $instance->id,
            'product_plan_id' => $planId,
            'commercial_mode' => $data['commercial_mode'],
            'status' => $data['status'],
            'starts_at' => $subscription ? Carbon::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Asia/Bangkok')->utc() : null,
            'expires_at' => $subscription ? Carbon::createFromFormat('!Y-m-d\TH:i', $data['expires_at'], 'Asia/Bangkok')->utc() : null,
            'overrides' => $overrides,
            'production_addon' => $productionAddon,
            'cancel_at_period_end' => $subscription && (bool) ($data['cancel_at_period_end'] ?? false),
            'change_reason' => trim($data['change_reason']),
        ];
    }

    private function sameState(InstanceEntitlement $current, array $state): bool
    {
        return $current->commercial_mode === $state['commercial_mode']
            && $current->status === $state['status']
            && (int) $current->product_plan_id === (int) ($state['product_plan_id'] ?? 0)
            && $this->sameDate($current->starts_at, $state['starts_at'])
            && $this->sameDate($current->expires_at, $state['expires_at'])
            && ($current->overrides ?? []) === ($state['overrides'] ?? [])
            && $current->production_addon === $state['production_addon']
            && $current->cancel_at_period_end === $state['cancel_at_period_end'];
    }

    private function sameDate(?Carbon $current, ?Carbon $next): bool
    {
        return $current === null ? $next === null : $next !== null && $current->equalTo($next);
    }

    private function snapshot(InstanceEntitlement $entitlement, Instance $instance): array
    {
        $isMintErp = $instance->product->code === 'MINTERP';
        $values = $entitlement->commercial_mode === 'SUBSCRIPTION' && $entitlement->productPlan
            ? $entitlement->effectiveValues() + ($isMintErp ? ['production_addon' => $entitlement->production_addon] : [])
            : [];

        return [
            'product_code' => $instance->product->code,
            'instance_ref' => $instance->instance_ref,
            'commercial_mode' => $entitlement->commercial_mode,
            'status' => $entitlement->status,
            'plan_code' => $entitlement->productPlan?->code,
            'plan_version' => $entitlement->productPlan?->version,
            'starts_at' => $entitlement->starts_at?->toIso8601String(),
            'expires_at' => $entitlement->expires_at?->toIso8601String(),
            'cancel_at_period_end' => $entitlement->cancel_at_period_end,
            'entitlement' => $values,
            'source_revision' => $entitlement->source_revision,
            'change_reason' => $entitlement->change_reason,
        ];
    }
}
