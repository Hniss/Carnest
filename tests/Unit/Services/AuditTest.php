<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_writes_actor_role_target_and_school(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $user   = User::factory()->create(['role' => 'referent']);
        $this->actingAs($user);

        $log = Audit::log('referent.child.view', $child);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id'    => $user->id,
            'actor_role'  => 'referent',
            'action'      => 'referent.child.view',
            'target_type' => Child::class,
            'target_id'   => $child->id,
            'school_id'   => $school->id,
        ]);
        $this->assertNotNull($log->created_at);
    }

    public function test_log_without_actor_uses_system_role(): void
    {
        $log = Audit::log('system.tick');

        $this->assertNull($log->actor_id);
        $this->assertSame('system', $log->actor_role);
        $this->assertNull($log->target_type);
    }

    public function test_meta_school_id_overrides_when_target_has_none(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $log = Audit::log('admin.export', null, ['school_id' => 42]);

        $this->assertSame(42, $log->school_id);
    }

    public function test_audit_log_model_is_append_only(): void
    {
        $log = Audit::log('x');
        $this->assertFalse($log->timestamps);
        $this->assertFalse(method_exists(Audit::class, 'update'));
        $this->assertFalse(method_exists(Audit::class, 'delete'));
    }
}
