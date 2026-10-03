<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Instance;
use App\Models\InstanceEntitlement;
use App\Models\InstanceRenewal;
use App\Models\InstanceSyncCredential;
use App\Models\OpsAuditLog;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use App\Services\InstanceRenewalService;
use App\Services\InstanceEntitlementDelivery;
use App\Services\TrialIssuanceService;
use Database\Seeders\ProductPlanSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstanceRenewalContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Instance $instance;
    private InstanceRenewalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(0, 0));
        Storage::fake('local');
        Http::fake();
        $this->seed([ProductSeeder::class, ProductPlanSeeder::class]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'billing@example.test', 'password' => 'secure-password-123', 'is_admin' => true, 'is_active' => true, 'must_change_password' => false]);
        $customer = Customer::create(['name' => 'Example customer', 'is_active' => true]);
        $product = Product::where('code', 'MINTERP')->sole();
        $this->instance = Instance::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'name' => 'ERP production', 'is_active' => true]);
        InstanceEntitlement::create([
            'instance_id' => $this->instance->id, 'commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE',
            'product_plan_id' => ProductPlan::where('product_id', $product->id)->where('code', 'BUSINESS')->sole()->id,
            'starts_at' => '2026-09-30 17:00:00', 'expires_at' => '2026-10-31 17:00:00',
            'overrides' => ['quota_users' => 20], 'production_addon' => true, 'source_revision' => 1,
        ]);
        $this->service = app(InstanceRenewalService::class);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['kind' => 'RENEWAL', 'period_start' => '2026-11-01T00:00', 'period_end' => '2026-12-01T00:00',
            'payment_due_at' => '2026-11-01T00:00', 'agreed_amount' => '1000.10', 'reason' => 'Contract renewal approved', 'expected_revision' => $this->instance->entitlement()->sole()->source_revision];
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    private function confirm(InstanceRenewal $record, array $extra = []): void
    {
        $this->service->confirm($record, $extra + ['expected_version' => $record->fresh()->version, 'received_amount' => '1000.10', 'received_at' => '2026-10-20T07:00',
            'external_reference' => 'ACC-2026-001', 'reason' => 'Accounts verified full settlement', 'settled' => '1'], $this->file(), $this->admin);
    }

    private function rejected(callable $action, string $field): void
    {
        try { $action(); $this->fail('Must reject '.$field); }
        catch (ValidationException $exception) { $this->assertArrayHasKey($field, $exception->errors()); }
    }

    public function test_money_confirmation_and_renewal_are_separate_and_grace_does_not_compound(): void
    {
        $record = $this->service->save($this->instance, $this->input(), $this->admin);
        $this->confirm($record, ['received_amount' => '970.10', 'difference_reason' => 'Withholding tax verified']);
        $this->assertSame(1, $this->instance->entitlement()->sole()->source_revision);
        $this->assertSame('970.10', $record->fresh()->received_amount);
        $this->service->apply($record, $this->admin, 1, 'Renewal verified and approved');
        $e = $this->instance->entitlement()->sole();
        $this->assertSame('2026-11-30 17:00:00', $e->paid_period_end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-15 17:00:00', $e->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 17:00:00', $e->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(['quota_users' => 20], $e->overrides);
        $this->assertTrue($e->production_addon);
        $this->assertSame(2, $e->source_revision);
        $this->assertSame('APPLIED', $record->fresh()->status);
        $this->assertNull($record->fresh()->open_instance_id);
        $this->rejected(fn () => $this->service->apply($record, $this->admin, 2, 'Duplicate renewal attempt'), 'renewal');
        $this->rejected(fn () => $this->service->void($record, $this->admin, 'Cannot void used payment'), 'renewal');
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(['period_start' => '2026-12-16T00:00', 'period_end' => '2027-01-01T00:00']), $this->admin), 'period_start');
        $next = $this->service->save($this->instance, $this->input(['period_start' => '2026-12-01T00:00', 'period_end' => '2027-01-01T00:00']), $this->admin);
        $this->confirm($next);
        $this->service->apply($next, $this->admin, 2, 'Next month renewal approved');
        $this->assertSame('2027-01-16 00:00', $e->fresh()->expires_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
        $this->assertSame(2, OpsAuditLog::where('action', 'renewal.applied')->count());
        Http::assertNothingSent();
    }

    public function test_late_payment_in_grace_keeps_service_month(): void
    {
        $e = $this->instance->entitlement()->sole();
        $e->update(['paid_period_end' => $e->expires_at, 'grace_days' => 15, 'expires_at' => $e->expires_at->copy()->addDays(15)]);
        $record = $this->service->save($this->instance, $this->input(), $this->admin);
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(0, 0));
        $this->confirm($record, ['received_at' => '2026-11-10T07:00']);
        $this->service->apply($record, $this->admin, 1, 'Late payment during grace approved');
        $this->assertSame('2026-12-01 00:00', $e->fresh()->paid_period_end->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
        $this->assertSame('2026-12-16 00:00', $e->fresh()->expires_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 00:00', $record->fresh()->period_start->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
    }

    public function test_legacy_grace_is_explicit_once_and_never_changes_old_data_on_migration(): void
    {
        $e = $this->instance->entitlement()->sole();
        $this->assertNull($e->paid_period_end);
        $this->assertSame(0, $e->grace_days);
        $record = $this->service->save($this->instance, $this->input(['kind' => 'GRACE', 'period_start' => '2026-10-01T00:00', 'period_end' => '2026-11-01T00:00']), $this->admin);
        $this->confirm($record);
        $this->service->apply($record, $this->admin, 1, 'Legacy receipt verified grace enabled');
        $this->assertSame('2026-11-16 00:00', $e->fresh()->expires_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(['kind' => 'GRACE']), $this->admin), 'kind');
        Http::assertNothingSent();
    }

    public function test_open_record_unique_stale_revision_locked_confirmation_and_void_history(): void
    {
        $record = $this->service->save($this->instance, $this->input(), $this->admin);
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(), $this->admin), 'renewal');
        $this->confirm($record);
        $path = $record->fresh()->evidence_path;
        $this->rejected(fn () => $this->confirm($record), 'renewal');
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(['expected_version' => $record->version]), $this->admin, $record), 'renewal');
        $this->instance->entitlement()->sole()->increment('source_revision');
        $this->rejected(fn () => $this->service->apply($record, $this->admin, 2, 'Stale payment revision blocked'), 'expected_revision');
        $this->service->void($record, $this->admin, 'Reviewed stale record create replacement');
        $this->assertSame('VOID', $record->fresh()->status);
        Storage::disk('local')->assertExists($path);
        $replacement = $this->service->save($this->instance, $this->input(), $this->admin);
        try {
            InstanceRenewal::create($replacement->getAttributes());
            $this->fail('DB must reject duplicate open_instance_id');
        } catch (QueryException $exception) { $this->assertStringContainsString('UNIQUE', $exception->getMessage()); }
    }

    public function test_validation_private_files_and_failure_cleanup(): void
    {
        $record = $this->service->save($this->instance, $this->input(), $this->admin);
        $this->rejected(fn () => $this->confirm($record, ['received_amount' => '900']), 'difference_reason');
        $this->rejected(fn () => $this->confirm($record, ['received_at' => '2026-10-21T07:00']), 'received_at');
        $this->rejected(fn () => $this->confirm($record, ['settled' => '0']), 'settled');
        $data = ['expected_version' => $record->version, 'received_amount' => '1000.10', 'received_at' => '2026-10-20T07:00', 'external_reference' => 'R1', 'reason' => 'Full settlement verified', 'settled' => '1'];
        $temp = tempnam(sys_get_temp_dir(), 'bad-evidence-');
        file_put_contents($temp, '<svg></svg>');
        try {
            foreach ([new UploadedFile($temp, 'bad.png', null, null, true), UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')] as $file) {
                $this->rejected(fn () => $this->service->confirm($record, $data, $file, $this->admin), 'evidence');
            }
        } finally { unlink($temp); }
        $this->assertSame('PENDING', $record->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        // Fail the audit insert after the file write and record update: DB must roll back and file must be removed.
        OpsAuditLog::creating(function ($audit): void { if ($audit->action === 'renewal.confirmed') { throw new \RuntimeException('audit unavailable'); } });
        try { $this->confirm($record); $this->fail('Audit failure must roll back'); }
        catch (\RuntimeException $exception) { $this->assertSame('audit unavailable', $exception->getMessage()); }
        finally { OpsAuditLog::flushEventListeners(); }
        $this->assertSame('PENDING', $record->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Storage::shouldReceive('disk')->with('local')->once()->andReturn(new class { public function putFile($dir, $file) { return false; } });
        $this->rejected(fn () => $this->confirm($record), 'evidence');
    }

    public function test_admin_routes_render_and_non_admin_cannot_access_money_or_files(): void
    {
        $this->actingAs($this->admin)->get('/admin/renewals')->assertOk()->assertSee('ยังไม่แยกรอบบริการ/ผ่อนผัน');
        $this->get('/admin/instances/'.$this->instance->id.'/renewals/create')->assertOk();
        $this->post('/admin/instances/'.$this->instance->id.'/renewals', $this->input())->assertRedirect();
        $record = InstanceRenewal::sole();
        $this->get('/admin/renewals/'.$record->id)->assertOk();
        $this->post('/admin/renewals/'.$record->id.'/confirm', ['expected_version' => 1, 'received_amount' => '1000.10',
            'received_at' => '2026-10-20T07:00', 'external_reference' => 'ACC-001', 'reason' => 'Accounts verified receipt',
            'settled' => '1', 'evidence' => $this->file()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/admin/renewals/'.$record->id)->assertOk()->assertSee('รับชำระแล้ว');
        $this->get('/admin/renewals/'.$record->id.'/evidence')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/admin/renewals/'.$record->id.'/apply', ['expected_revision' => 1, 'reason' => 'Receipt verified apply renewal'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/admin/renewals/'.$record->id)->assertOk();
        $this->get('/admin/renewals?group=APPLIED')->assertOk()->assertSee('ERP production');
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'secure-password-123', 'is_admin' => false, 'is_active' => true, 'must_change_password' => false]);
        $this->flushSession();
        $this->actingAs($staff);
        foreach (['/admin/renewals', '/admin/renewals/'.$record->id, '/admin/renewals/'.$record->id.'/evidence'] as $url) { $this->get($url)->assertForbidden(); }
        foreach (['confirm', 'apply', 'void'] as $action) { $this->post('/admin/renewals/'.$record->id.'/'.$action, [])->assertForbidden(); }
        $this->patch('/admin/renewals/'.$record->id, [])->assertForbidden();
        $this->post('/admin/instances/'.$this->instance->id.'/renewals', [])->assertForbidden();
        $this->rejected(fn () => $this->service->void($record, $staff, 'Unauthorized admin action'), 'actor');
        auth()->logout();
        foreach (['/admin/renewals', '/admin/renewals/'.$record->id, '/admin/renewals/'.$record->id.'/evidence'] as $url) { $this->get($url)->assertRedirect('/login'); }
        foreach (['confirm', 'apply', 'void'] as $action) { $this->post('/admin/renewals/'.$record->id.'/'.$action, [])->assertRedirect('/login'); }
        $this->get('/storage/'.$record->fresh()->evidence_path)->assertNotFound();
    }

    public function test_reminder_boundaries_filters_and_delivery_state_never_trust_old_success(): void
    {
        $e = $this->instance->entitlement()->sole();
        $e->update(['paid_period_end' => now()->addDays(15), 'grace_days' => 15, 'expires_at' => now()->addDays(30)]);
        $this->assertSame('ใกล้ครบกำหนด', $e->fresh()->renewalStage());
        $this->actingAs($this->admin)->get('/admin/renewals?group=due')->assertSee('ERP production');
        $this->get('/admin/renewals?group=grace')->assertDontSee('ERP production');
        $e->update(['paid_period_end' => now(), 'expires_at' => now()->addDays(15)]);
        $this->assertSame('อยู่ช่วงผ่อนผัน', $e->fresh()->renewalStage());
        $this->get('/admin/renewals?group=grace')->assertSee('ERP production');
        $e->update(['expires_at' => now()]);
        $this->assertSame('สิทธิ์หมดอายุ', $e->fresh()->renewalStage());
        $this->get('/admin/renewals?group=expired')->assertSee('ERP production');
        $this->get('/admin/renewals?group=all&customer=Other')->assertDontSee('ERP production');
        $this->get('/admin/renewals?group=all&product=MINTPOS')->assertDontSee('ERP production');
        $credential = InstanceSyncCredential::create(['instance_id' => $this->instance->id, 'target_url' => 'https://erp.example.test', 'key_id' => 'k1', 'secret' => str_repeat('ab', 32)]);
        $event = ['actor_id' => $this->admin->id, 'subject_type' => $this->instance->getMorphClass(), 'subject_id' => (string) $this->instance->id, 'old_values' => []];
        OpsAuditLog::create($event + ['action' => 'instance.sync.delivered', 'new_values' => ['target_url' => $credential->target_url, 'key_id' => 'k1', 'source_revision' => 1]]);
        $this->assertTrue($this->instance->fresh()->deliveryState()['confirmed']);
        $e->increment('source_revision');
        $this->assertFalse($this->instance->fresh()->deliveryState()['confirmed']);
        $e->update(['source_revision' => 1]);
        $credential->update(['target_url' => 'https://changed.example.test']);
        $this->assertFalse($this->instance->fresh()->deliveryState()['confirmed']);
        $credential->update(['target_url' => 'https://erp.example.test']);
        OpsAuditLog::create($event + ['action' => 'instance.sync.delivery_failed', 'new_values' => []]);
        $this->assertFalse($this->instance->fresh()->deliveryState()['confirmed']);
        $e->update(['commercial_mode' => 'LICENSE']);
        $this->get('/admin/renewals?group=all')->assertDontSee('ERP production');
    }

    public function test_inactive_suspended_cancelled_trial_and_invalid_periods_are_not_renewed(): void
    {
        $e = $this->instance->entitlement()->sole();
        foreach ([['commercial_mode' => 'LICENSE'], ['status' => 'SUSPENDED'], ['status' => 'TRIAL'], ['cancel_at_period_end' => true]] as $state) {
            $e->update($state);
            $this->rejected(fn () => $this->service->save($this->instance, $this->input(), $this->admin), 'instance');
            $e->update(['commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE', 'cancel_at_period_end' => false]);
        }
        $this->instance->customer->update(['is_active' => false]);
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(), $this->admin), 'instance');
        $this->instance->customer->update(['is_active' => true]);
        $this->instance->update(['is_active' => false]);
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(), $this->admin), 'instance');
        $this->actingAs($this->admin)->get('/admin/renewals')->assertDontSee('ERP production');
        $this->get('/admin/renewals?group=all')->assertSee('ERP production');
        $this->instance->update(['is_active' => true]);
        $this->instance->product->update(['is_active' => false]);
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(), $this->admin), 'instance');
        $this->instance->product->update(['is_active' => true]);
        foreach ([['period_start' => '2026-10-01T00:00'], ['period_end' => '2026-10-01T00:00'], ['agreed_amount' => '1.001'], ['agreed_amount' => '0']] as $input) {
            $this->rejected(fn () => $this->service->save($this->instance, $this->input($input), $this->admin), array_key_first($input));
        }
        $e->update(['expires_at' => '2026-10-01 00:00:00']);
        $record = $this->service->save($this->instance, $this->input(['period_start' => '2026-10-20T07:00', 'period_end' => '2026-11-20T07:00']), $this->admin);
        $this->confirm($record);
        $this->service->apply($record, $this->admin, 1, 'Restart service after gap');
        $this->assertSame(now()->timestamp, $e->fresh()->starts_at->timestamp);
    }

    public function test_delivery_uses_unchanged_erp_contract_and_failure_does_not_rollback_payment(): void
    {
        $record = $this->service->save($this->instance, $this->input(), $this->admin);
        $this->confirm($record);
        $this->service->apply($record, $this->admin, 1, 'Payment verified renewal applied');
        config(['ops.entitlement_allowed_hosts' => ['erp.example.test']]);
        $delivery = new InstanceEntitlementDelivery(fn ($host) => ['93.184.216.34']);
        $delivery->enroll($this->instance, 'https://erp.example.test', 'key1', str_repeat('ab', 32), $this->admin);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);
        $this->rejected(fn () => $delivery->push($this->instance, $this->admin), 'delivery');
        $this->assertSame('APPLIED', $record->fresh()->status);
        $this->assertSame(2, $this->instance->entitlement()->sole()->source_revision);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['status' => 'applied', 'source_revision' => 2], 200)]);
        $delivery->push($this->instance, $this->admin);
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);
            return $body['entitlement']['expires_at'] === '2026-12-15T17:00:00Z'
                && ! array_key_exists('paid_period_end', $body['entitlement'])
                && ! array_key_exists('grace_days', $body['entitlement']);
        });
        $this->assertTrue($this->instance->fresh()->deliveryState()['confirmed']);
        $this->assertFalse(Instance::whereKey($this->instance->id)->awaitingDelivery()->exists());
        $this->actingAs($this->admin)->get('/admin/renewals')->assertDontSee('ERP production');
        $this->instance->entitlement()->sole()->increment('source_revision');
        $this->assertTrue(Instance::whereKey($this->instance->id)->awaitingDelivery()->exists());
        $this->get('/admin/renewals')->assertSee('ERP production');
        $next = $this->service->save($this->instance, $this->input(['period_start' => '2026-12-01T00:00', 'period_end' => '2027-01-01T00:00']), $this->admin);
        $this->service->void($next, $this->admin, 'Cancelled next collection record');
        $this->get('/admin/renewals')->assertSee('ERP production'); // A later VOID must not hide an earlier unsent renewal.
    }

    public function test_pending_edits_check_version_and_cancellation_removes_grace_with_audit(): void
    {
        $record = $this->service->save($this->instance, $this->input(), $this->admin);
        $this->service->save($this->instance, $this->input(['expected_version' => 1, 'note' => 'Reviewed agreement']), $this->admin, $record);
        $this->assertSame(2, $record->fresh()->version);
        $this->rejected(fn () => $this->service->save($this->instance, $this->input(['expected_version' => 1]), $this->admin, $record), 'renewal');
        $this->rejected(fn () => $this->confirm($record, ['expected_version' => 1]), 'renewal');
        $this->confirm($record);
        $this->service->apply($record, $this->admin, 1, 'Verified updated renewal payment');
        $e = $this->instance->entitlement()->sole();
        $form = ['commercial_mode' => 'SUBSCRIPTION', 'status' => 'ACTIVE', 'product_plan_id' => $e->product_plan_id,
            'starts_at' => '2026-10-01T00:00', 'expires_at' => '2026-12-16T00:00',
            'quota_overrides' => ['quota_users' => 20], 'production_addon' => '1', 'change_reason' => 'Cancellation at paid period end'];
        $this->actingAs($this->admin)->put('/admin/instances/'.$this->instance->id.'/entitlement', $form + ['cancel_at_period_end' => '1'])->assertRedirect();
        $this->assertSame(0, $e->fresh()->grace_days);
        $this->assertSame($e->fresh()->paid_period_end->timestamp, $e->fresh()->expires_at->timestamp);
        $this->assertSame(3, $e->fresh()->source_revision);
        $this->put('/admin/instances/'.$this->instance->id.'/entitlement', array_replace($form, ['expires_at' => '2027-01-01T00:00']))->assertSessionHasErrors('expires_at');
        $this->put('/admin/instances/'.$this->instance->id.'/entitlement', array_replace($form, ['expires_at' => '2027-01-01T00:00', 'reset_billing' => '1']))->assertRedirect();
        $this->assertNull($e->fresh()->paid_period_end);
        $this->assertSame(0, $e->fresh()->grace_days);
        $this->assertSame('APPLIED', $record->fresh()->status);
        $this->put('/admin/instances/'.$this->instance->id.'/entitlement', ['commercial_mode' => 'LICENSE', 'status' => 'ACTIVE', 'change_reason' => 'Switch to License approved'])->assertRedirect();
        $this->assertNull($e->fresh()->paid_period_end);
        $this->assertSame(0, $e->fresh()->grace_days);
    }

    public function test_short_month_and_pos_renewal_keep_calendar_period_without_claiming_delivery(): void
    {
        $pos = Product::where('code', 'MINTPOS')->sole();
        $this->instance->update(['product_id' => $pos->id]);
        $e = $this->instance->entitlement()->sole();
        $e->update(['product_plan_id' => ProductPlan::where('product_id', $pos->id)->where('code', 'CORE')->sole()->id,
            'expires_at' => '2027-01-31 17:00:00', 'paid_period_end' => '2027-01-31 17:00:00', 'grace_days' => 0, 'production_addon' => false]);
        $record = $this->service->save($this->instance, $this->input(['period_start' => '2027-02-01T00:00', 'period_end' => '2027-03-01T00:00']), $this->admin);
        $this->confirm($record);
        $this->service->apply($record, $this->admin, 1, 'February calendar period approved');
        $this->assertSame('2027-03-16 00:00', $e->fresh()->expires_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
        $this->assertSame('ยังไม่รองรับการส่ง', $this->instance->fresh()->deliveryState()['label']);
        Http::assertNothingSent();
    }

    public function test_trial_cannot_convert_without_confirmed_payment(): void
    {
        $this->instance->entitlement()->delete();
        $plan = ProductPlan::where('product_id', $this->instance->product_id)->where('code', 'BUSINESS')->sole();
        $trials = app(TrialIssuanceService::class);
        $trials->issue($this->instance, $plan->id, $this->admin, 'Trial handover approved', false, false);
        $this->rejected(fn () => $trials->convertToPaid($this->instance, $plan->id, now()->addMonth(), $this->admin, 'Unpaid conversion rejected'), 'renewal_id');
        $this->assertNull($this->instance->entitlement()->sole()->paid_period_end);
        $this->assertSame(0, $this->instance->entitlement()->sole()->grace_days);
    }
}
