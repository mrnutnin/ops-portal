<?php

use App\Models\Instance;
use App\Models\User;
use App\Services\InstanceEntitlementDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

Artisan::command('ops:entitlement:enroll {ref} {url} {key_id} {--actor=}', function (string $ref, string $url, string $key_id): int {
    $actor = User::query()->find($this->option('actor'));
    $instance = Instance::query()->where('instance_ref', $ref)->first();
    if (! $actor || ! $instance) {
        $this->error('Unknown actor or Instance');
        return 1;
    }
    // Pass the 64-character hex secret via protected stdin, not command arguments/history.
    $secret = trim(stream_get_contents(STDIN));
    try {
        app(InstanceEntitlementDelivery::class)->enroll($instance, $url, $key_id, $secret, $actor);
        $this->info('Instance enrolled; secret not printed. Bind the same ref and secret on ERP before pushing.');
        return 0;
    } catch (ValidationException $exception) {
        $this->error($exception->errors() ? collect($exception->errors())->flatten()->first() : 'Enrollment failed');
        return 1;
    }
})->purpose('Enroll an Instance from protected stdin without displaying the secret');

Artisan::command('ops:entitlement:rotate {ref} {key_id} {--actor=}', function (string $ref, string $key_id): int {
    $actor = User::query()->find($this->option('actor'));
    $instance = Instance::query()->where('instance_ref', $ref)->first();
    if (! $actor || ! $instance) {
        $this->error('Unknown actor or Instance');
        return 1;
    }
    try {
        app(InstanceEntitlementDelivery::class)->rotate($instance, $key_id, trim(stream_get_contents(STDIN)), $actor);
        $this->info('New key installed in Ops. Push and verify new-key delivery before retiring the old key.');
        return 0;
    } catch (ValidationException $exception) {
        $this->error(collect($exception->errors())->flatten()->first() ?? 'Rotation failed');
        return 1;
    }
})->purpose('Rotate the per-instance signing key from protected stdin');

Artisan::command('ops:entitlement:retire-key {ref} {--actor=}', function (string $ref): int {
    $actor = User::query()->find($this->option('actor'));
    $instance = Instance::query()->where('instance_ref', $ref)->first();
    if (! $actor || ! $instance) {
        $this->error('Unknown actor or Instance');
        return 1;
    }
    try {
        app(InstanceEntitlementDelivery::class)->retirePreviousKey($instance, $actor);
        $this->info('Old Ops key retired; remove the previous key from ERP configuration.');
        return 0;
    } catch (ValidationException $exception) {
        $this->error(collect($exception->errors())->flatten()->first() ?? 'Retirement failed');
        return 1;
    }
})->purpose('Retire an old key after ERP acknowledges a push signed by the new key');

Artisan::command('ops:entitlement:bump {ref} {--actor=} {--reason=}', function (string $ref): int {
    $actor = User::query()->find($this->option('actor'));
    $instance = Instance::query()->where('instance_ref', $ref)->first();
    if (! $actor || ! $instance) {
        $this->error('Unknown actor or Instance');
        return 1;
    }
    try {
        $revision = app(InstanceEntitlementDelivery::class)->bumpForReconciliation($instance, $actor, (string) $this->option('reason'));
        $this->info("Ops revision {$revision} recorded; reconcile on ERP before pushing.");
        return 0;
    } catch (ValidationException $exception) {
        $this->error(collect($exception->errors())->flatten()->first() ?? 'Bump failed');
        return 1;
    }
})->purpose('Explicitly bump the source revision after reviewing an ERP local override');

Artisan::command('ops:entitlement:push {ref} {--actor=}', function (string $ref): int {
    $actor = User::query()->find($this->option('actor'));
    $instance = Instance::query()->where('instance_ref', $ref)->first();
    if (! $actor || ! $instance) {
        $this->error('Unknown actor or Instance');
        return 1;
    }
    try {
        $result = app(InstanceEntitlementDelivery::class)->push($instance, $actor);
        $this->info("ERP confirmed {$result['status']} at source revision {$result['source_revision']}.");
        return 0;
    } catch (ValidationException $exception) {
        $this->error($exception->errors() ? collect($exception->errors())->flatten()->first() : 'Delivery failed');
        return 1;
    }
})->purpose('Manually push the current MintERP entitlement and verify ERP acknowledgement');
