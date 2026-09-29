<?php

namespace Tests\Unit;

use App\Http\Controllers\AdminInstanceEntitlementController;
use App\Http\Controllers\AdminTrialController;
use App\Models\Customer;
use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\OpsAuditLog;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use App\Services\TrialIssuanceService;
use Carbon\Carbon;
use Database\Seeders\ProductPlanSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class OpsTrialIssuanceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_is_once_per_customer_and_product_on_one_instance_with_audited_exception(): void
    {
        Date::setTestNow('2026-10-01 12:00:00 UTC');
        try {
            $this->seed(ProductSeeder::class);
            $this->seed(ProductPlanSeeder::class);
            $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
            $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
            $product = Product::where('code', 'MINTERP')->sole();
            $plan = ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole();
            $instances = collect([1, 2, 3])->map(fn ($n) => Instance::create([
                'customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP '.$n, 'is_active' => true,
            ]));
            $service = app(TrialIssuanceService::class);
            $form = app(AdminInstanceEntitlementController::class)->edit($instances[0])->with('errors', new ViewErrorBag)->render();
            $this->assertStringNotContainsString('name="ready_to_handover"', $form);
            $this->assertStringNotContainsString('name="production_ready_confirmed"', $form);
            $request = Request::create('/admin/instances/'.$instances[0]->id.'/trial', 'POST', [
                'product_plan_id' => $plan->id, 'production_addon' => '1', 'reason' => 'Customer handover approved',
            ]);
            $request->setUserResolver(fn () => $admin);
            app(AdminTrialController::class)->store($request, $instances[0], $service);
            $trial = InstanceEntitlement::where('instance_id', $instances[0]->id)->sole();
            $this->assertSame('TRIAL', $trial->status);
            $this->assertSame('SUBSCRIPTION', $trial->commercial_mode);
            $this->assertTrue($trial->production_addon);
            $this->assertSame('BUSINESS', $trial->productPlan->code);
            $this->assertSame($trial->starts_at->copy()->addDays(30)->timestamp, $trial->expires_at->timestamp);
            $this->assertSame(1, $trial->source_revision);
            $this->assertSame(15, $trial->effectiveValues()['quota_users']);
            $this->assertSame('Customer handover approved', OpsAuditLog::where('action', 'trial.issued')->sole()->new_values['reason']);

            try {
                $service->issue($instances[1], $plan->id, $admin, 'Second trial requested', false, false);
                $this->fail('A second ordinary Trial should be rejected');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('exception_approved', $exception->errors());
            }
            $this->assertSame(0, InstanceEntitlement::where('instance_id', $instances[1]->id)->count());

            $service->issue($instances[1], $plan->id, $admin, 'Approved by Admin as an exception', true, true);
            $this->assertSame(2, DB::table('trial_issuances')->count());
            $this->assertSame(1, DB::table('trial_issuances')->whereNotNull('standard_claim')->count());
            $this->assertSame(1, OpsAuditLog::where('action', 'trial.exception_issued')->count());
            $this->assertTrue(InstanceEntitlement::where('instance_id', $instances[1]->id)->sole()->production_addon);

            foreach ([$instances[0], $instances[1]] as $issued) {
                try {
                    $service->issue($issued, $plan->id, $admin, 'Do not extend this trial', true, false);
                    $this->fail('The same instance cannot receive another Trial');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('instance', $exception->errors());
                }
            }
            $this->assertSame(2, DB::table('trial_issuances')->count());
            $this->assertSame(2, InstanceEntitlement::count());
            $page = app(AdminInstanceEntitlementController::class)->edit($instances[0])->with('errors', new ViewErrorBag)->render();
            $this->assertStringContainsString('Approved by Admin as an exception', $page);
            $this->assertStringContainsString('แปลง Trial เป็นแพ็กเกจชำระเงิน', $page);
            $this->assertStringNotContainsString('name="production_ready_confirmed"', $page);
            $this->assertStringNotContainsString('name="paid_contract_confirmed"', $page);

            $request = Request::create('/admin/instances/'.$instances[0]->id.'/entitlement', 'PUT', [
                'commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE', 'product_plan_id' => $plan->id,
                'starts_at' => '2026-10-01T12:00', 'expires_at' => '2027-10-01T12:00',
                'change_reason' => 'Attempt to convert without approval',
            ]);
            $request->setUserResolver(fn () => $admin);
            try {
                app(AdminInstanceEntitlementController::class)->save($request, $instances[0]);
                $this->fail('The generic entitlement form must not convert or extend a Trial');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('commercial_mode', $exception->errors());
            }
            $this->assertSame('TRIAL', $trial->fresh()->status);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_production_opt_in_keeps_trial_expiry_and_paid_conversion_requires_admin_reason(): void
    {
        Date::setTestNow('2026-10-01 12:00:00 UTC');
        try {
            $this->seed(ProductSeeder::class);
            $this->seed(ProductPlanSeeder::class);
            $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
            $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
            $product = Product::where('code', 'MINTERP')->sole();
            $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
            $business = ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole();
            $core = ProductPlan::where('product_id', $product->id)->where('code', 'CORE')->sole();
            $service = app(TrialIssuanceService::class);
            $service->issue($instance, $business->id, $admin, 'Handover approved by Admin', false, false);
            $trial = InstanceEntitlement::where('instance_id', $instance->id)->sole();
            $originalEnd = $trial->expires_at->timestamp;

            $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'secure-password-123', 'is_admin' => false, 'is_active' => true]);
            try {
                $service->convertToPaid($instance, $core->id, Carbon::parse('2027-10-01', 'UTC'), $staff, 'Unauthorized conversion');
                $this->fail('A non-admin must not change Trial entitlements');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
            try {
                $service->enableProduction($instance, $admin, ' ');
                $this->fail('Production needs an audited reason');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
            try {
                $service->convertToPaid($instance, $core->id, Carbon::parse('2027-10-01', 'UTC'), $admin, ' ');
                $this->fail('Conversion needs an audited reason');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
            $productionRequest = Request::create('/admin/instances/'.$instance->id.'/trial/production', 'POST', [
                'reason' => 'Production readiness confirmed',
            ]);
            $productionRequest->setUserResolver(fn () => $admin);
            app(AdminTrialController::class)->enableProduction($productionRequest, $instance, $service);
            $this->assertSame(2, $trial->fresh()->source_revision);
            $this->assertSame($originalEnd, $trial->fresh()->expires_at->timestamp);
            $this->assertTrue($trial->fresh()->production_addon);
            $this->assertSame('Production readiness confirmed', OpsAuditLog::where('action', 'trial.production_enabled')->sole()->new_values['reason']);
            try {
                $service->enableProduction($instance, $admin, 'Repeat Production request');
                $this->fail('Production should not be enabled twice');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('production_addon', $exception->errors());
            }
            try {
                $service->convertToPaid($instance, $core->id, Carbon::parse('2026-09-01', 'UTC'), $admin, 'Invalid paid period');
                $this->fail('Expired paid period is invalid');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('paid_expires_at', $exception->errors());
            }
            $request = Request::create('/admin/instances/'.$instance->id.'/trial/convert', 'POST', [
                'product_plan_id' => $core->id, 'paid_expires_at' => '2027-10-01T07:00',
                'reason' => 'Contract and period approved',
            ]);
            $request->setUserResolver(fn () => $admin);
            app(AdminTrialController::class)->convertToPaid($request, $instance, $service);
            $paid = $trial->fresh();
            $this->assertSame('ACTIVE', $paid->status);
            $this->assertSame('2027-10-01 00:00:00', $paid->expires_at->utc()->format('Y-m-d H:i:s'));
            $this->assertSame(3, $paid->source_revision);
            $this->assertSame('CORE', $paid->productPlan->code);
            $this->assertSame(5, $paid->effectiveValues()['quota_users']);
            $this->assertTrue($paid->production_addon);
            $this->assertSame('Contract and period approved', OpsAuditLog::where('action', 'trial.converted_to_paid')->sole()->new_values['reason']);
            $this->assertSame(1, DB::table('trial_issuances')->count());
            $this->assertSame($originalEnd, Carbon::parse(DB::table('trial_issuances')->value('expires_at'), 'UTC')->timestamp);
            $paidPage = app(AdminInstanceEntitlementController::class)->edit($instance)->with('errors', new ViewErrorBag)->render();
            $this->assertStringContainsString('กำหนดหรือแก้ไขสิทธิ์', $paidPage);
            $this->assertStringContainsString('value="2027-10-01T07:00"', $paidPage);
            try {
                $service->convertToPaid($instance, $core->id, Carbon::parse('2028-10-01', 'UTC'), $admin, 'Repeat paid conversion');
                $this->fail('Conversion cannot run twice');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('instance', $exception->errors());
            }
        } finally {
            Date::setTestNow();
        }
    }

    public function test_expired_trial_cannot_gain_production_but_requires_paid_contract_to_resume(): void
    {
        Date::setTestNow('2026-10-01 12:00:00 UTC');
        try {
            $this->seed(ProductSeeder::class);
            $this->seed(ProductPlanSeeder::class);
            $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
            $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
            $product = Product::where('code', 'MINTERP')->sole();
            $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
            $plan = ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole();
            $service = app(TrialIssuanceService::class);
            $service->issue($instance, $plan->id, $admin, 'Handover approved by Admin', false, false);
            Date::setTestNow('2026-11-01 12:00:00 UTC');
            try {
                $service->enableProduction($instance, $admin, 'Production after expiry');
                $this->fail('Production cannot be enabled after expiry');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('production_addon', $exception->errors());
            }
            $this->assertSame(1, $instance->entitlement->source_revision);
            $service->convertToPaid($instance, $plan->id, Carbon::parse('2027-11-01', 'UTC'), $admin, 'New paid period approved');
            $this->assertSame('ACTIVE', $instance->entitlement->fresh()->status);
            $this->assertSame(2, $instance->entitlement->fresh()->source_revision);
            $this->assertSame(1, DB::table('trial_issuances')->count());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_trial_requires_active_minterp_business_plan_and_admin_reason(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
        $service = app(TrialIssuanceService::class);
        $plan = ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole();

        try {
            $service->issue($instance, $plan->id, $admin, ' ', false, true);
            $this->fail('An audited reason is still required');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        try {
            $service->issue($instance, ProductPlan::where('product_id', $product->id)->where('code', 'CORE')->sole()->id, $admin, 'Wrong plan requested', false, false);
            $this->fail('Trial must use Business');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_plan_id', $exception->errors());
        }
        $pos = Product::where('code', 'MINTPOS')->sole();
        $posInstance = Instance::create(['customer_id' => $customer->id, 'product_id' => $pos->id, 'name' => 'POS', 'is_active' => true]);
        try {
            $service->issue($posInstance, $plan->id, $admin, 'MintPOS trial is not defined', false, false);
            $this->fail('MintERP Trial must not be issued to another product');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('instance', $exception->errors());
        }
        InstanceEntitlement::create(['instance_id' => $instance->id, 'commercial_mode' => 'LICENSE', 'status' => 'ACTIVE']);
        try {
            $service->issue($instance, $plan->id, $admin, 'Existing License cannot be replaced', false, false);
            $this->fail('Existing License must not be overwritten');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('instance', $exception->errors());
        }
        $this->assertSame(0, DB::table('trial_issuances')->count());
        $this->assertSame('LICENSE', $instance->entitlement->commercial_mode);
    }
}
