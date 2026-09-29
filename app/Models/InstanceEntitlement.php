<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceEntitlement extends Model
{
    protected $fillable = [
        'instance_id', 'product_plan_id', 'commercial_mode', 'status', 'starts_at', 'expires_at',
        'overrides', 'production_addon', 'cancel_at_period_end', 'source_revision', 'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'overrides' => 'array',
            'production_addon' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'source_revision' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function productPlan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class);
    }

    public function effectiveValues(): array
    {
        return array_replace($this->productPlan?->entitlement_defaults ?? [], $this->overrides ?? []);
    }
}
