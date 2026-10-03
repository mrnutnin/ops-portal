<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Instance extends Model
{
    protected $fillable = ['customer_id', 'product_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Instance $instance): void {
            $instance->instance_ref ??= (string) Str::uuid7();
        });

        static::updating(function (Instance $instance): void {
            if ($instance->isDirty('instance_ref')) {
                throw new LogicException('An instance reference cannot be changed.');
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(InstanceRenewal::class);
    }

    public function openRenewal(): HasOne
    {
        return $this->hasOne(InstanceRenewal::class)->whereNotNull('open_instance_id');
    }

    public function latestRenewal(): HasOne
    {
        return $this->hasOne(InstanceRenewal::class)->latestOfMany();
    }

    public function syncCredential(): HasOne
    {
        return $this->hasOne(InstanceSyncCredential::class);
    }

    public function lastDelivery(): HasOne
    {
        return $this->hasOne(OpsAuditLog::class, 'subject_id')->ofMany(['id' => 'max'], function ($query): void {
            $query->where('subject_type', $this->getMorphClass())
                ->whereIn('action', ['instance.sync.delivered', 'instance.sync.delivery_failed', 'instance.sync.target_changed']);
        });
    }

    public function scopeAwaitingDelivery($query): void
    {
        $query->whereHas('product', fn ($p) => $p->where('code', 'MINTERP'))
            ->whereDoesntHave('lastDelivery', function ($event): void {
                $event->where('action', 'instance.sync.delivered')
                    ->join('instance_entitlements as delivery_entitlement', 'delivery_entitlement.instance_id', '=', 'ops_audit_logs.subject_id')
                    ->join('instance_sync_credentials as delivery_credential', 'delivery_credential.instance_id', '=', 'ops_audit_logs.subject_id')
                    ->whereColumn('new_values->source_revision', 'delivery_entitlement.source_revision')
                    ->whereColumn('new_values->target_url', 'delivery_credential.target_url')
                    ->whereColumn('new_values->key_id', 'delivery_credential.key_id');
            });
    }

    public function deliveryState(): array
    {
        if ($this->product->code !== 'MINTERP') {
            return ['label' => 'ยังไม่รองรับการส่ง', 'confirmed' => false];
        }
        $event = $this->lastDelivery;
        $credential = $this->syncCredential;
        $confirmed = $credential && $event?->action === 'instance.sync.delivered'
            && ($event->new_values['source_revision'] ?? null) === $this->entitlement?->source_revision
            && ($event->new_values['target_url'] ?? null) === $credential->target_url
            && ($event->new_values['key_id'] ?? null) === $credential->key_id;
        return ['label' => $confirmed ? 'ERP ตอบรับ revision ปัจจุบัน' : ($event?->action === 'instance.sync.delivery_failed' ? 'ส่งไม่สำเร็จ — ต้องติดตาม' : 'ยังไม่ส่ง revision/ปลายทางปัจจุบัน'), 'confirmed' => (bool) $confirmed];
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(InstanceEntitlement::class);
    }
}
