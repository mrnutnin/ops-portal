<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Instance;
use App\Models\OpsAuditLog;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminRegistryController extends Controller
{
    public function customers(): View
    {
        return view('admin.customers.index', [
            'customers' => Customer::query()->withCount('instances')->orderBy('name')->paginate(20),
        ]);
    }

    public function storeCustomer(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        DB::transaction(function () use ($request, $data): void {
            $customer = Customer::create(['name' => trim($data['name']), 'is_active' => true]);
            $this->record($request, 'customer.created', $customer, [], ['name' => $customer->name, 'is_active' => true]);
        });

        return back()->with('status', 'เพิ่มลูกค้าแล้ว');
    }

    public function updateCustomer(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        return $this->updateName($request, $customer, 'customer', trim($data['name']));
    }

    public function products(): View
    {
        return view('admin.products.index', [
            'products' => Product::query()->withCount('instances')->orderBy('code')->paginate(20),
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $code = $request->input('code');
        if (is_string($code)) {
            $request->merge(['code' => Str::upper(trim($code))]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/', 'unique:products,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $product = Product::create(['code' => $data['code'], 'name' => trim($data['name']), 'is_active' => true]);
            $this->record($request, 'product.created', $product, [], ['code' => $product->code, 'name' => $product->name, 'is_active' => true]);
        });

        return back()->with('status', 'เพิ่มผลิตภัณฑ์แล้ว');
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        return $this->updateName($request, $product, 'product', trim($data['name']));
    }

    public function instances(Request $request): View
    {
        $data = $request->validate(['product' => ['sometimes', 'string', Rule::exists('products', 'code')]]);
        $selectedProduct = isset($data['product']) ? Product::query()->where('code', $data['product'])->firstOrFail() : null;
        // ponytail: load active choices for the small Ops registry; switch to search-as-you-type if the catalog grows large.
        return view('admin.instances.index', [
            'instances' => Instance::query()->with(['customer:id,name', 'product:id,code,name'])
                ->when($selectedProduct, fn ($query) => $query->where('product_id', $selectedProduct->id))
                ->orderByDesc('id')->paginate(20)->withQueryString(),
            'selectedProduct' => $selectedProduct,
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeInstance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
        ], [
            'customer_id.exists' => 'เลือกลูกค้าที่เปิดใช้งานอยู่',
            'product_id.exists' => 'เลือกผลิตภัณฑ์ที่เปิดใช้งานอยู่',
        ]);

        DB::transaction(function () use ($request, $data): void {
            $instance = Instance::create([
                'name' => trim($data['name']),
                'customer_id' => $data['customer_id'],
                'product_id' => $data['product_id'],
                'is_active' => true,
            ]);
            $this->record($request, 'instance.created', $instance, [], [
                'name' => $instance->name,
                'customer_id' => $instance->customer_id,
                'product_code' => $instance->product->code,
                'instance_ref' => $instance->instance_ref,
                'is_active' => true,
            ]);
        });

        return back()->with('status', 'เพิ่ม Instance แล้ว');
    }

    public function updateInstance(Request $request, Instance $instance): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        return $this->updateName($request, $instance, 'instance', trim($data['name']));
    }

    public function setCustomerActive(Request $request, Customer $customer): RedirectResponse
    {
        return $this->setActive($request, $customer, 'customer');
    }

    public function setProductActive(Request $request, Product $product): RedirectResponse
    {
        return $this->setActive($request, $product, 'product');
    }

    public function setInstanceActive(Request $request, Instance $instance): RedirectResponse
    {
        return $this->setActive($request, $instance, 'instance');
    }

    private function updateName(Request $request, Model $record, string $type, string $name): RedirectResponse
    {
        $status = DB::transaction(function () use ($request, $record, $type, $name): string {
            $target = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($target->name === $name) {
                return 'ไม่มีการเปลี่ยนแปลง';
            }

            $old = ['name' => $target->name];
            $target->update(['name' => $name]);
            $this->record($request, $type.'.updated', $target, $old, ['name' => $name]);

            return 'บันทึกการแก้ไขแล้ว';
        });

        return back()->with('status', $status);
    }

    private function setActive(Request $request, Model $record, string $type): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];

        $status = DB::transaction(function () use ($request, $record, $type, $active): string {
            $target = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($active === $target->is_active) {
                return $active ? 'รายการใช้งานอยู่แล้ว' : 'รายการถูกระงับอยู่แล้ว';
            }

            $old = ['is_active' => $target->is_active];
            $target->update(['is_active' => $active]);
            $this->record($request, $type.'.'.($active ? 'activated' : 'deactivated'), $target, $old, ['is_active' => $active]);

            return $active ? 'เปิดใช้งานแล้ว' : 'ระงับแล้ว';
        });

        return back()->with('status', $status);
    }

    private function record(Request $request, string $action, Model $subject, array $old, array $new): void
    {
        OpsAuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
