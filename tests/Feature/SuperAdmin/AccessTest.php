<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Espace super-admin (2026-10-05) : rôle, adresse /superadmin, redirection de chaque
 * profil vers son propre espace (décision Q3), accueil séparé adulte / élève (Q1).
 */
class AccessTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    public function test_superadmin_home_is_superadmin_space(): void
    {
        $sa = $this->superAdmin();

        $this->assertSame('/superadmin', $sa->homePath());
        $this->actingAs($sa)->get('/superadmin')->assertOk();
        $this->actingAs($sa)->get('/')->assertRedirect('/superadmin');
        $this->actingAs($sa)->get('/login')->assertRedirect('/superadmin');
    }

    public function test_guest_on_superadmin_goes_to_login_then_back_to_superadmin(): void
    {
        $sa = $this->superAdmin();

        $this->get('/superadmin')->assertRedirect('/login');

        Volt::test('pages.auth.login')
            ->set('form.email', $sa->email)->set('form.password', 'password')
            ->call('login')->assertRedirect('/superadmin');
    }

    public function test_school_admin_typing_superadmin_is_sent_to_his_space(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);

        $this->actingAs($admin)->get('/superadmin')->assertRedirect('/dashboard');
        $this->actingAs($admin)->get('/superadmin/cles')->assertRedirect('/dashboard');
        $this->actingAs($admin)->getJson('/superadmin')->assertForbidden();
    }

    public function test_superadmin_never_opens_school_referent_parent_or_child_spaces(): void
    {
        $sa = $this->superAdmin();

        $this->actingAs($sa)->get('/dashboard')->assertRedirect('/superadmin');
        $this->actingAs($sa)->get('/dashboard/eleves')->assertRedirect('/superadmin');
        $this->actingAs($sa)->get('/dashboard-referent')->assertRedirect('/superadmin');
        $this->actingAs($sa)->get('/parent')->assertRedirect('/superadmin');
        $this->actingAs($sa)->get('/chat')->assertRedirect('/child/login');
    }

    public function test_each_profile_is_redirected_to_its_own_space_instead_of_403(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child);
        $ref    = $this->makeReferent($school);

        $this->actingAs($parent)->get('/dashboard')->assertRedirect('/parent');
        $this->actingAs($ref)->get('/parent')->assertRedirect('/dashboard-referent');
    }

    public function test_superadmin_role_exists_with_a_french_label(): void
    {
        $this->assertContains('superadmin', User::ROLES);
        $this->assertSame('Super-admin', User::roleLabel('superadmin'));
    }

    public function test_guest_root_goes_to_adult_login_and_never_shows_laravel_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertDontSee('Accès élève')->assertDontSee('Laravel');
    }

    public function test_create_superadmin_command_writes_password_to_private_file_only(): void
    {
        $file = storage_path('app/private/test-comptes-superadmin.txt');
        File::delete($file);

        $this->artisan('carenest:create-superadmin', [
            'email' => 'fondateur@example.test', 'name' => 'Fondateur', '--file' => $file,
        ])->doesntExpectOutputToContain('Mot de passe :')->assertSuccessful();

        $user = User::where('email', 'fondateur@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('superadmin', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, $user->schools()->count());
        $this->assertFileExists($file);
        $this->assertStringContainsString('fondateur@example.test', File::get($file));
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.account.create', 'actor_role' => 'system']);

        File::delete($file);
    }

    public function test_create_superadmin_refuses_an_email_already_used(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school, ['email' => 'deja@example.test']);

        $this->artisan('carenest:create-superadmin', ['email' => 'deja@example.test', 'name' => 'X'])
            ->assertFailed();

        $this->assertSame('admin', $admin->fresh()->role);
    }
}
