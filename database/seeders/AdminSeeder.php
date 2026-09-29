<?php

namespace Database\Seeders;

use App\Models\OpsAuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('ops.admin.name'));
        $email = Str::lower(trim((string) config('ops.admin.email')));
        $password = (string) config('ops.admin.password');
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        if ($validEmail && ($existing = User::where('email', $email)->first())) {
            if (! $existing->is_admin) {
                throw new RuntimeException('Configured OPS_ADMIN_EMAIL belongs to a non-admin user.');
            }

            return;
        }

        if (! $validEmail || $name === '' || mb_strlen($password) < 12) {
            if (User::where('is_admin', true)->exists()) {
                return;
            }

            throw new RuntimeException('Set OPS_ADMIN_NAME, OPS_ADMIN_EMAIL and a 12+ character OPS_ADMIN_PASSWORD before seeding.');
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $admin = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'email_verified_at' => now(),
                'password' => $password,
                'is_active' => true,
                'is_admin' => true,
                'must_change_password' => false,
            ]);

            if ($admin->wasRecentlyCreated) {
                OpsAuditLog::create([
                    'action' => 'admin.bootstrap_created',
                    'subject_type' => $admin->getMorphClass(),
                    'subject_id' => (string) $admin->getKey(),
                    'old_values' => [],
                    'new_values' => ['is_admin' => true, 'is_active' => true],
                    'user_agent' => 'database:seed:AdminSeeder',
                ]);
            }
        });
    }
}
