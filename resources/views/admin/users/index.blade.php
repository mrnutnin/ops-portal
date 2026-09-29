@extends('layouts.ops')
@section('title', 'บัญชีผู้ใช้ · AlexiaSoft Ops')
@section('content')
<section class="card">
    <div class="actions spread">
        <div><h1>บัญชีผู้ใช้</h1><p class="muted">Admin จัดการบัญชีและสิทธิ์ของทีมงาน</p></div>
        @include('admin.partials.navigation')
    </div>
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <p class="error" role="alert">{{ $errors->first() }}</p> @endif

    <h2>สร้างบัญชี</h2>
    <p class="muted">กำหนดรหัสผ่านชั่วคราวและแจ้งผู้ใช้ผ่านช่องทางที่ปลอดภัย ระบบจะบังคับเปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบครั้งแรก</p>
    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf
        <div class="form-row"><label for="name">ชื่อ</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>@error('name')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="email">อีเมลสำหรับเข้าสู่ระบบ</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>@error('email')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="password">รหัสผ่านชั่วคราว (อย่างน้อย 12 ตัวอักษร)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>@error('password')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label for="password_confirmation">ยืนยันรหัสผ่านชั่วคราว</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
        <div class="form-row"><input type="hidden" name="is_admin" value="0"><label><input type="checkbox" name="is_admin" value="1" @checked(old('is_admin')) class="checkbox"> ให้สิทธิ์ Admin</label></div>
        <button type="submit">สร้างบัญชี</button>
    </form>

    <h2>บัญชีทั้งหมด</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>ชื่อ / อีเมล</th><th>สิทธิ์</th><th>สถานะ</th><th>การจัดการ</th></tr></thead>
        <tbody>
        @forelse ($users as $user)
            <tr>
                <td>{{ $user->name }}<br><span class="muted small">{{ $user->email }}</span></td>
                <td>{{ $user->is_admin ? 'Admin' : 'User' }}</td>
                <td>{{ ! $user->is_active ? 'ระงับ' : ($user->must_change_password ? 'รอเปลี่ยนรหัสผ่าน' : 'ใช้งาน') }}</td>
                <td>
                    @if (! request()->user()->is($user))
                        <form method="POST" action="{{ route('admin.users.active', $user) }}" @if ($user->is_active) onsubmit="return confirm('ระงับบัญชีนี้ใช่หรือไม่?')" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}"><button type="submit">{{ $user->is_active ? 'ระงับบัญชี' : 'เปิดใช้งาน' }}</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4">ยังไม่มีบัญชี</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div>{{ $users->links() }}</div>
</section>
@endsection
