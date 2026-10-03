<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceEntitlement extends Model
{
    protected $fillable = [
        'instance_id', 'product_plan_id', 'commercial_mode', 'status', 'starts_at', 'expires_at',
        'overrides', 'production_addon', 'cancel_at_period_end', 'source_revision', 'change_reason',
        'paid_period_end', 'grace_days',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_period_end' => 'datetime',
            'grace_days' => 'integer',
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

    public function billingBoundary(): ?\Illuminate\Support\Carbon
    {
        return $this->paid_period_end ?? $this->expires_at;
    }

    public function renewalStage(): string
    {
        if ($this->status === 'TRIAL') {
            return 'ทดลองใช้';
        }
        if (! $this->paid_period_end) {
            return $this->expires_at?->lte(now()) ? 'สิทธิ์หมดอายุ · ยังไม่แยกรอบบริการ/ผ่อนผัน' : 'ยังไม่แยกรอบบริการ/ผ่อนผัน';
        }
        return match (true) {
            $this->expires_at?->lte(now()) => 'สิทธิ์หมดอายุ',
            $this->paid_period_end->lte(now()) => 'อยู่ช่วงผ่อนผัน',
            $this->paid_period_end->lte(now()->addDays(15)) => 'ใกล้ครบกำหนด',
            default => 'อยู่ในรอบที่ชำระ',
        };
    }

    public function renewalSnapshot(): array
    {
        return $this->only(['commercial_mode', 'status', 'product_plan_id', 'starts_at', 'expires_at',
            'paid_period_end', 'grace_days', 'overrides', 'production_addon', 'cancel_at_period_end', 'source_revision']);
    }

    public function effectiveValues(): array
    {
        return array_replace($this->productPlan?->entitlement_defaults ?? [], $this->overrides ?? []);
    }
}
