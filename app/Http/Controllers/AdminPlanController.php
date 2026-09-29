<?php

namespace App\Http\Controllers;

use App\Models\OpsAuditLog;
use App\Models\Product;
use App\Models\ProductPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminPlanController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['product' => ['sometimes', 'string', Rule::exists('products', 'code')]]);
        $product = Product::query()->where('code', $data['product'] ?? 'MINTERP')->firstOrFail();

        return view('admin.plans.index', [
            'product' => $product,
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
            'plans' => ProductPlan::query()->where('product_id', $product->id)->orderByDesc('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)]]);
        $product = Product::query()->findOrFail($data['product_id']);
        if (! in_array($product->code, ['MINTERP', 'MINTPOS', 'MINTHRM'], true)) {
            throw ValidationException::withMessages(['product_id' => 'ยังไม่ได้กำหนดรูปแบบ quota ของผลิตภัณฑ์นี้']);
        }
        $isMintErp = $product->code === 'MINTERP';
        foreach (['code', 'version', 'name'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $request->merge([$field => $field === 'code' ? Str::upper(trim($value)) : trim($value)]);
            }
        }
        if ($isMintErp) {
            $request->merge(['included_modules' => $request->input('included_modules', [])]);
        }
        $version = $request->input('version');
        $version = is_string($version) ? trim($version) : '';

        $data = $request->validate([
            'code' => [
                'bail', 'required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/',
                Rule::unique('product_plans', 'code')->where(fn ($query) => $query
                    ->where('product_id', $product->id)
                    ->where('version', $version)),
            ],
            'version' => ['bail', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'quota_users' => ['required', 'integer', 'min:0', 'max:1000000'],
            'quota_branches' => ['required', 'integer', 'min:0', 'max:1000000'],
            'quota_warehouses' => ['required', 'integer', 'min:0', 'max:1000000'],
            'included_modules' => $isMintErp ? ['array', 'max:2'] : ['prohibited'],
            'included_modules.*' => $isMintErp ? ['string', 'in:crm,asset', 'distinct'] : ['prohibited'],
        ]);

        $modules = $isMintErp ? array_values(array_unique($data['included_modules'])) : [];
        sort($modules);
        DB::transaction(function () use ($request, $product, $data, $modules, $isMintErp): void {
            $plan = ProductPlan::create([
                'product_id' => $product->id,
                'code' => $data['code'],
                'version' => $data['version'],
                'name' => $data['name'],
                'entitlement_defaults' => [
                    'quota_users' => (int) $data['quota_users'],
                    'quota_branches' => (int) $data['quota_branches'],
                    'quota_warehouses' => (int) $data['quota_warehouses'],
                    ...($isMintErp ? ['included_modules' => $modules] : []),
                ],
                'is_active' => true,
            ]);
            $this->record($request, 'plan.created', $plan, [], [
                'product_code' => $product->code,
                'code' => $plan->code,
                'version' => $plan->version,
                'name' => $plan->name,
                'entitlement_defaults' => $plan->entitlement_defaults,
            ]);
        });

        return redirect()->route('admin.plans.index', ['product' => $product->code])->with('status', 'เพิ่ม Plan version แล้ว');
    }

    public function setActive(Request $request, ProductPlan $plan): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];
        $status = DB::transaction(function () use ($request, $plan, $active): string {
            $target = ProductPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($target->is_active === $active) {
                return $active ? 'Plan version ใช้งานอยู่แล้ว' : 'Plan version ถูกปิดอยู่แล้ว';
            }

            $old = ['is_active' => $target->is_active];
            $target->update(['is_active' => $active]);
            $this->record($request, $active ? 'plan.activated' : 'plan.deactivated', $target, $old, ['is_active' => $active]);

            return $active ? 'เปิดใช้ Plan version แล้ว' : 'ปิดใช้ Plan version แล้ว';
        });

        return back()->with('status', $status);
    }

    private function record(Request $request, string $action, ProductPlan $plan, array $old, array $new): void
    {
        OpsAuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $plan->getMorphClass(),
            'subject_id' => (string) $plan->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
