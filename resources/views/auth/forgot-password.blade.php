@extends('layouts.ops')
@section('title', 'ตั้งรหัสผ่านใหม่ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <h1>ตั้งรหัสผ่านใหม่</h1>
    <p class="muted">กรอกอีเมลบัญชี ระบบจะส่งลิงก์กู้รหัสผ่านให้</p>
    @if (session('status')) <p class="notice" role="status">ส่งลิงก์แล้ว หากอีเมลนี้มีบัญชีที่ได้รับเชิญ</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="form-row"><label for="email">อีเมล</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus></div>
        <div class="actions"><button type="submit">ส่งลิงก์</button><a href="{{ route('login') }}">กลับเข้าสู่ระบบ</a></div>
    </form>
</section>
@endsection
