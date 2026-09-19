<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MVP v3 — Extensions de schéma (D3, D8, D9 préparation) :
 *  - children       : birth_date, deactivated_at ; age_group -> 5-7 / 8-11 / 12-18
 *  - chat_sessions  : tokens_used, prompt_version, model, care_memory (chiffré)
 *  - alerts         : summary (chiffré), signals, prompt_version, model, adjudication
 *  - school_settings: horaires, plafond de tokens, durée max de session, téléphones
 *  - users          : role (admin / referent / parent), phone
 *  - school_user    : role étendu à referent
 */
return new class extends Migration
{
    private const AGE_GROUPS = ['5-7', '8-11', '12-18'];
    private const SCHOOL_USER_ROLES = ['director', 'counselor', 'teacher', 'staff', 'referent'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $isMysql = $driver === 'mysql' || $driver === 'mariadb';

        // ── children ──────────────────────────────────────────────────────
        if ($driver === 'sqlite') {
            $this->recreateChildrenSqlite();
        } else {
            Schema::table('children', function (Blueprint $table) {
                $table->date('birth_date')->nullable()->after('age');
                $table->timestamp('deactivated_at')->nullable()->after('last_session_at');
            });
            DB::statement("UPDATE children SET age_group = '12-18' WHERE age_group = '12-14'");
            if ($isMysql) {
                DB::statement("ALTER TABLE children MODIFY COLUMN age_group ENUM('" . implode("','", self::AGE_GROUPS) . "') NOT NULL");
            }
        }

        // ── chat_sessions ─────────────────────────────────────────────────
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->unsignedInteger('tokens_used')->default(0)->after('low_confidence');
            $table->string('prompt_version', 20)->nullable()->after('tokens_used');
            $table->string('model', 80)->nullable()->after('prompt_version');
            $table->text('care_memory')->nullable()->after('model');
        });

        // ── alerts ────────────────────────────────────────────────────────
        Schema::table('alerts', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('status');
            $table->json('signals')->nullable()->after('summary');
            $table->string('prompt_version', 20)->nullable()->after('signals');
            $table->string('model', 80)->nullable()->after('prompt_version');
            $table->enum('adjudication', ['non_applicable', 'confirmee', 'infirmee', 'a_confirmer'])
                ->default('non_applicable')->after('model');
        });

        // ── school_settings ───────────────────────────────────────────────
        Schema::table('school_settings', function (Blueprint $table) {
            $table->time('school_hours_start')->default('08:00')->after('language');
            $table->time('school_hours_end')->default('17:00')->after('school_hours_start');
            $table->unsignedInteger('daily_token_cap')->default(10000)->after('school_hours_end');
            $table->string('referent_phone', 30)->nullable()->after('daily_token_cap');
        });

        // ── users ─────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'referent', 'parent'])->default('admin')->after('email');
            $table->string('phone', 30)->nullable()->after('role');
        });

        // ── school_user.role ──────────────────────────────────────────────
        if ($isMysql) {
            DB::statement("ALTER TABLE school_user MODIFY COLUMN role ENUM('" . implode("','", self::SCHOOL_USER_ROLES) . "') NOT NULL DEFAULT 'staff'");
        } elseif ($driver === 'sqlite') {
            $this->recreateSchoolUserSqlite();
        } else {
            DB::statement('ALTER TABLE school_user ALTER COLUMN role TYPE VARCHAR(20)');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $isMysql = $driver === 'mysql' || $driver === 'mariadb';

        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'phone']));
        Schema::table('school_settings', fn (Blueprint $t) => $t->dropColumn([
            'school_hours_start', 'school_hours_end', 'daily_token_cap', 'referent_phone',
        ]));
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn(['summary', 'signals', 'prompt_version', 'model', 'adjudication']));
        Schema::table('chat_sessions', fn (Blueprint $t) => $t->dropColumn(['tokens_used', 'prompt_version', 'model', 'care_memory']));

        if ($isMysql) {
            DB::statement("UPDATE children SET age_group = '12-14' WHERE age_group = '12-18'");
            DB::statement("ALTER TABLE children MODIFY COLUMN age_group ENUM('5-7','8-11','12-14') NOT NULL");
            DB::statement("UPDATE school_user SET role = 'staff' WHERE role = 'referent'");
            DB::statement("ALTER TABLE school_user MODIFY COLUMN role ENUM('director','counselor','teacher','staff') NOT NULL DEFAULT 'staff'");
        }
        if ($driver === 'sqlite') {
            DB::statement("UPDATE children SET age_group = '12-14' WHERE age_group = '12-18'");
            DB::statement("UPDATE school_user SET role = 'staff' WHERE role = 'referent'");
        }
        Schema::table('children', fn (Blueprint $t) => $t->dropColumn(['birth_date', 'deactivated_at']));
    }

    /**
     * SQLite : la contrainte CHECK de age_group ne se modifie pas — on recrée la
     * table avec TOUTES les colonnes actuelles + les nouvelles, en convertissant 12-14 -> 12-18.
     */
    private function recreateChildrenSqlite(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::create('children_tmp_v6', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password');
                $table->unsignedTinyInteger('age');
                $table->date('birth_date')->nullable();
                $table->enum('age_group', self::AGE_GROUPS);
                $table->string('classe', 50);
                $table->string('gender', 1)->nullable();
                $table->float('score_enfant')->nullable()->default(null);
                $table->enum('status', ['ok', 'a_surveiller', 'a_suivre', 'critique'])->default('ok');
                $table->timestamp('last_session_at')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['school_id', 'score_enfant']);
            });

            DB::statement("INSERT INTO children_tmp_v6 (id, school_id, name, email, password, age, birth_date, age_group, classe, gender, score_enfant, status, last_session_at, deactivated_at, remember_token, created_at, updated_at)
                           SELECT id, school_id, name, email, password, age, NULL,
                                  CASE WHEN age_group = '12-14' THEN '12-18' ELSE age_group END,
                                  classe, gender, score_enfant, status, last_session_at, NULL, remember_token, created_at, updated_at
                           FROM children");

            Schema::drop('children');
            Schema::rename('children_tmp_v6', 'children');
            $this->canonicalizeSqliteIndexes('children', 'children_tmp_v6', [
                ['columns' => ['email'], 'unique' => true],
                ['columns' => ['school_id', 'status'], 'unique' => false],
                ['columns' => ['school_id', 'score_enfant'], 'unique' => false],
            ]);
        });
    }

    private function recreateSchoolUserSqlite(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::create('school_user_tmp_v6', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->enum('role', self::SCHOOL_USER_ROLES)->default('staff');
                $table->timestamp('created_at')->nullable();
                $table->unique(['school_id', 'user_id']);
            });

            DB::statement('INSERT INTO school_user_tmp_v6 (id, school_id, user_id, role, created_at)
                           SELECT id, school_id, user_id, role, created_at FROM school_user');

            Schema::drop('school_user');
            Schema::rename('school_user_tmp_v6', 'school_user');
            $this->canonicalizeSqliteIndexes('school_user', 'school_user_tmp_v6', [
                ['columns' => ['school_id', 'user_id'], 'unique' => true],
            ]);
        });
    }

    /**
     * SQLite conserve le nom des index de la table temporaire après RENAME
     * (ex. alerts_tmp_v6_school_id_status_index). On les recrée sous leur nom
     * canonique pour que la migration reste rejouable (rollback puis migrate).
     *
     * @param array<int, array{columns: string[], unique: bool}> $indexes
     */
    private function canonicalizeSqliteIndexes(string $table, string $tmpTable, array $indexes): void
    {
        $tmpIndexes = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', $table)
            ->where('name', 'like', $tmpTable . '_%')
            ->pluck('name');

        foreach ($tmpIndexes as $name) {
            DB::statement('DROP INDEX IF EXISTS "' . $name . '"');
        }

        Schema::table($table, function (Blueprint $t) use ($indexes) {
            foreach ($indexes as $index) {
                $index['unique'] ? $t->unique($index['columns']) : $t->index($index['columns']);
            }
        });
    }
};
