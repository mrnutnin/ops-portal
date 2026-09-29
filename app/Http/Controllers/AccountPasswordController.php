<?php

namespace App\Http\Controllers;

use App\Models\OpsAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::default(), 'confirmed'],
        ]);
        $user = $request->user();

        DB::transaction(function () use ($request, $user, $data): void {
            $mustChange = $user->must_change_password;
            $user->update(['password' => $data['password'], 'must_change_password' => false]);
            OpsAuditLog::create([
                'actor_id' => $user->id,
                'action' => 'user.password_changed',
                'subject_type' => $user->getMorphClass(),
                'subject_id' => (string) $user->id,
                'old_values' => ['must_change_password' => $mustChange],
                'new_values' => ['must_change_password' => false],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'เปลี่ยนรหัสผ่านแล้ว');
    }
}
