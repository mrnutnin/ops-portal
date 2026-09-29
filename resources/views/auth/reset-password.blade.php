@extends('layouts.ops')
@section('title', 'กำหนดรหัสผ่าน · AlexiaSoft Ops')
@section('content')
<section class="card">
    <h1>กำหนดรหัสผ่าน</h1>
    <p class="muted">รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร</p>
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div class="form-row"><label for="email">อีเมล</label><input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" autocomplete="email" required></div>
        <div class="form-row"><label for="password">รหัสผ่านใหม่</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
        <div class="form-row"><label for="password_confirmation">ยืนยันรหัสผ่าน</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
        <button type="submit">บันทึกรหัสผ่าน</button>
    </form>
</section>
@endsection
