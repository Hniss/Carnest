<?php

namespace Tests\Feature\Auth;

use App\Livewire\Admin\Accounts;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Un compte adulte reçoit TOUJOURS son rôle explicitement à sa création (correction du
 * 2026-10-02) : la colonne users.role n'a plus de valeur par défaut. Avant, elle valait
 * « admin » — un compte créé sans rôle devenait administrateur.
 */
class AccountRoleDefaultTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_an_account_created_without_a_role_is_refused(): void
    {
        $refused = false;
        try {
            User::create([
                'name'     => 'Compte sans rôle',
                'email'    => 'sans.role@test.invalid',
                'password' => Hash::make('mot-de-passe-de-test'),
            ]);
        } catch (QueryException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Un compte sans rôle explicite ne doit pas pouvoir être créé.');
        $this->assertSame(0, User::where('email', 'sans.role@test.invalid')->count());
    }

    public function test_a_direct_insert_without_a_role_is_refused_too(): void
    {
        $refused = false;
        try {
            DB::table('users')->insert([
                'name'       => 'Insertion sans rôle',
                'email'      => 'insertion.sans.role@test.invalid',
                'password'   => Hash::make('mot-de-passe-de-test'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Même hors application, la base refuse un compte sans rôle.');
        $this->assertSame(0, DB::table('users')->where('role', 'admin')->count());
    }

    /** Le parcours légitime de création d'un administrateur donne le rôle explicitement. */
    public function test_the_accounts_screen_still_creates_an_administrator(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $this->actingAs($this->makeAdmin($school));

        Livewire::test(Accounts::class)
            ->set('name', 'Seconde administration')
            ->set('email', 'seconde.admin@test.invalid')
            ->set('role', 'admin')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame('admin', User::where('email', 'seconde.admin@test.invalid')->value('role'));
    }
}
