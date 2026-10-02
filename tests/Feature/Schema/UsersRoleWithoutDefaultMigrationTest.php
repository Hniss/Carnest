<?php

namespace Tests\Feature\Schema;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Migration qui retire la valeur par défaut « admin » de users.role (correction du
 * 2026-10-02), rejouée sur des comptes déjà présents : aucun rôle ne change, aucun
 * rattachement n'est perdu.
 *
 * Sous SQLite, retirer une valeur par défaut reconstruit la table users, la plus
 * référencée de la base (écoles, enfants, délégations, notifications, fils de messages).
 * Si les clés étrangères restaient actives pendant la reconstruction, la suppression de
 * l'ancienne table effacerait ces rattachements en cascade : c'est ce que ce test surveille.
 * Pas de RefreshDatabase ici : la migration doit tourner hors transaction, comme en vrai.
 */
class UsersRoleWithoutDefaultMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_10_02_000001_users_role_without_default';

    private function path(): string
    {
        return 'database/migrations/' . self::MIGRATION . '.php';
    }

    private function insertWithoutRole(string $email): void
    {
        DB::table('users')->insert([
            'name'       => 'Sans rôle',
            'email'      => $email,
            'password'   => Hash::make('mot-de-passe-de-test'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_existing_accounts_keep_their_role_and_their_links(): void
    {
        $school   = School::factory()->create();
        $admin    = User::factory()->create(['role' => 'admin']);
        $referent = User::factory()->create(['role' => 'referent']);
        $parent   = User::factory()->create(['role' => 'parent']);
        $school->users()->attach($admin->id, ['role' => 'director']);
        $school->users()->attach($referent->id, ['role' => 'referent']);
        $child = Child::factory()->for($school)->create();
        $parent->children()->attach($child->id, [
            'relation' => 'mere', 'consent_given' => true, 'consent_text' => 'Consentement de test',
            'consent_timestamp' => now(), 'consent_ip' => '127.0.0.1',
        ]);

        $rolesBefore = DB::table('users')->orderBy('id')->pluck('role', 'id')->all();
        $linksBefore = [DB::table('school_user')->count(), DB::table('parent_child')->count()];

        Artisan::call('migrate:rollback', ['--path' => $this->path(), '--force' => true]);
        Artisan::call('migrate', ['--path' => $this->path(), '--force' => true]);

        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists(), 'La migration doit avoir été rejouée.');
        $this->assertSame($rolesBefore, DB::table('users')->orderBy('id')->pluck('role', 'id')->all());
        $this->assertSame([2, 1], $linksBefore);
        $this->assertSame($linksBefore, [DB::table('school_user')->count(), DB::table('parent_child')->count()]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys, 'Les clés étrangères doivent être réactivées après la migration.');
    }

    /** Dans une transaction, la reconstruction effacerait les rattachements : la migration refuse. */
    public function test_the_migration_refuses_to_rebuild_users_inside_a_transaction(): void
    {
        $school = School::factory()->create();
        $school->users()->attach(User::factory()->create(['role' => 'admin'])->id, ['role' => 'director']);
        $migration = require base_path($this->path());

        $refused = false;
        try {
            DB::transaction(fn () => $migration->down());
        } catch (\RuntimeException) {
            $refused = true;
        }

        $this->assertTrue($refused);
        $this->assertSame(1, DB::table('school_user')->count(), 'Aucun rattachement ne doit être perdu.');
    }

    public function test_the_old_default_comes_back_on_rollback_and_is_gone_once_replayed(): void
    {
        Artisan::call('migrate:rollback', ['--path' => $this->path(), '--force' => true]);
        $this->insertWithoutRole('avant@test.invalid');
        $this->assertSame('admin', DB::table('users')->where('email', 'avant@test.invalid')->value('role'));
        DB::table('users')->where('email', 'avant@test.invalid')->delete();

        Artisan::call('migrate', ['--path' => $this->path(), '--force' => true]);

        $refused = false;
        try {
            $this->insertWithoutRole('apres@test.invalid');
        } catch (QueryException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'Après la migration, un compte sans rôle explicite est refusé.');
    }
}
