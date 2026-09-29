@extends('layouts.ops')
@section('title', 'Plan catalog · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>Plan catalog</h1><p class="muted">เลือกผลิตภัณฑ์เพื่อดู Plan และวิธีจัดการสิทธิ์ที่แยกจากกัน</p></div>@include('admin.partials.navigation')</div>
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif

    <form method="GET" action="{{ route('admin.plans.index') }}" class="actions">
        <label for="product">ผลิตภัณฑ์</label>
        <select id="product" name="product" required>@foreach ($products as $item)<option value="{{ $item->code }}" @selected($item->id === $product->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select>
        <button type="submit">ดูผลิตภัณฑ์</button>
    </form>
    <h2>{{ $product->code }} — {{ $product->name }}</h2>
    @if (in_array($product->code, ['MINTERP', 'MINTPOS', 'MINTHRM'], true))
        <p class="muted">Core/Business ใช้ quota ผู้ใช้/สาขา/คลังแยกตาม Product; แต่ละ version แก้ไขไม่ได้ การเปลี่ยนค่าให้สร้าง version ใหม่ License ไม่ใช้ Subscription quota</p>
        @if ($product->is_active)
    <h2>เพิ่ม {{ $product->code }} plan version</h2>
    <form method="POST" action="{{ route('admin.plans.store') }}">
        @csrf
        <input type="hidden" name="product_id" value="{{ $product->id }}">
        @error('product_id')<p class="error">{{ $message }}</p>@enderror
        <div class="form-row"><label for="code">Plan code</label><input id="code" name="code" value="{{ old('code') }}" maxlength="32" pattern="[A-Za-z][A-Za-z0-9_]{1,31}" required>@error('code')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="version">Version</label><input id="version" name="version" value="{{ old('version') }}" maxlength="32" pattern="[A-Za-z0-9._-]+" required>@error('version')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="name">ชื่อ Plan</label><input id="name" name="name" value="{{ old('name') }}" required>@error('name')<p class="error">{{ $message }}</p>@enderror</div>
        <fieldset><legend>Quota เริ่มต้น</legend>
            @foreach (['quota_users' => 'ผู้ใช้งาน', 'quota_branches' => 'สาขา', 'quota_warehouses' => 'คลัง'] as $field => $label)
                <div class="form-row"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" type="number" name="{{ $field }}" value="{{ old($field) }}" min="0" max="1000000" required>@error($field)<p class="error">{{ $message }}</p>@enderror</div>
            @endforeach
        </fieldset>
        @if ($product->code === 'MINTERP')
        <fieldset><legend>โมดูลเสริมที่รวมใน Plan</legend>
            @foreach (['crm' => 'CRM', 'asset' => 'Asset'] as $module => $label)
                <label><input class="checkbox" type="checkbox" name="included_modules[]" value="{{ $module }}" @checked(in_array($module, (array) old('included_modules', []), true))> {{ $label }}</label>
            @endforeach
            @error('included_modules')<p class="error">{{ $message }}</p>@enderror
            @foreach (['included_modules.0', 'included_modules.1'] as $moduleField)
                @error($moduleField)<p class="error">{{ $message }}</p>@enderror
            @endforeach
        </fieldset>
        <p class="muted">Production เป็น add-on แยกต่อ Instance ไม่ได้รวมใน Plan defaults</p>
        @else
            <p class="muted">{{ $product->code }} ไม่มีโมดูลเสริมหรือ Production add-on; Plan นี้กำหนดเฉพาะ quota</p>
        @endif
        <button type="submit">บันทึก Plan version</button>
    </form>
        @else
            <p class="muted">ผลิตภัณฑ์ถูกระงับ ไม่สามารถสร้าง Plan version ใหม่ได้</p>
        @endif
    @else
        <p class="muted">ยังไม่ได้กำหนดรูปแบบ Plan ของผลิตภัณฑ์นี้; อย่านำ MintERP quota หรือโมดูลไปใช้</p>
    @endif

    @if (in_array($product->code, ['MINTERP', 'MINTPOS', 'MINTHRM'], true))
    <h2>Plan versions ของ {{ $product->code }}</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Plan / version</th><th>Quota เริ่มต้น</th><th>โมดูลเสริม</th><th>สถานะ</th><th>การจัดการ</th></tr></thead>
        <tbody>
        @forelse ($plans as $plan)
            <tr>
                <td>{{ $plan->name }} ({{ $plan->code }})<br><span class="muted small">{{ $plan->version }}</span></td>
                <td>ผู้ใช้ {{ $plan->entitlement_defaults['quota_users'] }} · สาขา {{ $plan->entitlement_defaults['quota_branches'] }} · คลัง {{ $plan->entitlement_defaults['quota_warehouses'] }}</td>
                <td>{{ $product->code === 'MINTERP' ? (implode(', ', $plan->entitlement_defaults['included_modules'] ?? []) ?: 'ไม่มี') : 'ไม่ใช้' }}</td>
                <td>{{ $plan->is_active ? 'ใช้งาน' : 'ปิดใช้' }}</td>
                <td><form method="POST" action="{{ route('admin.plans.active', $plan) }}" @if ($plan->is_active) onsubmit="return confirm('ปิดใช้ Plan version นี้หรือไม่?')" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $plan->is_active ? 0 : 1 }}"><button type="submit">{{ $plan->is_active ? 'ปิดใช้' : 'เปิดใช้' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5">ยังไม่มี Plan ของ {{ $product->code }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div>{{ $plans->links() }}</div>
    <p><a href="{{ route('admin.instances.index', ['product' => $product->code]) }}">จัดการสิทธิ์ Instances ของ {{ $product->code }}</a></p>
    @endif
</section>
@endsection
