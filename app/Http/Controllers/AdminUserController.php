<?php

namespace App\Http\Controllers;

use App\Models\OpsAuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function auditIndex(): View
    {
        return view('admin.audit.index', [
            'events' => OpsAuditLog::query()->with('actor:id,name,email')
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(50),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $emailInput = $request->input('email');
        if (is_string($emailInput)) {
            $request->merge(['email' => Str::lower(trim($emailInput))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::default(), 'confirmed'],
            'is_admin' => ['required', 'boolean'],
        ]);
        $isAdmin = (bool) $data['is_admin'];

        DB::transaction(function () use ($request, $data, $isAdmin): void {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'email_verified_at' => now(),
                'password' => $data['password'],
                'is_active' => true,
                'is_admin' => $isAdmin,
                'must_change_password' => true,
            ]);
            $this->record($request, 'admin.user.created', $user, [], [
                'is_admin' => $isAdmin,
                'is_active' => true,
                'must_change_password' => true,
            ]);
        });

        return back()->with('status', 'สร้างบัญชีแล้ว แจ้งรหัสชั่วคราวให้ผู้ใช้ผ่านช่องทางที่ปลอดภัย');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];

        $status = DB::transaction(function () use ($request, $user, $active): string {
            // ponytail: lock all staff rows for rare account-status writes; use finer locks only if staff volume warrants it.
            $accounts = User::query()->orderBy('id')->lockForUpdate()->get();
            $actor = $accounts->firstWhere('id', $request->user()->id);
            $target = $accounts->firstWhere('id', $user->id);

            if (! $actor || ! $actor->is_active || ! $actor->is_admin) {
                throw ValidationException::withMessages(['account' => 'บัญชีผู้ดูแลระบบถูกระงับแล้ว']);
            }
            if (! $target) {
                abort(404);
            }
            if (! $active && $actor->is($target)) {
                throw ValidationException::withMessages(['account' => 'ไม่สามารถระงับบัญชีที่กำลังใช้งานอยู่ได้']);
            }
            if (! $active && $target->is_admin && $target->is_active
                && $accounts->where('is_admin', true)->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['account' => 'ต้องมี Admin ที่ใช้งานได้อย่างน้อยหนึ่งบัญชี']);
            }
            if ($active === $target->is_active) {
                return $active ? 'บัญชีใช้งานอยู่แล้ว' : 'บัญชีถูกระงับอยู่แล้ว';
            }

            $old = ['is_active' => $target->is_active];
            $target->update(['is_active' => $active]);
            $this->record($request, $active ? 'admin.user.activated' : 'admin.user.deactivated', $target, $old, ['is_active' => $active]);

            return $active ? 'เปิดใช้งานบัญชีแล้ว' : 'ระงับบัญชีแล้ว';
        });

        return back()->with('status', $status);
    }

    private function record(Request $request, string $action, User $subject, array $old = [], array $new = []): void
    {
        OpsAuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
