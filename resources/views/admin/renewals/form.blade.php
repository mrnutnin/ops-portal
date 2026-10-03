@extends('layouts.ops')
@section('title', 'เปิดรายการต่ออายุ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>เปิดรายการ: {{ $instance->name }}</h1><p class="muted">{{ $instance->customer->name }} · {{ $instance->product->code }} · {{ $instance->instance_ref }}</p></div>@include('admin.partials.navigation')</div>
    <a href="{{ route('admin.instances.entitlement.edit', $instance) }}">← กลับหน้าสิทธิ์ Instance</a>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p class="notice">การเปิดรายการไม่ใช่การรับชำระ และไม่เปลี่ยนสิทธิ์ ERP ตรวจรอบบริการและยอดกับโปรแกรมบัญชีภายนอก</p>
    @if($instance->entitlement)<p>เริ่มสิทธิ์เดิม {{ $instance->entitlement->starts_at?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · สิทธิ์เดิมถึง {{ $instance->entitlement->expires_at?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · รอบที่ชำระถึง {{ $instance->entitlement->paid_period_end?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? 'ยังไม่แยกรอบ' }} (เวลาไทย)</p>@endif
    <form method="POST" action="{{ route('admin.renewals.store', $instance) }}">
        @csrf @include('admin.renewals.fields')
        <button type="submit">เปิดรายการ (ยังไม่ยืนยันเงิน)</button>
    </form>
</section>
@endsection
