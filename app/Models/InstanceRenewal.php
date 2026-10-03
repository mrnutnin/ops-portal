<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceRenewal extends Model
{
    public const STATUS_LABELS = ['PENDING' => 'รอยืนยันชำระ', 'CONFIRMED' => 'รับชำระแล้ว — รอต่ออายุ', 'APPLIED' => 'นำไปใช้ใน Ops แล้ว', 'VOID' => 'ยกเลิกรายการ'];
    public const KIND_LABELS = ['RENEWAL' => 'ต่ออายุ', 'GRACE' => 'เปิดผ่อนผันให้รอบเดิม', 'TRIAL' => 'รับชำระรอบแรกหลัง Trial'];

    protected $guarded = ['id'];
    protected $attributes = ['status' => 'PENDING', 'version' => 1];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime', 'period_end' => 'datetime', 'payment_due_at' => 'datetime',
            'received_at' => 'datetime', 'confirmed_at' => 'datetime', 'applied_at' => 'datetime', 'voided_at' => 'datetime',
            'agreed_amount' => 'decimal:2', 'received_amount' => 'decimal:2',
            'before_values' => 'array', 'after_values' => 'array', 'expected_revision' => 'integer',
            'applied_revision' => 'integer', 'grace_days' => 'integer', 'version' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
    public function voider(): BelongsTo { return $this->belongsTo(User::class, 'voided_by'); }

    public function accessEnd(): \Illuminate\Support\Carbon
    {
        return $this->period_end->copy()->addDays($this->grace_days);
    }
}
