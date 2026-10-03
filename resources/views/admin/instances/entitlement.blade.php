@extends('layouts.ops')
@section('title', 'สิทธิ์ Instance · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>สิทธิ์ Instance: {{ $instance->name }}</h1><p class="muted">{{ $instance->customer->name }} · {{ $instance->product->code }}<br><span class="small ref">Ref: {{ $instance->instance_ref }}</span></p></div><a href="{{ route('admin.instances.index') }}">← กลับรายการ Instances</a>@include('admin.partials.navigation')</div>
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif

    @if ($entitlement)
        @php($subscriptionInactive = $entitlement->commercial_mode === 'SUBSCRIPTION' && $entitlement->status !== 'SUSPENDED' && ($entitlement->starts_at?->gt(now()) || $entitlement->expires_at?->lte(now())))
        <div class="summary" aria-label="สิทธิ์ปัจจุบัน">
            <h2>สิทธิ์ปัจจุบัน</h2>
            <p><span class="status-pill {{ $entitlement->status === 'SUSPENDED' ? 'danger' : ($subscriptionInactive ? 'warning' : '') }}">{{ $subscriptionInactive ? ($entitlement->starts_at?->gt(now()) ? 'ยังไม่เริ่ม' : 'หมดอายุ') : (['ACTIVE' => 'ใช้งาน', 'SUSPENDED' => 'ระงับ', 'TRIAL' => 'ทดลองใช้'][$entitlement->status] ?? $entitlement->status) }}</span> · {{ ['SUBSCRIPTION' => 'Subscription', 'LICENSE' => 'License'][$entitlement->commercial_mode] ?? $entitlement->commercial_mode }} · Revision {{ $entitlement->source_revision }}</p>
            @if ($entitlement->commercial_mode === 'SUBSCRIPTION')
                <p>Plan {{ $entitlement->productPlan?->code }} v{{ $entitlement->productPlan?->version }} · หมดอายุ {{ $entitlement->expires_at?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น. (เวลาไทย)</p>
                @if ($hasQuotaPlans)
                    @php($effective = $entitlement->effectiveValues())
                    <ul class="summary-list" aria-label="โควตาและโมดูล">
                        <li>ผู้ใช้ {{ $effective['quota_users'] ?? '–' }}</li><li>สาขา {{ $effective['quota_branches'] ?? '–' }}</li><li>คลัง {{ $effective['quota_warehouses'] ?? '–' }}</li>
                        @if ($isMintErp)<li>โมดูล {{ implode(', ', $effective['included_modules'] ?? []) ?: 'ไม่มีโมดูลเสริม' }}</li><li>Production {{ $entitlement->production_addon ? 'เปิด' : 'ปิด' }}</li>@endif
                    </ul>
                @endif
            @endif
        </div>
        @if ($entitlement->status === 'SUSPENDED')
            <p class="notice">สิทธิ์ถูกระงับ ERP จะไม่อนุญาตให้อ่านหรือแก้ไขข้อมูลธุรกิจ ต้องเปลี่ยนเป็นใช้งานและส่งสิทธิ์อีกครั้งหลังได้รับอนุมัติ</p>
        @elseif ($subscriptionInactive && $entitlement->starts_at?->gt(now()))
            <p class="notice">Subscription ยังไม่เริ่ม ERP จะอ่านอย่างเดียวจนถึง {{ $entitlement->starts_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น. (เวลาไทย) ตรวจเวลาเริ่มก่อนส่งสิทธิ์</p>
        @elseif ($subscriptionInactive)
            <p class="notice">Subscription หมดอายุแล้ว ERP จะอ่านอย่างเดียว กรุณากำหนดรอบใหม่และส่งสิทธิ์</p>
        @endif
    @else
        <p class="notice">ยังไม่ได้กำหนดสิทธิ์ให้ Instance นี้ ขั้นถัดไปคือออก Trial หรือกำหนด Subscription/License</p>
    @endif

    <h2>รอบบริการ / รับชำระภายนอก</h2>
    @if($entitlement?->commercial_mode === 'SUBSCRIPTION')
        <p><a href="{{ route('admin.renewals.create', $instance) }}">เปิดหรือดูรายการรับชำระ / ต่ออายุ / เปิดผ่อนผันรอบเดิม</a></p>
        <p>{{ $entitlement->renewalStage() }} · รอบที่ชำระถึง {{ $entitlement->paid_period_end?->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? 'ยังไม่แยกรอบบริการ' }} · ผ่อนผัน {{ $entitlement->grace_days }} วัน (เวลาไทย)</p>
        <p class="muted">วันสิ้นสุดสิทธิ์ด้านบนรวมผ่อนผันแล้ว ไม่ใช่วันสิ้นสุดรอบที่ชำระ; ต้องส่งสิทธิ์ให้ ERP ตอบรับก่อนหมดอายุเดิม</p>
    @endif
    <div class="table-wrap"><table><thead><tr><th>รายการ</th><th>รอบบริการ (เวลาไทย)</th><th>ยอดรับ / สถานะ</th></tr></thead><tbody>
        @forelse($renewalHistory as $record)<tr><td><a href="{{ route('admin.renewals.show', $record) }}">#{{ $record->id }} · {{ \App\Models\InstanceRenewal::KIND_LABELS[$record->kind] }}</a></td><td>{{ $record->period_start->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} – {{ $record->period_end->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td><td>{{ $record->received_amount ?? '–' }} THB · {{ \App\Models\InstanceRenewal::STATUS_LABELS[$record->status] }}</td></tr>@empty<tr><td colspan="3">ยังไม่มีรายการรับชำระ</td></tr>@endforelse
    </tbody></table></div>
    {{ $renewalHistory->links() }}

    @if ($isMintErp)
        <h2 id="delivery">สถานะการส่งสิทธิ์ไป ERP</h2>
        @if ($syncCredential)
            <p class="muted ref">ปลายทาง API ปัจจุบัน: {{ $syncCredential->target_url }}/api/v1/entitlements/current</p>
        @endif
        @if (! $syncCredential)
            <p class="muted">ยังไม่ผูกปลายทางและกุญแจ; ข้อมูลหน้านี้ยังอยู่ใน Ops เท่านั้น</p>
            @if (app()->environment('local') && in_array(request()->ip(), ['127.0.0.1', '::1'], true) && in_array(request()->getHost(), ['127.0.0.1', 'localhost'], true) || request()->isSecure())
                <h3>ผูก ERP ครั้งแรก</h3>
                <p class="muted">ให้ ERP Admin เปิดหน้า <code>/ops-pairing</code> บน ERP ของ Instance นี้ คัดลอก ref จากหน้านี้ไป ERP แล้วนำ Key ID/Secret ที่แสดงครั้งเดียวมากรอกด้านล่าง ไม่ส่งสิทธิ์อัตโนมัติ</p>
                <form method="POST" action="{{ route(app('router')->has('admin.instances.enrollment') ? 'admin.instances.enrollment' : 'admin.instances.local-enrollment', $instance) }}" autocomplete="off">
                    @csrf
                    <div class="form-row"><label for="local_target_url">ERP endpoint (HTTPS หรือ loopback เฉพาะ local)</label><input id="local_target_url" type="url" name="target_url" value="{{ old('target_url', app()->environment('local') ? 'http://127.0.0.1:8000' : '') }}" required maxlength="255">@error('target_url')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="form-row"><label for="local_key_id">Key ID จาก ERP</label><input id="local_key_id" name="key_id" value="{{ old('key_id') }}" required maxlength="64">@error('key_id')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="form-row"><label for="local_secret">Secret จาก ERP (แสดงครั้งเดียว)</label><input id="local_secret" type="password" name="secret" required minlength="64" maxlength="64" autocomplete="off" spellcheck="false">@error('secret')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="form-row"><label for="enroll_reason">เหตุผลการผูกปลายทาง</label><textarea id="enroll_reason" name="reason" required minlength="10" maxlength="500">{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                    <button type="submit">ผูกปลายทาง (ยังไม่ส่ง)</button>
                </form>
            @endif
        @elseif (! $lastDelivery)
            <p class="muted">ผูก Instance แล้ว แต่ยังไม่มีผลการส่ง กรุณาตรวจ snapshot และสั่งส่งแบบ manual</p>
        @elseif ($lastDelivery->action === 'instance.sync.target_changed')
            @unless (session('status'))<p class="notice" role="status">เปลี่ยนปลายทางแล้ว แต่ ERP ที่ปลายทางใหม่ยังไม่ได้ตอบรับ กรุณาตรวจปลายทางและสั่งส่งสิทธิ์</p>@endunless
        @elseif ($lastDelivery->action === 'instance.sync.delivery_failed')
            <p class="error" role="status">ส่งครั้งล่าสุดไม่สำเร็จ ({{ $lastDelivery->created_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น. เวลาไทย, HTTP {{ $lastDelivery->new_values['http_status'] ?? 'เชื่อมต่อไม่ได้' }}) กรุณาตรวจสอบประวัติและแก้ปัญหาก่อนส่งซ้ำ</p>
        @elseif (($lastDelivery->new_values['target_url'] ?? null) !== $syncCredential->target_url)
            <p class="notice" role="status">โดเมนปัจจุบันยังไม่ได้รับการตอบรับจาก ERP; กรุณาตรวจและสั่ง push แบบ manual</p>
        @elseif ($syncCredential->previous_key_id && ($lastDelivery->new_values['key_id'] ?? null) !== $syncCredential->key_id)
            <p class="notice" role="status">ยังส่งด้วย key เดิม; การหมุนเวียน key ยังไม่ผ่าน กรุณาตรวจ ERP key ใหม่และ push อีกครั้งก่อนเลิกใช้ key เดิม</p>
        @elseif (($lastDelivery->new_values['source_revision'] ?? null) !== $entitlement?->source_revision)
            <p class="notice" role="status">มีสิทธิ์ Revision {{ $entitlement?->source_revision }} ที่ยังไม่ได้ส่ง; ERP เคยตอบรับ Revision {{ $lastDelivery->new_values['source_revision'] ?? '-' }}</p>
        @else
            @unless (session('status'))<p class="notice" role="status">ERP ตอบรับ Revision {{ $lastDelivery->new_values['source_revision'] }} ครั้งล่าสุดเมื่อ {{ $lastDelivery->created_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น. (เวลาไทย) (key {{ $lastDelivery->new_values['key_id'] ?? '-' }}) หากมีการแก้ไขใน ERP โดยตรงต้อง push เพื่อตรวจสอบอีกครั้ง</p>@endunless
        @endif
        @if ($syncCredential && $entitlement && (app()->environment('local') && in_array(request()->ip(), ['127.0.0.1', '::1'], true) && in_array(request()->getHost(), ['127.0.0.1', 'localhost'], true) || request()->isSecure()))
            <form method="POST" action="{{ route(app('router')->has('admin.instances.push') ? 'admin.instances.push' : 'admin.instances.local-push', $instance) }}" onsubmit="return confirm('ส่ง snapshot ของ Instance นี้ไป ERP หรือไม่? สิทธิ์ ERP ปัจจุบันอาจเปลี่ยนทันที')">
                @csrf
                <p class="muted">ส่ง {{ $entitlement->commercial_mode }} · {{ $entitlement->status }} · Revision {{ $entitlement->source_revision }} ไป {{ $syncCredential->target_url }}; การส่งซ้ำ revision เดิมไม่แก้ ERP อีกครั้ง เมื่อ ERP receiver เปิดแล้วเท่านั้น</p>
                <div class="form-row form-section"><label for="push_reason">เหตุผลการส่งสิทธิ์</label><textarea id="push_reason" name="reason" required minlength="10" maxlength="500">{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                <button type="submit">ส่งสิทธิ์ไป ERP</button>
            </form>
        @endif
        @if ($syncCredential)
            <details class="form-section" @if($errors->has('target_url') || $errors->has('target_change_reason')) open @endif>
                <summary>เปลี่ยนปลายทาง API (ไม่ส่งสิทธิ์อัตโนมัติ)</summary>
                <form method="POST" action="{{ route('admin.instances.delivery-target.update', $instance) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="expected_target_url" value="{{ $syncCredential->target_url }}">
                    <div class="form-row"><label for="delivery_target_url">ปลายทาง API ใหม่</label><input id="delivery_target_url" type="url" name="target_url" value="{{ old('target_url', $syncCredential->target_url) }}" maxlength="255" required placeholder="https://erp.example.com">@error('target_url')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="form-row"><label for="delivery_target_reason">เหตุผลการเปลี่ยนปลายทาง</label><textarea id="delivery_target_reason" name="target_change_reason" rows="2" required minlength="10" maxlength="500">{{ old('target_change_reason') }}</textarea>@error('target_change_reason')<p class="error">{{ $message }}</p>@enderror</div>
                    <p class="muted">Production ใช้เฉพาะโดเมน HTTPS ใน allowlist ที่ชี้ไป Instance เดิม; บนเครื่อง local เท่านั้นใช้ http://127.0.0.1:8000 หรือ http://localhost:8000 ได้ การบันทึกไม่ส่งสิทธิ์อัตโนมัติ; ตรวจผลแล้วสั่ง push แยกต่างหาก</p>
                    <button type="submit">เปลี่ยนปลายทาง (ยังไม่ส่ง)</button>
                </form>
            </details>
        @endif
    @endif

    @if ($isMintErp && ! $entitlement && ! $trialIssued && $instance->is_active && $instance->customer->is_active && $instance->product->is_active && $businessPlans->isNotEmpty())
        <h2>ออก Trial 30 วัน</h2>
        <p class="muted">เริ่มนับทันทีเมื่อพร้อมส่งมอบ ใช้ Business quota/โมดูลเดิม และหมดอายุใน 30 วัน ไม่ต่ออายุหรือแปลงเป็นแพ็กเกจเสียเงินอัตโนมัติ; Trial ปกติหนึ่งครั้งต่อลูกค้าและผลิตภัณฑ์ ผูกกับ Instance นี้</p>
        <form method="POST" action="{{ route('admin.instances.trial.store', $instance) }}" onsubmit="return confirm('ออก Trial 30 วันให้ Instance นี้ทันทีหรือไม่?')">
            @csrf
            <div class="form-row"><label for="trial_plan">Business Plan version</label><select id="trial_plan" name="product_plan_id" required><option value="">เลือก version</option>@foreach ($businessPlans as $plan)<option value="{{ $plan->id }}" @selected(old('product_plan_id') == $plan->id)>{{ $plan->name }} v{{ $plan->version }}</option>@endforeach</select>@error('product_plan_id')<p class="error">{{ $message }}</p>@enderror</div>
            <label><input class="checkbox" type="checkbox" name="exception_approved" value="1" @checked(old('exception_approved'))> ออก Trial แบบข้อยกเว้น (ลูกค้าเคยได้รับ Trial ของ MintERP แล้ว)</label>
            @error('exception_approved')<p class="error">{{ $message }}</p>@enderror
            <label><input class="checkbox" type="checkbox" name="production_addon" value="1" @checked(old('production_addon'))> รวม Production add-on ใน Trial</label>
            <p class="muted">หากรวม Production ให้ตรวจ Manufacturing/Production readiness ก่อนออก Trial; การกดออก Trial จะเริ่มนับ 30 วันทันที</p>
            <div class="form-row"><label for="trial_reason">เหตุผลการอนุมัติ Trial / ข้อยกเว้น</label><textarea id="trial_reason" name="reason" rows="3" required minlength="10" maxlength="500">{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit">ออก Trial 30 วัน</button>
        </form>
    @endif

    @if ($trialIssued && (! $entitlement || $entitlement->status === 'TRIAL'))
        <p class="notice">Instance นี้เคยได้รับ Trial แล้ว ไม่สามารถออกซ้ำหรือแก้ผ่านฟอร์มสิทธิ์ทั่วไปได้; ต้องใช้ขั้นตอนเฉพาะด้านล่าง</p>
        @if ($entitlement && $instance->is_active && $instance->customer->is_active && $instance->product->is_active)
            @if (! $entitlement->production_addon && $entitlement->starts_at?->lte(now()) && $entitlement->expires_at?->gt(now()))
                <h2>เปิด Production ระหว่าง Trial</h2>
                <p class="muted">ตรวจ Manufacturing/Production readiness ก่อนเปิดใช้งาน; ใช้ Business Trial เดิม ไม่เริ่ม Trial ใหม่และไม่เปลี่ยนวันหมดอายุ {{ $entitlement->expires_at->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น. (เวลาไทย)</p>
                <form method="POST" action="{{ route('admin.instances.trial.production', $instance) }}" onsubmit="return confirm('เปิด Production Trial โดยคงวันหมดอายุเดิมหรือไม่?')">
                    @csrf
                    <div class="form-row"><label for="production_reason">เหตุผลการเปิด Production Trial</label><textarea id="production_reason" name="reason" rows="3" required minlength="10" maxlength="500">{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                    <button type="submit">เปิด Production Trial</button>
                </form>
            @endif
            <h2>แปลง Trial เป็นแพ็กเกจชำระเงิน</h2>
            <p class="muted">ทำได้เมื่อทีมงานยืนยันสัญญาชำระเงินแล้วเท่านั้น; เริ่มรอบชำระเงินทันทีที่กดบันทึก Quota และโมดูลใช้ค่าเริ่มต้นของ Plan ที่เลือก (ไม่สืบทอด override) และสิทธิ์ Production ที่เปิดใน Trial จะคงอยู่ ไม่มีการเรียกเก็บเงินอัตโนมัติ ตรวจจำนวนผู้ใช้/สาขา/คลังจริงใน ERP ก่อนเลือก Plan เพื่อไม่ให้เกิน quota</p>
            <form method="POST" action="{{ route('admin.instances.trial.convert', $instance) }}" onsubmit="return confirm('แปลง Trial เป็นแพ็กเกจชำระเงินตาม Plan และวันหมดอายุที่ระบุหรือไม่?')">
                @csrf
                <div class="form-row"><label for="paid_plan">Plan ชำระเงิน</label><select id="paid_plan" name="product_plan_id" required><option value="">เลือก Plan</option>@foreach ($paidPlans as $plan)<option value="{{ $plan->id }}" @selected(old('product_plan_id') == $plan->id)>{{ $plan->code }} v{{ $plan->version }} · ผู้ใช้ {{ $plan->entitlement_defaults['quota_users'] }} สาขา {{ $plan->entitlement_defaults['quota_branches'] }} คลัง {{ $plan->entitlement_defaults['quota_warehouses'] }}</option>@endforeach</select>@error('product_plan_id')<p class="error">{{ $message }}</p>@enderror</div>
                <p class="muted">ต้องยืนยันเงินและหลักฐานผ่านรายการรับชำระรอบแรกก่อนแปลง Trial; รอบแรกไม่มีผ่อนผัน</p>
                <div class="form-row"><label for="renewal_id">รายการรับชำระรอบแรกที่ยืนยันแล้ว</label><select id="renewal_id" name="renewal_id" required><option value="">เลือกหลักฐาน</option>@if($trialPayment)<option value="{{ $trialPayment->id }}">#{{ $trialPayment->id }} · {{ $trialPayment->external_reference }} · {{ $trialPayment->received_amount }} THB</option>@endif</select>@error('renewal_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="form-row"><label for="paid_expires_at">วันหมดอายุรอบชำระเงิน (เวลาไทย)</label><input id="paid_expires_at" type="datetime-local" name="paid_expires_at" value="{{ old('paid_expires_at') }}" required>@error('paid_expires_at')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="form-row"><label for="paid_reason">เหตุผลการแปลง Trial</label><textarea id="paid_reason" name="reason" rows="3" required minlength="10" maxlength="500">{{ old('reason') }}</textarea>@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                <button type="submit">แปลง Trial เป็นแพ็กเกจชำระเงิน</button>
            </form>
        @endif
    @else
    <h2>กำหนดหรือแก้ไขสิทธิ์</h2>
    <form class="form-section" method="POST" action="{{ route('admin.instances.entitlement.save', $instance) }}">
        @csrf @method('PUT')
        <div class="form-row"><label for="commercial_mode">Commercial mode</label><select id="commercial_mode" name="commercial_mode" required><option value="">เลือก mode</option>@foreach (['SUBSCRIPTION' => 'Subscription', 'LICENSE' => 'License'] as $mode => $label)<option value="{{ $mode }}" @selected(old('commercial_mode', $entitlement?->commercial_mode) === $mode)>{{ $label }}</option>@endforeach</select>@error('commercial_mode')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="status">สถานะ</label><select id="status" name="status" required>@foreach (['ACTIVE' => 'ใช้งาน', 'SUSPENDED' => 'ระงับ'] as $value => $label)<option value="{{ $value }}" @selected(old('status', $entitlement?->status ?? 'ACTIVE') === $value)>{{ $label }}</option>@endforeach</select>@error('status')<p class="error">{{ $message }}</p>@enderror</div>
        <p class="muted">License ไม่มี Plan/ช่วงวันใช้งานใน Ops; Subscription ต้องระบุวันเริ่มและหมดอายุ</p>

        <p class="muted">กรอกเวลาไทย (UTC+7) · ณ เวลาเปิดหน้านี้ {{ now('Asia/Bangkok')->format('d/m/Y H:i') }} น. ถ้าวันเริ่มอยู่ในอนาคต ERP จะอ่านอย่างเดียวจนกว่าจะถึงเวลาเริ่ม การส่ง revision เดิมซ้ำไม่เปลี่ยนช่วงวัน</p>
        <div class="form-row"><label for="starts_at">เริ่มใช้ (Subscription · เวลาไทย)</label><input id="starts_at" type="datetime-local" name="starts_at" value="{{ old('starts_at', $entitlement?->starts_at?->copy()->timezone('Asia/Bangkok')->format('Y-m-d\TH:i')) }}">@error('starts_at')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="expires_at">หมดอายุ (Subscription · เวลาไทย)</label><input id="expires_at" type="datetime-local" name="expires_at" value="{{ old('expires_at', $entitlement?->expires_at?->copy()->timezone('Asia/Bangkok')->format('Y-m-d\TH:i')) }}">@error('expires_at')<p class="error">{{ $message }}</p>@enderror</div>

        @if ($hasQuotaPlans)
            <fieldset><legend>{{ $instance->product->code }} subscription (ใช้เมื่อเลือก SUBSCRIPTION)</legend>
                <div class="form-row"><label for="product_plan_id">Plan version</label><select id="product_plan_id" name="product_plan_id"><option value="">เลือก Plan</option>@foreach ($plans as $plan)<option value="{{ $plan->id }}" @selected((string) old('product_plan_id', $entitlement?->product_plan_id) === (string) $plan->id)>{{ $plan->code }} · {{ $plan->name }} v{{ $plan->version }}{{ $plan->is_active ? '' : ' (ปิดรับใหม่)' }}</option>@endforeach</select>@error('product_plan_id')<p class="error">{{ $message }}</p>@enderror</div>
                @php($quotaOverrides = $entitlement?->overrides ?? [])
                @foreach (['quota_users' => 'ผู้ใช้งาน', 'quota_branches' => 'สาขา', 'quota_warehouses' => 'คลัง'] as $field => $label)
                    <div class="form-row"><label for="{{ $field }}">{{ $label }} override (เว้นว่างใช้ค่า Plan)</label><input id="{{ $field }}" type="number" name="quota_overrides[{{ $field }}]" value="{{ old('quota_overrides.'.$field, $quotaOverrides[$field] ?? '') }}" min="0" max="1000000">@error('quota_overrides.'.$field)<p class="error">{{ $message }}</p>@enderror</div>
                @endforeach
                @if ($isMintErp)
                <label><input class="checkbox" type="checkbox" name="customize_modules" value="1" @checked((bool) old('customize_modules', array_key_exists('included_modules', $quotaOverrides)))> กำหนดโมดูลแทนค่า Plan</label>
                <div class="form-row">
                    @php($selectedModules = (array) old('included_modules', $quotaOverrides['included_modules'] ?? []))
                    @foreach (['crm' => 'CRM', 'asset' => 'Asset'] as $module => $label)
                        <label><input class="checkbox" type="checkbox" name="included_modules[]" value="{{ $module }}" @checked(in_array($module, $selectedModules, true))> {{ $label }}</label>
                    @endforeach
                    @error('included_modules')<p class="error">{{ $message }}</p>@enderror
                    @foreach (['included_modules.0', 'included_modules.1'] as $moduleField) @error($moduleField)<p class="error">{{ $message }}</p>@enderror @endforeach
                </div>
                <label><input class="checkbox" type="checkbox" name="production_addon" value="1" @checked((bool) old('production_addon', $entitlement?->production_addon ?? false))> เปิด Production add-on</label>
                <p class="muted">หากเปิด Production add-on ให้ตรวจ Manufacturing/Production readiness ก่อนบันทึกสิทธิ์</p>
                @else
                    <p class="muted">ไม่มีโมดูลเสริมหรือ Production add-on สำหรับผลิตภัณฑ์นี้</p>
                @endif
            </fieldset>
        @else
            <p class="muted">ยังไม่ได้กำหนด Plan quota ของผลิตภัณฑ์นี้</p>
        @endif

        <label><input class="checkbox" type="checkbox" name="cancel_at_period_end" value="1" @checked((bool) old('cancel_at_period_end', $entitlement?->cancel_at_period_end ?? false))> ยกเลิก Subscription เมื่อสิ้นสุดรอบที่ชำระ</label>
        @if($entitlement?->paid_period_end)
            <p class="muted">กำหนดยกเลิกจะตัดผ่อนผันและตั้งวันสิ้นสุดสิทธิ์ตรงรอบที่ชำระ {{ $entitlement->paid_period_end->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} เวลาไทย; ต้องส่งสิทธิ์แยก การแก้วันแบบข้อยกเว้นจะล้างข้อมูลรอบที่ชำระและคงประวัติเดิม</p>
            <label><input class="checkbox" type="checkbox" name="reset_billing" value="1" @checked(old('reset_billing'))> ยืนยันล้างข้อมูลรอบที่ชำระ หากแก้วันเริ่ม/หมดอายุแบบข้อยกเว้น (ไม่ใช่การต่ออายุจากการรับเงิน)</label>
        @endif
        <div class="form-row"><label for="change_reason">เหตุผลการเปลี่ยนแปลง</label><textarea id="change_reason" name="change_reason" rows="3" required>{{ old('change_reason') }}</textarea>@error('change_reason')<p class="error">{{ $message }}</p>@enderror</div>
        <p class="muted">การบันทึกนี้เก็บสถานะใน Ops และเพิ่ม Revision; ต้องส่งไป ERP ด้วยคำสั่งของผู้ดูแลระบบแยกต่างหาก</p>
        <button type="submit">บันทึกสิทธิ์ Instance</button>
    </form>
    @endif

    <h2>ประวัติ Trial ของลูกค้าและผลิตภัณฑ์นี้</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Instance</th><th>ช่วง Trial (เวลาไทย)</th><th>ประเภท / ผู้อนุมัติ</th><th>เหตุผล</th></tr></thead>
        <tbody>
            @forelse ($trialHistory as $trial)
                <tr><td>{{ $trial->instance_name }}</td><td>{{ \Carbon\Carbon::parse($trial->starts_at, 'UTC')->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} – {{ \Carbon\Carbon::parse($trial->expires_at, 'UTC')->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td><td>{{ $trial->is_exception ? 'ข้อยกเว้น' : 'ปกติ' }} · {{ $trial->actor_name }}{{ $trial->production_addon ? ' · Production ตอนออก Trial' : '' }}</td><td>{{ $trial->reason }}</td></tr>
            @empty
                <tr><td colspan="4">ยังไม่มีประวัติ Trial</td></tr>
            @endforelse
        </tbody>
    </table></div>
    {{ $trialHistory->links() }}
</section>
@endsection
