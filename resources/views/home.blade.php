@extends('layouts.ops')
@section('title', 'หน้าหลัก · AlexiaSoft Ops')
@section('content')
<section class="card">
    <h1>AlexiaSoft Ops</h1>
    <p>สวัสดี {{ request()->user()->name }}</p>
    <p class="muted">บัญชีนี้ยังไม่มีเมนูจัดการผลิตภัณฑ์ หากต้องการสิทธิ์ Admin ให้ติดต่อผู้ดูแลระบบ</p>
    <div class="actions">
        <a href="{{ route('account.password.edit') }}">เปลี่ยนรหัสผ่าน</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">ออกจากระบบ</button></form>
    </div>
</section>
@endsection
