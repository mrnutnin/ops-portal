@extends('layouts.ops')
@section('title', 'เปลี่ยนรหัสผ่าน · AlexiaSoft Ops')
@section('content')
<section class="card">
    <h1>เปลี่ยนรหัสผ่าน</h1>
    <p class="muted">รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร{{ request()->user()->must_change_password ? ' — เปลี่ยนรหัสผ่านชั่วคราวก่อนใช้งานต่อ' : '' }}</p>
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    <form method="POST" action="{{ route('account.password.update') }}">
        @csrf @method('PUT')
        <div class="form-row"><label for="current_password">รหัสผ่านปัจจุบัน</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required autofocus></div>
        <div class="form-row"><label for="password">รหัสผ่านใหม่</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
        <div class="form-row"><label for="password_confirmation">ยืนยันรหัสผ่านใหม่</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
        <button type="submit">บันทึกรหัสผ่าน</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="form-row">@csrf<button type="submit">ออกจากระบบ</button></form>
</section>
@endsection
