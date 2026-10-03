@extends('layouts.ops')
@section('title', 'รายการรับชำระ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>รายการ #{{ $renewal->id }} · {{ $instance->name }}</h1><p class="muted">{{ $instance->customer->name }} · {{ $instance->product->code }} · {{ $instance->instance_ref }}</p></div>@include('admin.partials.navigation')</div>
    <div class="actions"><a href="{{ route('admin.renewals.index') }}">← กลับติดตามต่ออายุ</a><a href="{{ route('admin.instances.entitlement.edit', $instance) }}">ดูสิทธิ์ / ส่งสิทธิ์ / แปลง Trial</a></div>
    @if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <div class="summary">
        <h2>{{ \App\Models\InstanceRenewal::KIND_LABELS[$renewal->kind] }}</h2>
        <p><span class="status-pill">{{ \App\Models\InstanceRenewal::STATUS_LABELS[$renewal->status] }}</span></p>
        <p>รอบบริการ {{ $renewal->period_start->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} – {{ $renewal->period_end->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} (สิ้นสุดแบบ exclusive)<br>กำหนดชำระ {{ $renewal->payment_due_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}<br>สิทธิ์ที่เสนอถึง {{ $renewal->accessEnd()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · ผ่อนผัน {{ $renewal->grace_days }} วัน (เวลาไทย)</p>
        <p>ยอดตามข้อตกลง {{ $renewal->agreed_amount }} THB · รับจริง {{ $renewal->received_amount ?? 'ยังไม่ยืนยัน' }}<br>เอกสารภายนอก {{ $renewal->external_reference ?? '–' }}<br>{{ $renewal->note }} @if($renewal->difference_reason)<br>เหตุผลยอดต่าง: {{ $renewal->difference_reason }}@endif</p>
        @if($renewal->evidence_path)<p><a href="{{ route('admin.renewals.evidence', $renewal) }}">ดาวน์โหลดหลักฐาน: {{ $renewal->evidence_name }}</a> (เฉพาะ Admin)</p>@endif
        <p>สิทธิ์ปัจจุบัน revision {{ $instance->entitlement?->source_revision }} · {{ $instance->deliveryState()['label'] }}</p>
    </div>
    @if($renewal->status === 'PENDING')
        <details class="form-section"><summary>แก้รายละเอียดรายการ / ทบทวน revision ปัจจุบัน</summary>
            <p class="muted">หากสิทธิ์เปลี่ยน ต้องตรวจสิทธิ์ปัจจุบันและรอบบริการอีกครั้งก่อนบันทึก</p>
            <form method="POST" action="{{ route('admin.renewals.update', $renewal) }}">@csrf @method('PATCH') @include('admin.renewals.fields')<button>บันทึกรายการ</button></form>
        </details>
        <h2>ยืนยันรับชำระจากโปรแกรมบัญชีภายนอก</h2>
        <p class="muted">ต้องตรวจยอดกับบัญชีจริงก่อนยืนยัน สลิปเพียงอย่างเดียวไม่ใช่การตรวจรับเงิน ขั้นนี้ยังไม่เปลี่ยนสิทธิ์ หากชำระไม่ครบให้คงรายการรอยืนยัน</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.renewals.confirm', $renewal) }}" onsubmit="return confirm('ตรวจรับเงินครบในโปรแกรมบัญชีแล้วหรือไม่? ยืนยันแล้วจะแก้ยอดและหลักฐานไม่ได้')">
            @csrf <input type="hidden" name="expected_version" value="{{ $renewal->version }}">
            <div class="form-row"><label for="received_amount">ยอดรับจริงที่จัดสรรให้ Instance นี้ (THB)</label><input id="received_amount" name="received_amount" type="number" min="0.01" max="999999999999.99" step="0.01" value="{{ old('received_amount', $renewal->agreed_amount) }}" required>@error('received_amount')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="received_at">วันที่รับเงิน · เวลาไทย</label><input id="received_at" name="received_at" type="datetime-local" value="{{ old('received_at') }}" required>@error('received_at')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="confirmation_reference">เลขอ้างอิงเอกสารบัญชี</label><input id="confirmation_reference" name="external_reference" value="{{ old('external_reference', $renewal->external_reference) }}" maxlength="255" required>@error('external_reference')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="evidence">หลักฐาน PDF/JPEG/PNG หนึ่งไฟล์ ไม่เกิน 10 MB</label><input id="evidence" name="evidence" type="file" accept="application/pdf,image/jpeg,image/png" required>@error('evidence')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="difference_reason">เหตุผลยอดรับต่างจากข้อตกลง (เช่น หัก ณ ที่จ่าย)</label><textarea id="difference_reason" name="difference_reason" maxlength="500">{{ old('difference_reason') }}</textarea>@error('difference_reason')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="confirm_reason">เหตุผลการยืนยันรับชำระ</label><textarea id="confirm_reason" name="reason" minlength="10" maxlength="500" required>{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
            <label><input class="checkbox" type="checkbox" name="settled" value="1" required @checked(old('settled'))> บัญชีภายนอกตรวจรับและเคลียร์ยอดครบแล้ว (ไม่ใช่ชำระบางส่วน)</label>@error('settled')<p class="error">{{ $message }}</p>@enderror
            <button type="submit">ยืนยันรับชำระ (ยังไม่ต่ออายุ)</button>
        </form>
    @elseif($renewal->status === 'CONFIRMED')
        @if($renewal->kind === 'TRIAL')
            <p class="notice">รับชำระรอบแรกแล้ว ให้ไปหน้า Instance และเลือกหลักฐานรายการ #{{ $renewal->id }} ในขั้นตอนแปลง Trial</p>
        @else
            <h2>ตรวจสิทธิ์ก่อนนำรอบไปใช้</h2>
            <p>วันสิ้นสุดสิทธิ์เดิม {{ $instance->entitlement?->expires_at?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} → ใหม่ {{ $renewal->accessEnd()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} (เวลาไทย)<br>คง Plan/Quota/โมดูล/Add-on เดิม ไม่ส่ง ERP อัตโนมัติ</p>
            <form method="POST" action="{{ route('admin.renewals.apply', $renewal) }}" onsubmit="return confirm('นำรอบที่ชำระไปใช้ใน Ops หรือไม่? ยังต้องส่งสิทธิ์ไป ERP แยกต่างหาก')">
                @csrf <input type="hidden" name="expected_revision" value="{{ $instance->entitlement?->source_revision }}">
                <div class="form-row"><label for="apply_reason">เหตุผลการต่ออายุ / เปิดผ่อนผัน</label><textarea id="apply_reason" name="reason" minlength="10" maxlength="500" required>{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                <button>นำรอบที่ชำระไปใช้ใน Ops</button>
            </form>
        @endif
    @elseif($renewal->status === 'APPLIED')
        <p class="notice">บันทึกใน Ops แล้ว revision {{ $renewal->applied_revision }} การส่งสิทธิ์เป็นอีกขั้นตอน หากส่งล้มเหลวไม่ต้องรับเงินหรือต่ออายุซ้ำ</p>
        <a href="{{ route('admin.instances.entitlement.edit', $instance) }}#delivery">ตรวจผลและส่งสิทธิ์ผ่านหน้า Instance</a>
    @endif
    <h2>ประวัติการดำเนินงาน</h2>
    <p>สร้างโดย {{ $renewal->creator->name }} · {{ $renewal->created_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>
    @foreach(['confirmed' => ['ยืนยันเงิน', $renewal->confirmer], 'applied' => ['นำไปใช้', $renewal->applier], 'voided' => ['ยกเลิก', $renewal->voider]] as $event => [$label, $actor])
        @if($renewal->{$event.'_at'})<p>{{ $label }} โดย {{ $actor?->name }} · {{ $renewal->{$event.'_at'}->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>@endif
    @endforeach
    <p>เหตุผล {{ $renewal->reason }} @if($renewal->void_reason)<br>เหตุผลยกเลิก {{ $renewal->void_reason }}@endif</p>
    @if(in_array($renewal->status, ['PENDING', 'CONFIRMED']))
        <details class="form-section"><summary>ยกเลิกรายการที่ยังไม่ได้นำไปใช้</summary><p>ไม่ลบหลักฐาน ไม่คืนเงิน และไม่ลดสิทธิ์; หากข้อมูลยืนยันผิดให้ยกเลิกแล้วสร้างใหม่</p>
            <form method="POST" action="{{ route('admin.renewals.void', $renewal) }}" onsubmit="return confirm('ยกเลิกรายการนี้โดยเก็บประวัติและหลักฐานหรือไม่?')">@csrf<div class="form-row"><label for="void_reason">เหตุผลยกเลิก</label><textarea id="void_reason" name="reason" minlength="10" maxlength="500" required></textarea></div><button>ยกเลิกรายการ</button></form>
        </details>
    @endif
</section>
@endsection
