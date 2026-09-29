<?php

namespace Tests\Unit;

use App\Http\Controllers\AdminInstanceEntitlementController;
use App\Models\Customer;
use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\InstanceSyncCredential;
use App\Models\OpsAuditLog;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use App\Services\InstanceEntitlementDelivery;
use Database\Seeders\ProductPlanSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class InstanceEntitlementDeliveryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_keeps_secret_encrypted_and_pushes_signed_license_and_subscription(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        config(['ops.entitlement_allowed_hosts' => ['erp.example.test']]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
        $entitlement = InstanceEntitlement::create(['instance_id' => $instance->id, 'commercial_mode' => 'LICENSE', 'status' => 'SUSPENDED', 'source_revision' => 1]);
        $service = new InstanceEntitlementDelivery(fn ($host) => ['8.8.8.8']);
        $secret = str_repeat('ab', 32);
        try {
            $service->enroll($instance, 'https://127.0.0.1', 'key-1', $secret, $admin);
            self::fail('Must reject arbitrary/internal destinations');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('target_url', $exception->errors());
        }
        $service->enroll($instance, 'https://erp.example.test', 'key-1', $secret, $admin);
        self::assertNotSame($secret, DB::table('instance_sync_credentials')->value('secret'));
        self::assertSame($secret, InstanceSyncCredential::query()->sole()->secret);
        $page = app(AdminInstanceEntitlementController::class)->edit($instance)->with('errors', new ViewErrorBag)->render();
        self::assertStringContainsString('https://erp.example.test/api/v1/entitlements/current', $page);
        self::assertStringContainsString('ปลายทาง API ปัจจุบัน', $page);
        self::assertStringContainsString('<summary>เปลี่ยนปลายทาง API (ไม่ส่งสิทธิ์อัตโนมัติ)</summary>', $page);
        self::assertStringContainsString('กรอกเวลาไทย (UTC+7)', $page);
        self::assertStringContainsString('เริ่มใช้ (Subscription · เวลาไทย)', $page);
        self::assertStringContainsString('name="target_url"', $page);
        self::assertStringContainsString('name="target_change_reason"', $page);
        self::assertStringNotContainsString($secret, $page);
        try {
            $service->enroll($instance, 'https://erp.example.test', 'key-1', $secret, $admin);
            self::fail('Re-enrollment must not replace a key');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('instance', $exception->errors());
        }
        $responseStatus = 200;
        Http::fake(['https://erp.example.test/*' => function ($request) use (&$responseStatus) {
            if ($responseStatus === 'connection') {
                throw new ConnectionException('Private endpoint details must not enter the audit');
            }
            if ($responseStatus === 401 && $request->header('X-Ops-Key-Id')[0] === 'key-1') {
                return Http::response(['status' => 'unchanged', 'source_revision' => json_decode($request->body(), true)['source_revision']], 200);
            }
            return Http::response([
                'status' => 'applied', 'source_revision' => json_decode($request->body(), true)['source_revision'],
            ], $responseStatus);
        }]);
        self::assertSame(['status' => 'applied', 'source_revision' => 1], $service->push($instance, $admin));
        Http::assertSent(function ($request) use ($instance, $secret): bool {
            $body = json_decode($request->body(), true);
            $timestamp = $request->header('X-Ops-Timestamp')[0];
            $message = "v1\nkey-1\n{$timestamp}\nPUT\n/api/v1/entitlements/current\n".hash('sha256', $request->body());
            return $request->method() === 'PUT' && $request->url() === 'https://erp.example.test/api/v1/entitlements/current'
                && $body['instance_ref'] === $instance->instance_ref
                && $body['entitlement']['commercial_mode'] === 'LICENSE'
                && $body['entitlement']['status'] === 'SUSPENDED'
                && $body['entitlement']['quota_users'] === null
                && hash_equals(hash_hmac('sha256', $message, hex2bin($secret)), $request->header('X-Ops-Signature')[0]);
        });
        $plan = ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole();
        $entitlement->update([
            'commercial_mode' => 'SUBSCRIPTION', 'product_plan_id' => $plan->id, 'status' => 'ACTIVE',
            'starts_at' => '2026-10-01 00:00:00', 'expires_at' => '2027-10-01 00:00:00',
            'overrides' => ['quota_users' => 20], 'source_revision' => 2,
        ]);
        self::assertSame('applied', $service->push($instance->fresh(), $admin)['status']);
        Http::assertSent(function ($request): bool {
            $snapshot = json_decode($request->body(), true)['entitlement'];
            return $snapshot['quota_users'] === 20
                && $snapshot['starts_at'] === '2026-10-01T00:00:00Z'
                && $snapshot['expires_at'] === '2027-10-01T00:00:00Z';
        });
        self::assertSame(2, OpsAuditLog::where('action', 'instance.sync.delivered')->count());
        self::assertStringNotContainsString($secret, json_encode(OpsAuditLog::query()->get()->toArray()));
        self::assertSame(3, $service->bumpForReconciliation($instance, $admin, 'Reviewed emergency override'));
        self::assertSame(3, InstanceEntitlement::query()->sole()->source_revision);
        $service->rotate($instance, 'key-2', str_repeat('cd', 32), $admin);
        self::assertSame('key-1', InstanceSyncCredential::query()->sole()->previous_key_id);
        self::assertNotSame($secret, DB::table('instance_sync_credentials')->value('previous_secret'));
        try {
            $service->retirePreviousKey($instance, $admin);
            self::fail('Must confirm delivery signed with new key before retirement');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('key', $exception->errors());
        }
        $responseStatus = 401;
        $service->push($instance->fresh(), $admin);
        self::assertSame('key-1', OpsAuditLog::query()->where('action', 'instance.sync.delivered')->latest('id')->first()->new_values['key_id']);
        try {
            $service->retirePreviousKey($instance, $admin);
            self::fail('Old-key fallback is not proof of new-key activation');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('key', $exception->errors());
        }
        $responseStatus = 200;
        $service->push($instance->fresh(), $admin);
        self::assertSame('key-2', OpsAuditLog::query()->where('action', 'instance.sync.delivered')->latest('id')->first()->new_values['key_id']);
        $service->retirePreviousKey($instance, $admin);
        self::assertNull(InstanceSyncCredential::query()->sole()->previous_secret);
        self::assertStringNotContainsString(str_repeat('cd', 32), json_encode(OpsAuditLog::query()->get()->toArray()));
        $responseStatus = 302;
        try {
            $service->push($instance->fresh(), $admin);
            self::fail('Redirects are not delivery confirmations');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('delivery', $exception->errors());
        }
        self::assertSame(302, OpsAuditLog::query()->where('action', 'instance.sync.delivery_failed')->latest('id')->first()->new_values['http_status']);
        $responseStatus = 'connection';
        try {
            $service->push($instance->fresh(), $admin);
            self::fail('Connection failures must be reported safely');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('delivery', $exception->errors());
        }
        self::assertSame('Connection failed', OpsAuditLog::query()->where('action', 'instance.sync.delivery_failed')->latest('id')->first()->new_values['reason']);
        self::assertStringNotContainsString('Private endpoint details', json_encode(OpsAuditLog::query()->get()->toArray()));
        try {
            (new InstanceEntitlementDelivery(fn ($host) => ['8.8.8.8', '10.0.0.1']))->push($instance->fresh(), $admin);
            self::fail('A private DNS resolution must never send HTTP');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('delivery', $exception->errors());
        }
        config(['ops.entitlement_allowed_hosts' => []]);
        try {
            $service->push($instance->fresh(), $admin);
            self::fail('Delivery must stop when the destination is removed from the allowlist');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('delivery', $exception->errors());
        }
    }

    public function test_loopback_http_delivery_is_pinned_and_only_allowed_in_local_mode(): void
    {
        $this->seed(ProductSeeder::class);
        $originalEnvironment = app()->environment();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
        InstanceEntitlement::create(['instance_id' => $instance->id, 'commercial_mode' => 'LICENSE', 'status' => 'ACTIVE', 'source_revision' => 1]);
        $delivery = new InstanceEntitlementDelivery(fn () => self::fail('Loopback must not use DNS'));
        try {
            app()->detectEnvironment(fn () => 'local');
            foreach (['http://127.0.0.2:8000', 'http://10.0.0.1:8000', 'http://localhost:8000/path',
                'http://user@localhost:8000', 'http://localhost:8000?x=1', 'http://localhost:8000#fragment',
                'http://localhost', 'http://example.test:8000'] as $url) {
                try {
                    $delivery->enroll($instance, $url, 'key-1', str_repeat('ab', 32), $admin);
                    self::fail('Unapproved destination must not be saved: '.$url);
                } catch (ValidationException $exception) {
                    self::assertArrayHasKey('target_url', $exception->errors());
                }
            }
            $delivery->enroll($instance, 'http://localhost:8000', 'key-1', str_repeat('ab', 32), $admin);
            Http::fake(['http://localhost:8000/*' => Http::response(['status' => 'applied', 'source_revision' => 1])]);
            self::assertSame('applied', $delivery->push($instance, $admin)['status']);
            Http::assertSent(fn ($request) => $request->url() === 'http://localhost:8000/api/v1/entitlements/current');
            self::assertTrue($delivery->changeTarget($instance, 'http://localhost:8000', 'http://127.0.0.1:8000', 'Local ERP instance', $admin));
            Http::fake(['http://127.0.0.1:8000/*' => Http::response(['status' => 'unchanged', 'source_revision' => 1])]);
            self::assertSame('unchanged', $delivery->push($instance, $admin)['status']);
            $sent = Http::recorded()->count();
            app()->detectEnvironment(fn () => 'production');
            try {
                $delivery->push($instance, $admin);
                self::fail('Production must reject saved loopback HTTP destinations');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('delivery', $exception->errors());
            }
            try {
                $delivery->changeTarget($instance, 'http://127.0.0.1:8000', 'http://localhost:8000', 'Local ERP instance', $admin);
                self::fail('Production must reject loopback destination changes');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('target_url', $exception->errors());
            }
            self::assertSame($sent, Http::recorded()->count());
        } finally {
            app()->detectEnvironment(fn () => $originalEnvironment);
        }
    }

    public function test_local_admin_can_enroll_and_push_from_web_but_production_cannot(): void
    {
        $this->seed(ProductSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
        InstanceEntitlement::create(['instance_id' => $instance->id, 'commercial_mode' => 'LICENSE', 'status' => 'ACTIVE', 'source_revision' => 1]);
        $original = app()->environment();
        $secret = str_repeat('ab', 32);
        $request = Request::create('http://127.0.0.1:8001/admin/instances/'.$instance->id.'/local-enrollment', 'POST', [
            'target_url' => 'http://127.0.0.1:8000', 'key_id' => 'uat-local-1', 'secret' => $secret,
        ]);
        $request->setUserResolver(fn () => $admin);
        $controller = app(AdminInstanceEntitlementController::class);
        try {
            app()->detectEnvironment(fn () => 'production');
            try {
                $controller->enrollLocal($request, $instance, app(InstanceEntitlementDelivery::class));
                self::fail('Production cannot enroll from the web');
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                self::assertSame(0, InstanceSyncCredential::query()->count());
            }
            app()->detectEnvironment(fn () => 'local');
            $controller->enrollLocal($request, $instance, app(InstanceEntitlementDelivery::class));
            self::assertSame('http://127.0.0.1:8000', InstanceSyncCredential::query()->sole()->target_url);
            self::assertNotSame($secret, DB::table('instance_sync_credentials')->value('secret'));
            Http::fake(['http://127.0.0.1:8000/*' => Http::response(['status' => 'applied', 'source_revision' => 1])]);
            $controller->pushLocal($request, $instance, app(InstanceEntitlementDelivery::class));
            Http::assertSent(fn ($sent) => $sent->url() === 'http://127.0.0.1:8000/api/v1/entitlements/current');
            self::assertSame(1, OpsAuditLog::query()->where('action', 'instance.sync.delivered')->count());
            self::assertStringNotContainsString($secret, json_encode(OpsAuditLog::query()->get()->toArray()));
            self::assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.instances.local-enrollment'));
        } finally {
            app()->detectEnvironment(fn () => $original);
        }
    }

    public function test_admin_can_enroll_and_push_over_https_without_local_only_routes(): void
    {
        $this->seed(ProductSeeder::class);
        config(['ops.entitlement_allowed_hosts' => ['erp.example.test']]);
        $original = app()->environment();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
        InstanceEntitlement::create(['instance_id' => $instance->id, 'commercial_mode' => 'LICENSE', 'status' => 'ACTIVE', 'source_revision' => 1]);
        $secret = str_repeat('ab', 32);
        $request = Request::create('https://ops.example.test/admin/instances/'.$instance->id.'/enrollment', 'POST', [
            'target_url' => 'https://erp.example.test', 'key_id' => 'key-1', 'secret' => $secret,
            'reason' => 'Approved ERP onboarding',
        ]);
        $request->setUserResolver(fn () => $admin);
        $controller = app(AdminInstanceEntitlementController::class);
        $delivery = new InstanceEntitlementDelivery(fn () => ['8.8.8.8']);
        try {
            app()->detectEnvironment(fn () => 'production');
            $controller->enrollWeb($request, $instance, $delivery);
            self::assertNotSame($secret, DB::table('instance_sync_credentials')->value('secret'));
            Http::fake(['https://erp.example.test/*' => Http::response(['status' => 'applied', 'source_revision' => 1])]);
            $controller->pushWeb($request, $instance, $delivery);
            self::assertSame('Approved ERP onboarding', OpsAuditLog::where('action', 'instance.sync.enrolled')->sole()->new_values['change_reason']);
            self::assertSame('Approved ERP onboarding', OpsAuditLog::where('action', 'instance.sync.delivered')->sole()->new_values['change_reason']);
            self::assertSame(1, OpsAuditLog::where('action', 'instance.sync.delivered')->count());
            $http = Request::create('http://ops.example.test/admin/instances/'.$instance->id.'/push', 'POST');
            $http->setUserResolver(fn () => $admin);
            try {
                $controller->pushWeb($http, $instance, $delivery);
                self::fail('Production must not accept HTTP operator actions');
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                self::assertSame(1, OpsAuditLog::where('action', 'instance.sync.delivered')->count());
            }
            self::assertStringNotContainsString($secret, json_encode(OpsAuditLog::all()->toArray()));
        } finally {
            app()->detectEnvironment(fn () => $original);
        }
    }

    public function test_admin_can_change_approved_destination_without_changing_credentials_or_sending(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        config(['ops.entitlement_allowed_hosts' => ['old.example.test', 'new.example.test']]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true]);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'secure-password-123', 'is_admin' => false, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP', 'is_active' => true]);
        InstanceEntitlement::create(['instance_id' => $instance->id, 'commercial_mode' => 'LICENSE', 'status' => 'ACTIVE', 'source_revision' => 1]);
        $secret = str_repeat('ab', 32);
        $delivery = new InstanceEntitlementDelivery(fn ($host) => ['8.8.8.8']);
        $delivery->enroll($instance, 'https://old.example.test', 'key-1', $secret, $admin);
        Http::fake();
        foreach (['http://new.example.test', 'https://unknown.example.test', 'https://new.example.test/path', 'https://user@new.example.test'] as $url) {
            try {
                $delivery->changeTarget($instance, 'https://old.example.test', $url, 'Customer changed domain', $admin);
                self::fail('Unapproved destination must not be saved');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('target_url', $exception->errors());
            }
        }
        foreach ([$staff, $admin] as $actor) {
            try {
                ($actor === $admin ? new InstanceEntitlementDelivery(fn ($host) => ['127.0.0.1']) : $delivery)
                    ->changeTarget($instance, 'https://old.example.test', 'https://new.example.test', 'Customer changed domain', $actor);
                self::fail('Non-admin or private DNS target must not be saved');
            } catch (ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
        }
        $request = Request::create('/admin/instances/'.$instance->id.'/delivery-target', 'PATCH', [
            'expected_target_url' => 'https://old.example.test', 'target_url' => 'https://new.example.test',
            'target_change_reason' => 'Customer changed domain',
        ]);
        $request->setUserResolver(fn () => $admin);
        app(AdminInstanceEntitlementController::class)->changeTarget($request, $instance, $delivery);
        self::assertSame('https://new.example.test', InstanceSyncCredential::query()->sole()->target_url);
        self::assertSame($secret, InstanceSyncCredential::query()->sole()->secret);
        self::assertSame(1, InstanceEntitlement::query()->sole()->source_revision);
        $audit = OpsAuditLog::where('action', 'instance.sync.target_changed')->sole();
        self::assertSame('https://old.example.test', $audit->new_values['old_target_url']);
        self::assertSame('Customer changed domain', $audit->new_values['reason']);
        self::assertStringNotContainsString($secret, json_encode($audit->toArray()));
        $page = app(AdminInstanceEntitlementController::class)->edit($instance)->with('errors', new ViewErrorBag)->render();
        self::assertStringContainsString('https://new.example.test/api/v1/entitlements/current', $page);
        self::assertStringContainsString('ปลายทางใหม่ยังไม่ได้ตอบรับ', $page);
        Http::assertNothingSent();
        try {
            $delivery->changeTarget($instance, 'https://old.example.test', 'https://new.example.test', 'Customer changed domain', $admin);
            self::fail('Stale browser form must not overwrite a newer destination');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('target_url', $exception->errors());
        }
        self::assertFalse($delivery->changeTarget($instance, 'https://new.example.test', 'https://new.example.test', 'Customer changed domain', $admin));
        self::assertSame(1, OpsAuditLog::where('action', 'instance.sync.target_changed')->count());
    }
}
