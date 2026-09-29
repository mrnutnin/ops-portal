@extends('layouts.ops')
@section('title', 'ลูกค้า · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>ลูกค้า</h1><p class="muted">บันทึกองค์กรลูกค้าที่เชื่อมกับ Instance ต่าง ๆ</p></div>@include('admin.partials.navigation')</div>
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif

    <h2>เพิ่มลูกค้า</h2>
    <form method="POST" action="{{ route('admin.customers.store') }}">
        @csrf
        <div class="form-row"><label for="name">ชื่อลูกค้า</label><input id="name" name="name" value="{{ old('name') }}" required>@error('name')<p class="error">{{ $message }}</p>@enderror</div>
        <button type="submit">เพิ่มลูกค้า</button>
    </form>

    <h2>ลูกค้าทั้งหมด</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>ชื่อลูกค้า</th><th>Instances</th><th>สถานะ</th><th>การจัดการ</th></tr></thead>
        <tbody>
        @forelse ($customers as $customer)
            <tr>
                <td><form class="actions" method="POST" action="{{ route('admin.customers.update', $customer) }}">@csrf @method('PATCH')<input name="name" value="{{ $customer->name }}" aria-label="ชื่อลูกค้า {{ $customer->name }}" required><button type="submit">บันทึก</button></form></td><td>{{ $customer->instances_count }}</td>
                <td>{{ $customer->is_active ? 'ใช้งาน' : 'ระงับ' }}</td>
                <td><form method="POST" action="{{ route('admin.customers.active', $customer) }}" @if ($customer->is_active) onsubmit="return confirm('ระงับลูกค้านี้หรือไม่?')" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $customer->is_active ? 0 : 1 }}"><button type="submit">{{ $customer->is_active ? 'ระงับ' : 'เปิดใช้งาน' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="4">ยังไม่มีลูกค้า</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div>{{ $customers->links() }}</div>
</section>
@endsection
