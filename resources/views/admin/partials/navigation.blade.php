<nav class="actions" aria-label="เมนู Admin">
    <a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>บัญชีผู้ใช้</a>
    <a href="{{ route('admin.customers.index') }}" @if(request()->routeIs('admin.customers.*')) aria-current="page" @endif>ลูกค้า</a>
    <a href="{{ route('admin.products.index') }}" @if(request()->routeIs('admin.products.*')) aria-current="page" @endif>ผลิตภัณฑ์</a>
    <a href="{{ route('admin.instances.index') }}" @if(request()->routeIs('admin.instances.*')) aria-current="page" @endif>Instances</a>
    <a href="{{ route('admin.renewals.index') }}" @if(request()->routeIs('admin.renewals.*')) aria-current="page" @endif>ติดตามต่ออายุ</a>
    <a href="{{ route('admin.plans.index') }}" @if(request()->routeIs('admin.plans.*')) aria-current="page" @endif>Plans</a>
    <a href="{{ route('admin.audit.index') }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>ประวัติ</a>
    <a href="{{ route('account.password.edit') }}" @if(request()->routeIs('account.password.*')) aria-current="page" @endif>เปลี่ยนรหัสผ่าน</a>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">ออกจากระบบ</button></form>
</nav>
