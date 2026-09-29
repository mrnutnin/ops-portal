<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsAuditLog extends Model
{
    public const ACTION_LABELS = [
        'owner.bootstrap_created' => 'สร้าง Owner คนแรก',
        'owner.user.invited' => 'เชิญ Owner',
        'owner.user.invitation_delivery_sent' => 'ส่งคำเชิญแล้ว',
        'owner.user.invitation_delivery_failed' => 'ส่งคำเชิญไม่สำเร็จ',
        'owner.user.invitation_resent' => 'ส่งคำเชิญซ้ำแล้ว',
        'owner.user.invitation_resend_failed' => 'ส่งคำเชิญซ้ำไม่สำเร็จ',
        'owner.user.activated' => 'เปิดใช้งานบัญชี',
        'owner.user.deactivated' => 'ระงับบัญชี',
        'admin.bootstrap_created' => 'สร้าง Admin คนแรก',
        'admin.user.created' => 'สร้างบัญชีผู้ใช้',
        'admin.user.activated' => 'เปิดใช้งานบัญชี',
        'admin.user.deactivated' => 'ระงับบัญชี',
        'user.password_changed' => 'เปลี่ยนรหัสผ่าน',
        'customer.created' => 'เพิ่มลูกค้า',
        'customer.updated' => 'แก้ไขลูกค้า',
        'customer.activated' => 'เปิดใช้งานลูกค้า',
        'customer.deactivated' => 'ระงับลูกค้า',
        'product.created' => 'เพิ่มผลิตภัณฑ์',
        'product.updated' => 'แก้ไขผลิตภัณฑ์',
        'product.activated' => 'เปิดใช้งานผลิตภัณฑ์',
        'product.deactivated' => 'ระงับผลิตภัณฑ์',
        'instance.created' => 'เพิ่ม Instance',
        'instance.updated' => 'แก้ไข Instance',
        'instance.activated' => 'เปิดใช้งาน Instance',
        'instance.deactivated' => 'ระงับ Instance',
        'plan.created' => 'สร้าง Plan version',
        'plan.activated' => 'เปิดใช้ Plan version',
        'plan.deactivated' => 'ปิดใช้ Plan version',
        'instance.sync.enrolled' => 'ผูก Instance สำหรับส่งสิทธิ์',
        'instance.sync.target_changed' => 'เปลี่ยนโดเมนปลายทางส่งสิทธิ์',
        'instance.sync.delivered' => 'ส่งสิทธิ์ถึง Instance',
        'instance.sync.delivery_failed' => 'ส่งสิทธิ์ไม่สำเร็จ',
        'instance.sync.key_rotated' => 'หมุนเวียนกุญแจส่งสิทธิ์',
        'instance.sync.key_retired' => 'เลิกใช้กุญแจส่งสิทธิ์เดิม',
        'instance.sync.revision_bumped' => 'เพิ่ม Revision เพื่อกระทบยอด',
        'instance.entitlement.assigned' => 'กำหนดสิทธิ์ Instance',
        'instance.entitlement.updated' => 'แก้ไขสิทธิ์ Instance',
        'trial.issued' => 'ออก Trial',
        'trial.exception_issued' => 'อนุมัติ Trial ข้อยกเว้น',
        'trial.production_enabled' => 'เปิด Production Trial',
        'trial.converted_to_paid' => 'แปลง Trial เป็นแพ็กเกจชำระเงิน',
    ];

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
