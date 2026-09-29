@extends('layouts.ops')
@section('title', 'Instances · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>Instances</h1><p class="muted">เลือก Instance เพื่อดูสิทธิ์และสถานะการส่ง หรือเพิ่ม Instance ใหม่สำหรับลูกค้าและผลิตภัณฑ์ที่มีอยู่</p></div><a href="#create-instance">+ เพิ่ม Instance</a>@include('admin.partials.navigation')</div>
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif

    <h2 id="create-instance">เพิ่ม Instance</h2>
    <p class="muted">หนึ่ง Instance ผูกลูกค้าและผลิตภัณฑ์อย่างละหนึ่งรายการ; Ref สร้างอัตโนมัติและแก้ไขไม่ได้</p>
    @if ($customers->isEmpty() || $products->isEmpty())
        <p class="muted">ต้องมีลูกค้าและผลิตภัณฑ์ที่เปิดใช้งานก่อนจึงจะเพิ่ม Instance ได้</p>
    @else
        <form class="form-section" method="POST" action="{{ route('admin.instances.store') }}">
            @csrf
            <div class="form-row"><label for="name">ชื่อ Instance</label><input id="name" name="name" value="{{ old('name') }}" required>@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="customer_id">ลูกค้า</label><select id="customer_id" name="customer_id" required><option value="">เลือกลูกค้า</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }}</option>@endforeach</select>@error('customer_id')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="form-row"><label for="product_id">ผลิตภัณฑ์</label><select id="product_id" name="product_id" required><option value="">เลือกผลิตภัณฑ์</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected(old('product_id', $selectedProduct?->id) == $product->id)>{{ $product->code }} — {{ $product->name }}</option>@endforeach</select>@error('product_id')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit">เพิ่ม Instance</button>
        </form>
    @endif

    <h2>Instances {{ $selectedProduct ? 'ของ '.$selectedProduct->code : 'ทั้งหมด' }}</h2>
    @if ($selectedProduct) <p><a href="{{ route('admin.instances.index') }}">ดูทุกผลิตภัณฑ์</a></p> @endif
    <div class="table-wrap"><table>
        <thead><tr><th>ชื่อ / instance_ref</th><th>ลูกค้า</th><th>ผลิตภัณฑ์</th><th>สถานะ</th><th>การจัดการ</th></tr></thead>
        <tbody>
        @forelse ($instances as $instance)
            <tr>
                <td><form class="actions" method="POST" action="{{ route('admin.instances.update', $instance) }}">@csrf @method('PATCH')<input name="name" value="{{ $instance->name }}" aria-label="ชื่อ Instance {{ $instance->instance_ref }}" required><button type="submit">บันทึก</button></form><span class="muted small">{{ $instance->instance_ref }}</span></td>
                <td>{{ $instance->customer->name }}</td><td>{{ $instance->product->code }} — {{ $instance->product->name }}</td>
                <td><span class="status-pill {{ $instance->is_active ? '' : 'danger' }}">{{ $instance->is_active ? 'ใช้งาน' : 'ระงับ' }}</span></td>
                <td><div class="actions"><a href="{{ route('admin.instances.entitlement.edit', $instance) }}">จัดการสิทธิ์</a><form method="POST" action="{{ route('admin.instances.active', $instance) }}" @if ($instance->is_active) onsubmit="return confirm('ระงับ Instance นี้หรือไม่?')" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $instance->is_active ? 0 : 1 }}"><button type="submit">{{ $instance->is_active ? 'ระงับ' : 'เปิดใช้งาน' }}</button></form></div></td>
            </tr>
        @empty
            <tr><td colspan="5">ยังไม่มี Instance</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div>{{ $instances->links() }}</div>
</section>
@endsection
