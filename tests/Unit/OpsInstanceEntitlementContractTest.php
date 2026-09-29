<?php

namespace Tests\Unit;

use App\Http\Controllers\AdminInstanceEntitlementController;
use App\Models\OpsAuditLog;
use App\Models\User;
use App\Models\Customer;
use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\Product;
use App\Models\ProductPlan;
use Database\Seeders\ProductPlanSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpsInstanceEntitlementContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_entitlement_keeps_effective_per_instance_values_and_revision(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $customer = Customer::create(['name' => 'Example Co', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Production',
            'is_active' => true,
        ]);
        $plan = ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole();

        $entitlement = InstanceEntitlement::create([
            'instance_id' => $instance->id,
            'product_plan_id' => $plan->id,
            'commercial_mode' => 'SUBSCRIPTION',
            'status' => 'ACTIVE',
            'starts_at' => '2026-10-01 00:00:00',
            'expires_at' => '2027-10-01 00:00:00',
            'overrides' => ['quota_users' => 20],
            'production_addon' => true,
            'cancel_at_period_end' => false,
            'source_revision' => 3,
        ]);

        $this->assertSame($instance->id, $entitlement->instance->id);
        $this->assertSame([
            'quota_users' => 20,
            'quota_branches' => 3,
            'quota_warehouses' => 6,
            'included_modules' => ['crm', 'asset'],
        ], $entitlement->effectiveValues());
        $this->assertSame(3, $entitlement->source_revision);
        $this->assertSame(1, InstanceEntitlement::where('instance_id', $instance->id)->count());
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $entitlement->update(['production_addon' => false]);
        $controller = app(AdminInstanceEntitlementController::class);
        $page = $controller->edit($instance)->with('errors', new ViewErrorBag)->render();
        $this->assertStringNotContainsString('name="production_ready_confirmed"', $page);
        $this->assertStringContainsString('value="2026-10-01T07:00"', $page);
        $this->assertStringContainsString('เริ่มใช้ (Subscription · เวลาไทย)', $page);
        $this->assertStringContainsString('ผู้ใช้ 20', $page);
        $this->assertStringContainsString('สาขา 3', $page);
        $this->assertStringContainsString('คลัง 6', $page);
        $request = Request::create('/admin/instances/'.$instance->id.'/entitlement', 'PUT', [
            'commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE', 'product_plan_id' => $plan->id,
            'starts_at' => '2026-10-01T07:00', 'expires_at' => '2027-10-01T07:00',
            'quota_overrides' => ['quota_users' => 20], 'production_addon' => '1',
            'change_reason' => 'Approved Production addon',
        ]);
        $request->setUserResolver(fn () => $admin);
        $controller->save($request, $instance);
        $this->assertTrue($entitlement->fresh()->production_addon);
        $this->assertSame(4, $entitlement->fresh()->source_revision);
        $this->assertSame('2026-10-01 00:00', $entitlement->fresh()->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertArrayNotHasKey('production_readiness_confirmed', OpsAuditLog::query()->latest('id')->firstOrFail()->new_values);

        $request = Request::create('/admin/instances/'.$instance->id.'/entitlement', 'PUT', [
            'commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE', 'product_plan_id' => $plan->id,
            'starts_at' => '2026-09-28T13:36', 'expires_at' => '2026-10-28T13:36',
            'quota_overrides' => ['quota_users' => 20], 'production_addon' => '1',
            'change_reason' => 'Thai time subscription correction',
        ]);
        $request->setUserResolver(fn () => $admin);
        $controller->save($request, $instance);
        $this->assertSame('2026-09-28 06:36', $entitlement->fresh()->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-28 06:36', $entitlement->fresh()->expires_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(5, $entitlement->fresh()->source_revision);
        $page = $controller->edit($instance)->with('errors', new ViewErrorBag)->render();
        $this->assertStringContainsString('value="2026-09-28T13:36"', $page);
    }

    public function test_pos_and_hrm_subscription_quotas_are_product_scoped_and_license_bypasses_plans(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $controller = app(AdminInstanceEntitlementController::class);
        $erpPlan = ProductPlan::where('product_id', Product::where('code', 'MINTERP')->sole()->id)->where('code', 'CORE')->sole();

        foreach (['MINTPOS', 'MINTHRM'] as $code) {
            $product = Product::where('code', $code)->sole();
            $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => $code, 'is_active' => true]);
            $plan = ProductPlan::where('product_id', $product->id)->where('code', 'CORE')->sole();
            $page = $controller->edit($instance)->with('errors', new ViewErrorBag)->render();
            $this->assertStringContainsString('name="quota_overrides[quota_users]"', $page);
            $this->assertStringNotContainsString('name="production_addon"', $page);

            $input = [
                'commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE', 'product_plan_id' => $plan->id,
                'starts_at' => '2026-10-01T00:00', 'expires_at' => '2027-10-01T00:00',
                'quota_overrides' => ['quota_users' => '9', 'quota_branches' => '', 'quota_warehouses' => ''], 'change_reason' => 'Contract approved by Admin',
            ];
            $save = function (array $data) use ($controller, $instance, $admin): void {
                $request = Request::create('/admin/instances/'.$instance->id.'/entitlement', 'PUT', $data);
                $request->setUserResolver(fn () => $admin);
                $controller->save($request, $instance);
            };
            foreach ([
                ['product_plan_id' => $erpPlan->id, 'error' => 'product_plan_id'],
                ['included_modules' => ['crm'], 'error' => 'included_modules'],
                ['production_addon' => '1', 'error' => 'production_addon'],
                ['quota_overrides' => ['quota_unknown' => 100], 'error' => 'quota_overrides'],
            ] as $invalid) {
                $field = $invalid['error'];
                unset($invalid['error']);
                try {
                    $save(array_replace($input, $invalid));
                    $this->fail("Invalid $field must be rejected for $code");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey($field, $exception->errors());
                }
            }
            $this->assertNull($instance->fresh()->entitlement);
            $save($input);
            $entitlement = $instance->fresh()->entitlement;
            $this->assertSame($plan->id, $entitlement->product_plan_id);
            $this->assertSame(['quota_users' => 9, 'quota_branches' => 1, 'quota_warehouses' => 2], $entitlement->effectiveValues());
            $this->assertSame(1, $entitlement->source_revision);
            $this->assertSame($code, OpsAuditLog::where('subject_id', (string) $entitlement->id)->latest('id')->firstOrFail()->new_values['product_code']);
            $save(['commercial_mode' => 'LICENSE', 'status' => 'ACTIVE', 'change_reason' => 'Converted to License by Admin']);
            $this->assertNull($entitlement->fresh()->product_plan_id);
            $this->assertSame([], $entitlement->fresh()->effectiveValues());
        }
    }

    public function test_only_one_current_entitlement_can_be_assigned_to_an_instance(): void
    {
        $this->seed(ProductSeeder::class);
        $customer = Customer::create(['name' => 'Example Co', 'is_active' => true]);
        $product = Product::where('code', 'MINTPOS')->sole();
        $instance = Instance::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'POS Production',
            'is_active' => true,
        ]);
        InstanceEntitlement::create([
            'instance_id' => $instance->id,
            'commercial_mode' => 'SUBSCRIPTION',
            'status' => 'ACTIVE',
            'source_revision' => 1,
        ]);

        $this->expectException(QueryException::class);
        InstanceEntitlement::create([
            'instance_id' => $instance->id,
            'commercial_mode' => 'LICENSE',
            'status' => 'ACTIVE',
            'source_revision' => 1,
        ]);
    }
}
