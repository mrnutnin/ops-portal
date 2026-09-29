@extends('layouts.ops')
@section('title', 'เข้าสู่ระบบ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <h1>AlexiaSoft Ops</h1>
    <p class="muted">เข้าสู่ระบบสำหรับทีมงาน</p>
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="form-row"><label for="email">อีเมล</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></div>
        <div class="form-row"><label for="password">รหัสผ่าน</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <div class="actions"><button type="submit">เข้าสู่ระบบ</button><a href="{{ route('password.request') }}">ลืมรหัสผ่าน</a></div>
    </form>
</section>
@endsection
