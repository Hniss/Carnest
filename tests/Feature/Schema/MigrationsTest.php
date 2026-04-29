<?php
namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schools_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schools'));
        foreach (['id','name','address','city','phone','email','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('schools', $col), "Missing column: $col");
        }
    }

    public function test_school_user_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('school_user'));
        foreach (['id','school_id','user_id','role','created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('school_user', $col), "Missing column: $col");
        }
    }

    public function test_school_settings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('school_settings'));
        foreach (['id','school_id','alert_threshold','email_notifications','language','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('school_settings', $col), "Missing column: $col");
        }
    }

    public function test_children_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('children'));
        foreach ([
            'id','school_id','name','email','password',
            'age','age_group','classe','score_enfant',
            'status','last_session_at','remember_token',
            'created_at','updated_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('children', $col), "Missing column: $col");
        }
    }

    public function test_chat_sessions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('chat_sessions'));
        foreach ([
            'id','child_id','school_id','zone',
            'ai_summary','low_confidence',
            'started_at','ended_at','created_at','updated_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('chat_sessions', $col), "Missing column: $col");
        }
    }

    public function test_alerts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('alerts'));
        foreach ([
            'id','session_id','child_id','school_id',
            'type','level','status','notified_at',
            'created_at','updated_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('alerts', $col), "Missing column: $col");
        }
    }

    public function test_admin_notes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('admin_notes'));
        foreach (['id','alert_id','user_id','content','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('admin_notes', $col), "Missing column: $col");
        }
    }
}
