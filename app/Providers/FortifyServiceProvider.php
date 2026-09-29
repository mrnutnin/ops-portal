<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = $request->input('email');
            $password = $request->input('password');
            if (! is_string($email) || strlen($email) > 255 || ! is_string($password)) {
                return null;
            }

            $user = User::where('email', Str::lower(trim($email)))
                ->where('is_active', true)
                ->whereNotNull('email_verified_at')
                ->first();

            return $user && Hash::check($password, $user->password) ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email', '');
            $key = Str::transliterate((is_string($email) ? Str::lower($email) : '').'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
    }
}
