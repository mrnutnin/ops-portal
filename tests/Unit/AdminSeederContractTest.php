<?php

namespace Tests\Unit;

use App\Models\OpsAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSeederContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_one_configured_admin_without_resetting_its_password(): void
    {
        config([
            'ops.admin.name' => 'Initial Admin',
            'ops.admin.email' => 'ADMIN@example.test',
            'ops.admin.password' => 'Initial-Admin-Secret-123',
        ]);

        $this->seed();
        $admin = User::sole();
        $hash = $admin->password;

        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->must_change_password);
        $this->assertTrue(Hash::check('Initial-Admin-Secret-123', $hash));

        config(['ops.admin.password' => 'Changed-Secret-456']);
        $this->seed();

        $this->assertSame(1, User::count());
        $this->assertSame($hash, User::sole()->password);
        $this->assertSame(1, OpsAuditLog::where('action', 'admin.bootstrap_created')->count());

        config(['ops.admin.name' => null, 'ops.admin.email' => null, 'ops.admin.password' => null]);
        $this->seed();
        $this->assertSame(1, User::count());
    }
}
