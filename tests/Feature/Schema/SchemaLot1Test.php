<?php

namespace Tests\Feature\Schema;

use App\Models\AdminNote;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Lot 1 (MVP v3) — tables des espaces référent / parent / administration. */
class SchemaLot1Test extends TestCase
{
    use RefreshDatabase;

    public function test_lot1_tables_exist(): void
    {
        foreach ([
            'parent_child', 'alert_lifecycle', 'alert_actions', 'follow_ups',
            'parent_threads', 'parent_messages', 'parent_syntheses', 'audit_logs',
            'referent_delegations', 'alert_notifications', 'app_notifications',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table manquante : {$table}");
        }
    }

    public function test_lot1_key_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('parent_child', ['parent_id', 'child_id', 'relation', 'consent_given', 'consent_text', 'consent_timestamp', 'consent_ip', 'consent_withdrawn_at']));
        $this->assertTrue(Schema::hasColumns('alert_lifecycle', ['alert_id', 'status', 'qualification', 'changed_by', 'changed_at']));
        $this->assertTrue(Schema::hasColumns('alert_actions', ['alert_id', 'action_type', 'performed_by', 'performed_at', 'notes']));
        $this->assertTrue(Schema::hasColumns('follow_ups', ['child_id', 'alert_id', 'status', 'next_review_date', 'objectif', 'responsable_id']));
        $this->assertTrue(Schema::hasColumns('parent_threads', ['school_id', 'child_id', 'parent_id', 'referent_id', 'subject', 'important']));
        $this->assertTrue(Schema::hasColumns('parent_messages', ['thread_id', 'sender_id', 'sender_role', 'body', 'sent_at', 'read_at']));
        $this->assertTrue(Schema::hasColumns('parent_syntheses', ['child_id', 'alert_id', 'parent_id', 'sent_by', 'identified', 'school_did', 'school_proposes', 'parent_can', 'sent_at', 'read_at']));
        $this->assertTrue(Schema::hasColumns('audit_logs', ['actor_id', 'actor_role', 'action', 'target_type', 'target_id', 'school_id', 'ip', 'created_at']));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
        $this->assertTrue(Schema::hasColumns('referent_delegations', ['school_id', 'referent_id', 'delegate_id', 'start_date', 'end_date', 'activated_at', 'revoked_at']));
        $this->assertTrue(Schema::hasColumns('alert_notifications', ['alert_id', 'channel', 'recipient_id', 'escalation_step', 'sent_at', 'acked_at', 'payload']));
        $this->assertTrue(Schema::hasColumns('app_notifications', ['user_id', 'type', 'title', 'body', 'link', 'read_at']));
        $this->assertTrue(Schema::hasColumn('admin_notes', 'referent_id'));
    }

    public function test_admin_note_exposes_referent_relation(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $user   = User::factory()->create();

        $note = AdminNote::create(['child_id' => $child->id, 'user_id' => $user->id, 'referent_id' => $user->id, 'content' => 'Note interne']);

        $this->assertSame($user->id, $note->referent->id);
    }
}
