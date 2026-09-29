<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function entitlement(): HasOne
    {
        return $this->hasOne(InstanceEntitlement::class);
    }
}
