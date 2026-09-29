@extends('layouts.ops')
@section('title', 'ยืนยันรหัสผ่าน · AlexiaSoft Ops')
@section('content')
<section class="card">
    <h1>ยืนยันรหัสผ่าน</h1>
    <p class="muted">กรอกรหัสผ่านอีกครั้งเพื่อดำเนินการต่อ</p>
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif
    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <div class="form-row"><label for="password">รหัสผ่าน</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus></div>
        <button type="submit">ยืนยันรหัสผ่าน</button>
    </form>
</section>
@endsection
