<?php

namespace Tests\Unit;

use App\Http\Controllers\AdminPlanController;
use App\Http\Controllers\AdminRegistryController;
use App\Models\Customer;
use App\Models\Instance;
use App\Models\OpsAuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\ProductPlan;
use Database\Seeders\ProductPlanSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpsPlanCatalogContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_minterp_plan_defaults_are_versioned_and_reseeding_preserves_changes(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);

        $core = ProductPlan::whereHas('product', fn ($q) => $q->where('code', 'MINTERP'))->where('code', 'CORE')->sole();
        $business = ProductPlan::whereHas('product', fn ($q) => $q->where('code', 'MINTERP'))->where('code', 'BUSINESS')->sole();
        $this->assertSame('2026.1', $core->version);
        $this->assertSame([
            'quota_users' => 5,
            'quota_branches' => 1,
            'quota_warehouses' => 2,
            'included_modules' => [],
        ], $core->entitlement_defaults);
        $this->assertSame([
            'quota_users' => 15,
            'quota_branches' => 3,
            'quota_warehouses' => 6,
            'included_modules' => ['crm', 'asset'],
        ], $business->entitlement_defaults);
        $this->assertSame('MINTERP', $business->product->code);

        ProductPlan::whereKey($core->id)->update(['name' => 'Locally adjusted Core']);
        $this->seed(ProductPlanSeeder::class);
        $this->assertSame(6, ProductPlan::count());
        $this->assertSame('Locally adjusted Core', ProductPlan::findOrFail($core->id)->name);
        foreach (['MINTPOS', 'MINTHRM'] as $code) {
            $product = Product::where('code', $code)->sole();
            foreach (['CORE' => [5, 1, 2], 'BUSINESS' => [15, 3, 6]] as $tier => [$users, $branches, $warehouses]) {
                $this->assertSame([
                    'quota_users' => $users, 'quota_branches' => $branches, 'quota_warehouses' => $warehouses,
                ], ProductPlan::where('product_id', $product->id)->where('code', $tier)->sole()->entitlement_defaults);
            }
        }
    }

    public function test_plan_version_fields_cannot_be_mutated(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $plan = ProductPlan::whereHas('product', fn ($q) => $q->where('code', 'MINTERP'))->where('code', 'CORE')->sole();
        $plan->name = 'Changed in place';

        $this->expectException(\LogicException::class);
        $plan->save();
    }

    public function test_plan_management_is_product_scoped_and_non_erp_products_use_instance_modes(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $pos = Product::where('code', 'MINTPOS')->sole();
        $erp = Product::where('code', 'MINTERP')->sole();
        $controller = app(AdminPlanController::class);

        $erpPage = $controller->index(Request::create('/admin/plans', 'GET'));
        $this->assertSame(2, $erpPage->getData()['plans']->total());
        $this->assertStringContainsString('Quota เริ่มต้น', $erpPage->with('errors', new ViewErrorBag)->render());

        $posPage = $controller->index(Request::create('/admin/plans?product=MINTPOS', 'GET'));
        $this->assertSame(2, $posPage->getData()['plans']->total());
        $html = $posPage->with('errors', new ViewErrorBag)->render();
        $this->assertStringContainsString('name="quota_users"', $html);
        $this->assertStringNotContainsString('name="included_modules[]"', $html);
        $this->assertStringContainsString('ผู้ใช้ 5 · สาขา 1 · คลัง 2', $html);
        $this->assertStringContainsString('/admin/instances?product=MINTPOS', $html);

        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        Instance::create(['customer_id' => $customer->id, 'product_id' => $pos->id, 'name' => 'POS', 'is_active' => true]);
        Instance::create(['customer_id' => $customer->id, 'product_id' => $erp->id, 'name' => 'ERP', 'is_active' => true]);
        $instances = app(AdminRegistryController::class)->instances(Request::create('/admin/instances?product=MINTPOS', 'GET'));
        $this->assertSame(1, $instances->getData()['instances']->total());
        $this->assertSame('MINTPOS', $instances->getData()['instances']->first()->product->code);

        try {
            $controller->store(Request::create('/admin/plans', 'POST', [
                'product_id' => $pos->id, 'code' => 'CORE', 'version' => '2026.2', 'name' => 'POS Core',
                'quota_users' => 6, 'quota_branches' => 1, 'quota_warehouses' => 2,
                'included_modules' => ['crm'],
            ]));
            $this->fail('MintPOS must not receive module add-ons');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('included_modules', $exception->errors());
        }
        $this->assertSame(6, ProductPlan::count());

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $request = Request::create('/admin/plans', 'POST', [
            'product_id' => $erp->id, 'code' => 'CORE', 'version' => '2026.2', 'name' => 'Core next',
            'quota_users' => 6, 'quota_branches' => 1, 'quota_warehouses' => 2,
        ]);
        $request->setUserResolver(fn () => $admin);
        $controller->store($request);
        $this->assertSame(7, ProductPlan::count());
        $this->assertSame($erp->id, ProductPlan::where('version', '2026.2')->sole()->product_id);
        $request = Request::create('/admin/plans', 'POST', [
            'product_id' => $pos->id, 'code' => 'CORE', 'version' => '2026.2', 'name' => 'POS Core',
            'quota_users' => 6, 'quota_branches' => 1, 'quota_warehouses' => 2,
        ]);
        $request->setUserResolver(fn () => $admin);
        $controller->store($request);
        $this->assertSame(8, ProductPlan::count());
        $this->assertSame(['quota_users' => 6, 'quota_branches' => 1, 'quota_warehouses' => 2], ProductPlan::where('product_id', $pos->id)->where('version', '2026.2')->sole()->entitlement_defaults);
        $this->assertSame(2, OpsAuditLog::where('action', 'plan.created')->count());
    }

    public function test_plan_version_is_unique_per_product_and_code(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $product = Product::where('code', 'MINTERP')->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);
        ProductPlan::create([
            'product_id' => $product->id,
            'code' => 'CORE',
            'version' => '2026.1',
            'name' => 'Duplicate',
            'entitlement_defaults' => [],
            'is_active' => true,
        ]);
    }
}
