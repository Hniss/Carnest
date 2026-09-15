<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lot 0 (MVP v3) — les trois migrations du lot doivent être rejouables sur
 * SQLite (rollback puis migrate) : recréation de tables sans collision d'index.
 */
class MigrationsReplayTest extends TestCase
{
    use DatabaseMigrations;

    public function test_lot0_and_lot1_migrations_can_be_rolled_back_and_replayed(): void
    {
        // 3 migrations lot 0 + 3 migrations lot 1 (000010, 000011, 000012).
        $this->artisan('migrate:rollback', ['--step' => 6, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('children', 'birth_date'));
        $this->assertFalse(Schema::hasTable('audit_logs'));
        $this->assertFalse(Schema::hasColumn('admin_notes', 'referent_id'));
        $this->assertFalse(Schema::hasColumn('users', 'deactivated_at'));

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('children', 'birth_date'));
        $this->assertTrue(Schema::hasColumn('alerts', 'adjudication'));
        $this->assertTrue(Schema::hasColumn('chat_sessions', 'care_memory'));
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumn('alerts', 'reopened_from_id'));
        $this->assertTrue(Schema::hasColumn('school_settings', 'notification_channels'));
    }

    public function test_recreated_tables_keep_canonical_index_names(): void
    {
        $tmpNamed = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('name', 'like', '%_tmp_v6_%')
            ->pluck('name')
            ->all();

        $this->assertSame([], $tmpNamed, 'Index nommés d\'après la table temporaire : ' . implode(', ', $tmpNamed));

        $names = DB::table('sqlite_master')->where('type', 'index')->pluck('name')->all();
        foreach ([
            'alerts_school_id_status_index',
            'alerts_child_id_created_at_index',
            'children_email_unique',
            'children_school_id_status_index',
            'children_school_id_score_enfant_index',
            'school_user_school_id_user_id_unique',
        ] as $expected) {
            $this->assertContains($expected, $names, "Index manquant : {$expected}");
        }
    }
}
