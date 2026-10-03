@extends('layouts.ops')
@section('title', 'ติดตามต่ออายุ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>ติดตามต่ออายุ Subscription</h1><p class="muted">เตือนก่อนสิ้นสุดรอบที่ชำระ 15 วัน · เก็บเงินและออกเอกสารในโปรแกรมบัญชีภายนอก</p></div>@include('admin.partials.navigation')</div>
    <form method="GET" class="form-section">
        <div class="form-row"><label for="group">กลุ่มงาน</label><select id="group" name="group">@foreach(['attention' => 'รายการที่ต้องติดตาม', 'all' => 'ทั้งหมด (รวมปิดใช้งาน)', 'due' => 'ใกล้ครบกำหนด', 'grace' => 'อยู่ช่วงผ่อนผัน', 'expired' => 'สิทธิ์หมดอายุ', 'PENDING' => 'รอยืนยันชำระ', 'CONFIRMED' => 'รับเงินแล้ว รอต่ออายุ', 'APPLIED' => 'นำไปใช้แล้ว', 'trial' => 'Trial (ไม่ใช่ยอดค้างชำระ)'] as $value => $label)<option value="{{ $value }}" @selected($group === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="form-row"><label for="customer">ชื่อลูกค้า</label><input id="customer" name="customer" value="{{ request('customer') }}" maxlength="100"></div>
        <div class="form-row"><label for="product">Product code</label><input id="product" name="product" value="{{ request('product') }}" maxlength="50" placeholder="MINTERP / MINTPOS / MINTHRM"></div>
        <div class="actions"><button>ค้นหา</button><a href="{{ route('admin.renewals.index') }}">ล้างตัวกรอง</a></div>
    </form>
    <p class="muted">ป้ายสิทธิ์หมดอายุไม่ใช่ยอดค้างเงิน · ผ่อนผัน 15 วันต้องบันทึกและส่งสิทธิ์จนปลายทางตอบรับก่อนวันหมดอายุเดิม</p>
    <div class="table-wrap"><table>
        <thead><tr><th>ลูกค้า / Instance</th><th>รอบและสิทธิ์ (เวลาไทย)</th><th>รายการเก็บเงิน</th><th>ผลส่งสิทธิ์</th><th>ดำเนินการ</th></tr></thead>
        <tbody>@forelse($instances as $instance)
            @php($entitlement = $instance->entitlement)
            @php($record = $instance->openRenewal ?? $instance->latestRenewal)
            @php($delivery = $instance->deliveryState())
            <tr>
                <td>{{ $instance->customer->name }}<br>{{ $instance->product->code }} · {{ $instance->name }}<br><span class="small ref">{{ $instance->instance_ref }}</span><br>{{ $entitlement->productPlan?->code }} v{{ $entitlement->productPlan?->version }}</td>
                <td><span class="status-pill warning">{{ $entitlement->renewalStage() }}</span><br>รอบที่ชำระถึง {{ $entitlement->paid_period_end?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? 'ยังไม่ยืนยันรอบ' }}<br>สิทธิ์ถึง {{ $entitlement->expires_at?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? '–' }}<br>ผ่อนผัน {{ $entitlement->grace_days }} วัน
                    @if($entitlement->expires_at && $entitlement->expires_at->gt(now()))<br>เหลือ {{ (int) ceil(now()->diffInSeconds($entitlement->expires_at) / 86400) }} วันก่อนสิทธิ์หมด@endif
                    @if($entitlement->status === 'SUSPENDED')<br><strong class="error">ระงับใช้งาน</strong>@endif
                    @if($entitlement->cancel_at_period_end)<br><strong>ยกเลิกเมื่อจบรอบ</strong>@endif
                    @if(! $instance->is_active || ! $instance->customer->is_active || ! $instance->product->is_active)<br><strong>ปิดใช้งาน</strong>@endif
                </td>
                <td>@if($record)<a href="{{ route('admin.renewals.show', $record) }}">#{{ $record->id }} · {{ \App\Models\InstanceRenewal::STATUS_LABELS[$record->status] }}</a><br>{{ \App\Models\InstanceRenewal::KIND_LABELS[$record->kind] }}<br>กำหนดชำระ {{ $record->payment_due_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}@else ยังไม่มีรายการ @endif</td>
                <td><span @class(['error' => $instance->product->code === 'MINTERP' && ! $delivery['confirmed'] && ($entitlement->paid_period_end || ($record && in_array($record->status, ['CONFIRMED', 'APPLIED'])))])>{{ $delivery['label'] }}</span></td>
                <td><a href="{{ route('admin.instances.entitlement.edit', $instance) }}">ดูสิทธิ์ / ส่งสิทธิ์</a><br><a href="{{ route('admin.renewals.create', $instance) }}">{{ $instance->openRenewal ? 'ดูรายการเปิด' : 'เปิดรายการ' }}</a></td>
            </tr>
        @empty<tr><td colspan="5">ไม่มีรายการในกลุ่มนี้</td></tr>@endforelse</tbody>
    </table></div>
    {{ $instances->links() }}
</section>
@endsection
