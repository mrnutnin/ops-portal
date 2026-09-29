@extends('layouts.ops')
@section('title', 'ผลิตภัณฑ์ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread"><div><h1>ผลิตภัณฑ์</h1><p class="muted">รหัสผลิตภัณฑ์แยกจากโมดูลภายใน MintERP</p></div>@include('admin.partials.navigation')</div>
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif

    <h2>เพิ่มผลิตภัณฑ์</h2>
    <form method="POST" action="{{ route('admin.products.store') }}">
        @csrf
        <div class="form-row"><label for="code">Product code</label><input id="code" name="code" value="{{ old('code') }}" maxlength="32" pattern="[A-Za-z][A-Za-z0-9_]{1,31}" required>@error('code')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="name">ชื่อผลิตภัณฑ์</label><input id="name" name="name" value="{{ old('name') }}" required>@error('name')<p class="error">{{ $message }}</p>@enderror</div>
        <button type="submit">เพิ่มผลิตภัณฑ์</button>
    </form>

    <h2>ผลิตภัณฑ์ทั้งหมด</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Product code</th><th>ชื่อ</th><th>Instances</th><th>สถานะ</th><th>การจัดการ</th></tr></thead>
        <tbody>
        @forelse ($products as $product)
            <tr>
                <td>{{ $product->code }}</td><td><form class="actions" method="POST" action="{{ route('admin.products.update', $product) }}">@csrf @method('PATCH')<input name="name" value="{{ $product->name }}" aria-label="ชื่อผลิตภัณฑ์ {{ $product->code }}" required><button type="submit">บันทึก</button></form></td><td>{{ $product->instances_count }}</td>
                <td>{{ $product->is_active ? 'ใช้งาน' : 'ระงับ' }}</td>
                <td><form method="POST" action="{{ route('admin.products.active', $product) }}" @if ($product->is_active) onsubmit="return confirm('ระงับผลิตภัณฑ์นี้หรือไม่?')" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $product->is_active ? 0 : 1 }}"><button type="submit">{{ $product->is_active ? 'ระงับ' : 'เปิดใช้งาน' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5">ยังไม่มีผลิตภัณฑ์</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div>{{ $products->links() }}</div>
</section>
@endsection
