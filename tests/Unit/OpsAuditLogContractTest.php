<?php

namespace Tests\Unit;

use App\Models\OpsAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsAuditLogContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entry_keeps_actor_subject_and_json_snapshots(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();
        $entry = OpsAuditLog::create([
            'actor_id' => $actor->id,
            'action' => 'admin.user.deactivated',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->id,
            'old_values' => ['is_active' => true],
            'new_values' => ['is_active' => false],
        ]);

        $this->assertSame($actor->id, $entry->actor->id);
        $this->assertSame('ระงับบัญชี', $entry->action_label);
        $this->assertSame(['is_active' => true], $entry->old_values);
        $this->assertSame(['is_active' => false], $entry->new_values);
        $this->assertDatabaseHas('ops_audit_logs', ['action' => 'admin.user.deactivated']);
    }
}
