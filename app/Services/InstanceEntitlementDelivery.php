<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\InstanceSyncCredential;
use App\Models\OpsAuditLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class InstanceEntitlementDelivery
{
    private const PATH = '/api/v1/entitlements/current';

    public function __construct(private readonly ?Closure $resolve = null) {}

    public function enroll(Instance $instance, string $url, string $keyId, string $secret, User $actor, ?string $reason = null): void
    {
        $this->assertAdmin($actor);
        if ($instance->product()->value('code') !== 'MINTERP' || ! $instance->is_active) {
            $this->reject('instance', 'Only active MintERP instances can be enrolled');
        }
        $url = rtrim($url, '/');
        if (! $this->validTarget($url)
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $keyId)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $secret)) {
            $this->reject('target_url', 'Invalid enrollment destination or key');
        }
        DB::transaction(function () use ($instance, $actor, $url, $keyId, $secret, $reason): void {
            // Inserting against the unique instance_id refuses accidental secret replacement.
            if (InstanceSyncCredential::query()->where('instance_id', $instance->id)->exists()) {
                $this->reject('instance', 'Instance is already enrolled; use the rotation procedure');
            }
            InstanceSyncCredential::create([
                'instance_id' => $instance->id, 'target_url' => $url, 'key_id' => $keyId, 'secret' => $secret,
            ]);
            OpsAuditLog::create([
                'actor_id' => $actor->id, 'action' => 'instance.sync.enrolled',
                'subject_type' => $instance->getMorphClass(), 'subject_id' => (string) $instance->id,
                'old_values' => [], 'new_values' => ['instance_ref' => $instance->instance_ref, 'target_url' => $url, 'key_id' => $keyId, 'change_reason' => $reason],
            ]);
        });
    }

    public function changeTarget(Instance $instance, string $expectedUrl, string $url, string $reason, User $actor): bool
    {
        $this->assertAdmin($actor);
        $url = rtrim($url, '/');
        $reason = trim($reason);
        if ($instance->product()->value('code') !== 'MINTERP' || ! $this->validTarget($url)) {
            $this->reject('target_url', 'Choose an approved HTTPS destination (or local loopback HTTP in local mode) for MintERP');
        }
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->reject('target_change_reason', 'A reason of 10–500 characters is required');
        }
        if ($url !== $expectedUrl) {
            $this->targetAddress($url);
        }
        return DB::transaction(function () use ($instance, $expectedUrl, $url, $reason, $actor): bool {
            $credential = InstanceSyncCredential::query()->where('instance_id', $instance->id)->lockForUpdate()->first();
            if (! $credential || $credential->target_url !== $expectedUrl) {
                $this->reject('target_url', 'Destination changed; reload and review before saving');
            }
            if ($credential->target_url === $url) {
                return false;
            }
            $oldUrl = $credential->target_url;
            $credential->update(['target_url' => $url]);
            $this->audit($instance, $actor, 'instance.sync.target_changed', [
                'old_target_url' => $oldUrl, 'target_url' => $url, 'reason' => $reason,
            ]);
            return true;
        });
    }

    public function rotate(Instance $instance, string $keyId, string $secret, User $actor): void
    {
        $this->assertAdmin($actor);
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $keyId) || ! preg_match('/\A[a-f0-9]{64}\z/', $secret)) {
            $this->reject('key', 'Invalid key ID or secret');
        }
        DB::transaction(function () use ($instance, $keyId, $secret, $actor): void {
            $credential = InstanceSyncCredential::query()->where('instance_id', $instance->id)->lockForUpdate()->first();
            if (! $credential || $credential->previous_key_id || $credential->key_id === $keyId || hash_equals($credential->secret, $secret)) {
                $this->reject('key', 'Instance not enrolled, key unchanged, or previous rotation not retired');
            }
            $oldId = $credential->key_id;
            $credential->update(['previous_key_id' => $oldId, 'previous_secret' => $credential->secret, 'key_id' => $keyId, 'secret' => $secret]);
            $this->audit($instance, $actor, 'instance.sync.key_rotated', ['previous_key_id' => $oldId, 'key_id' => $keyId]);
        });
    }

    public function retirePreviousKey(Instance $instance, User $actor): void
    {
        $this->assertAdmin($actor);
        DB::transaction(function () use ($instance, $actor): void {
            $credential = InstanceSyncCredential::query()->where('instance_id', $instance->id)->lockForUpdate()->first();
            $events = OpsAuditLog::query()->where('subject_type', $instance->getMorphClass())
                ->where('subject_id', (string) $instance->id);
            $rotation = (clone $events)->where('action', 'instance.sync.key_rotated')->latest('id')->first();
            $destinationChange = (clone $events)->where('action', 'instance.sync.target_changed')->latest('id')->first();
            if (! $credential || ! $credential->previous_key_id || ! $rotation || ! OpsAuditLog::query()
                ->where('subject_type', $instance->getMorphClass())->where('subject_id', (string) $instance->id)
                ->where('action', 'instance.sync.delivered')->where('id', '>', max($rotation->id, $destinationChange?->id ?? 0))
                ->where('new_values->key_id', $credential->key_id)->where('new_values->target_url', $credential->target_url)->exists()) {
                $this->reject('key', 'Push successfully with the new key before retiring the previous key');
            }
            $oldId = $credential->previous_key_id;
            $credential->update(['previous_key_id' => null, 'previous_secret' => null]);
            $this->audit($instance, $actor, 'instance.sync.key_retired', ['key_id' => $oldId]);
        });
    }

    public function bumpForReconciliation(Instance $instance, User $actor, string $reason): int
    {
        $this->assertAdmin($actor);
        if (mb_strlen(trim($reason)) < 10) {
            $this->reject('reason', 'A reason of at least 10 characters is required');
        }
        return DB::transaction(function () use ($instance, $actor, $reason): int {
            if (! InstanceSyncCredential::query()->where('instance_id', $instance->id)->exists()) {
                $this->reject('instance', 'Instance not enrolled');
            }
            $entitlement = $instance->entitlement()->lockForUpdate()->firstOrFail();
            if (! $entitlement->source_revision) {
                $this->reject('instance', 'No source revision to reconcile');
            }
            $old = $entitlement->source_revision;
            $entitlement->increment('source_revision');
            $this->audit($instance, $actor, 'instance.sync.revision_bumped', [
                'old_revision' => $old, 'source_revision' => $entitlement->source_revision, 'reason' => trim($reason),
            ]);
            return $entitlement->source_revision;
        });
    }

    public function push(Instance $instance, User $actor, ?string $changeReason = null): array
    {
        $this->assertAdmin($actor);
        $instance->load(['product', 'entitlement.productPlan']);
        $credentials = InstanceSyncCredential::query()->where('instance_id', $instance->id)->first();
        if ($credentials && ! $this->validTarget($credentials->target_url)) {
            $this->audit($instance, $actor, 'instance.sync.delivery_failed', ['reason' => 'Destination not approved', 'change_reason' => $changeReason]);
            $this->reject('delivery', 'Destination is not approved');
        }
        $entitlement = $instance->entitlement;
        if (! $instance->is_active || ! $instance->customer()->value('is_active') || $instance->product->code !== 'MINTERP'
            || ! $instance->product->is_active || ! $credentials || ! $entitlement
            || ! in_array($entitlement->commercial_mode, ['LICENSE', 'SUBSCRIPTION'], true)
            || ! in_array($entitlement->status, ['ACTIVE', 'SUSPENDED', 'TRIAL'], true)) {
            $this->reject('instance', 'Instance or entitlement is not ready for delivery');
        }
        $subscription = $entitlement->commercial_mode === 'SUBSCRIPTION';
        if (($subscription && ! $entitlement->productPlan)
            || (! $subscription && $entitlement->status === 'TRIAL')) {
            $this->reject('instance', 'Missing or invalid product plan');
        }
        $values = $subscription ? $entitlement->effectiveValues() : [];
        $modules = $subscription ? ($values['included_modules'] ?? []) : [];
        sort($modules);
        $body = [
            'schema_version' => 1, 'product_code' => 'MINTERP', 'instance_ref' => $instance->instance_ref,
            'source_revision' => $entitlement->source_revision,
            'entitlement' => [
                'commercial_mode' => $entitlement->commercial_mode,
                'plan_code' => $subscription ? $entitlement->productPlan->code : null,
                'plan_version' => $subscription ? $entitlement->productPlan->version : null,
                'status' => $entitlement->status,
                'starts_at' => $subscription ? $entitlement->starts_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z') : null,
                'expires_at' => $subscription ? $entitlement->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z') : null,
                'quota_users' => $subscription ? $values['quota_users'] ?? null : null,
                'quota_branches' => $subscription ? $values['quota_branches'] ?? null : null,
                'quota_warehouses' => $subscription ? $values['quota_warehouses'] ?? null : null,
                'included_modules' => $modules,
                'production_addon' => $subscription && $entitlement->production_addon,
            ],
        ];
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        try {
            $ip = $this->targetAddress($credentials->target_url);
            $result = $this->send($credentials, $raw, $ip, $credentials->key_id, $credentials->secret);
            $usedKeyId = $credentials->key_id;
            if ($result->status() === 401 && $credentials->previous_key_id && $credentials->previous_secret) {
                $result = $this->send($credentials, $raw, $ip, $credentials->previous_key_id, $credentials->previous_secret);
                $usedKeyId = $credentials->previous_key_id;
            }
        } catch (ConnectionException $exception) {
            $this->audit($instance, $actor, 'instance.sync.delivery_failed', [
                'source_revision' => $entitlement->source_revision, 'reason' => 'Connection failed', 'change_reason' => $changeReason,
            ]);
            $this->reject('delivery', 'ERP could not be reached');
        } catch (ValidationException $exception) {
            $this->audit($instance, $actor, 'instance.sync.delivery_failed', [
                'source_revision' => $entitlement->source_revision, 'reason' => 'Destination not approved', 'change_reason' => $changeReason,
            ]);
            throw $exception;
        }
        if ($result->status() !== 200 || ! in_array($result->json('status'), ['applied', 'unchanged'], true)
            || $result->json('source_revision') !== $entitlement->source_revision) {
            $this->audit($instance, $actor, 'instance.sync.delivery_failed', [
                'source_revision' => $entitlement->source_revision, 'http_status' => $result->status(), 'key_id' => $usedKeyId, 'change_reason' => $changeReason,
            ]);
            $this->reject('delivery', 'ERP did not confirm this entitlement revision (HTTP '.$result->status().')');
        }
        $this->audit($instance, $actor, 'instance.sync.delivered', [
            'instance_ref' => $instance->instance_ref, 'source_revision' => $entitlement->source_revision,
            'status' => $result->json('status'), 'key_id' => $usedKeyId, 'target_url' => $credentials->target_url, 'change_reason' => $changeReason,
        ]);
        return ['status' => $result->json('status'), 'source_revision' => $entitlement->source_revision];
    }

    private function send(InstanceSyncCredential $credential, string $raw, string $ip, string $keyId, string $secret): \Illuminate\Http\Client\Response
    {
        $timestamp = (string) time();
        $message = "v1\n{$keyId}\n{$timestamp}\nPUT\n".self::PATH."\n".hash('sha256', $raw);
        $host = parse_url($credential->target_url, PHP_URL_HOST);
        $port = parse_url($credential->target_url, PHP_URL_PORT) ?: 443;
        return Http::withHeaders([
            'X-Ops-Key-Id' => $keyId,
            'X-Ops-Timestamp' => $timestamp,
            'X-Ops-Signature' => hash_hmac('sha256', $message, hex2bin($secret)),
        ])->withBody($raw, 'application/json')->timeout(10)->withoutRedirecting()
            ->withOptions(['verify' => true, 'proxy' => '', 'curl' => [CURLOPT_PROXY => '', CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
            ->send('PUT', $credential->target_url.self::PATH);
    }

    private function targetAddress(string $url): string
    {
        // Local HTTP is pinned to loopback; never resolve localhost through DNS or a proxy.
        return parse_url($url, PHP_URL_SCHEME) === 'http'
            ? '127.0.0.1'
            : $this->publicAddress(parse_url($url, PHP_URL_HOST));
    }

    private function publicAddress(string $host): string
    {
        if (! extension_loaded('curl')) {
            $this->reject('delivery', 'cURL is required for pinned HTTPS delivery');
        }
        $addresses = $this->resolve ? ($this->resolve)($host) : array_column(dns_get_record($host, DNS_A) ?: [], 'ip');
        if (! is_array($addresses) || $addresses === []) {
            $this->reject('delivery', 'Destination DNS has no approved public IPv4 address');
        }
        foreach ($addresses as $ip) {
            if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->reject('delivery', 'Destination DNS includes a non-public address');
            }
        }
        return $addresses[0];
    }

    private function audit(Instance $instance, User $actor, string $action, array $values): void
    {
        OpsAuditLog::create([
            'actor_id' => $actor->id, 'action' => $action,
            'subject_type' => $instance->getMorphClass(), 'subject_id' => (string) $instance->id,
            'old_values' => [], 'new_values' => $values,
        ]);
    }

    private function validTarget(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['path'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (
                (app()->environment('local') && ($parts['scheme'] ?? '') === 'http'
                    && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
                    && isset($parts['port']) && $parts['port'] >= 1 && $parts['port'] <= 65535)
                || (($parts['scheme'] ?? '') === 'https'
                    && $this->allowedTargetHost($parts['host'] ?? '')
                    && ! filter_var($parts['host'] ?? '', FILTER_VALIDATE_IP)
                    && (! isset($parts['port']) || $parts['port'] === 443)
                    && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\.[a-zA-Z]{2,}\z/', $parts['host'] ?? '') === 1)
            );
    }

    private function allowedTargetHost(string $host): bool
    {
        foreach (config('ops.entitlement_allowed_hosts', []) as $allowedHost) {
            $host = strtolower($host);
            $allowedHost = strtolower($allowedHost);
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->is_admin || ! $actor->is_active) {
            $this->reject('actor', 'An active Ops Admin is required');
        }
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
