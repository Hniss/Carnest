<?php

namespace Tests\Feature\SuperAdmin;

use App\Livewire\SuperAdmin\SchoolShow;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Écoles en lecture seule et comptes admin d'école gérés par le super-admin. */
class SchoolsAndAdminAccountsTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    public function test_schools_are_listed_read_only_without_any_pupil_name(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create(['name' => 'École pilote Test', 'city' => 'Rabat']);
        Child::factory()->for($school)->count(3)->create();
        Child::factory()->for($school)->create(['name' => 'Prenom Secret Eleve']);

        $this->actingAs($sa)->get('/superadmin/ecoles')
            ->assertOk()->assertSee('École pilote Test')->assertSee('Rabat')->assertDontSee('Prenom Secret Eleve');
        $this->actingAs($sa)->get('/superadmin/ecoles/' . $school->id)
            ->assertOk()->assertSee('4 élèves actifs')->assertDontSee('Prenom Secret Eleve');
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.view.schools']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.view.school', 'school_id' => $school->id]);
    }

    public function test_superadmin_creates_a_school_admin_who_receives_a_password_link(): void
    {
        Notification::fake();
        $sa = $this->superAdmin();
        $school = School::factory()->create();

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->set('adminName', 'Directrice Test')->set('adminEmail', 'Directrice@Ecole.test')->set('adminPhone', '0600000000')
            ->call('createAdmin')->assertHasNoErrors();

        $admin = User::where('email', 'directrice@ecole.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($school->users()->whereKey($admin->id)->wherePivot('role', 'director')->exists());
        Notification::assertSentTo($admin, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.admin_account.create', 'target_id' => $admin->id, 'school_id' => $school->id]);
    }

    public function test_an_email_already_used_is_refused(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();
        $this->makeReferent($school, ['email' => 'pris@ecole.test']);

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->set('adminName', 'X')->set('adminEmail', 'pris@ecole.test')->call('createAdmin')->assertHasErrors('adminEmail');
        $this->assertSame(1, User::where('email', 'pris@ecole.test')->count());
    }

    public function test_superadmin_edits_deactivates_and_reactivates_a_school_admin(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();
        $a1 = $this->makeAdmin($school);
        $a2 = $this->makeAdmin($school);

        $page = Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->call('editAdmin', $a1->id)->set('adminName', 'Nom Corrigé')->set('adminEmail', 'nouveau@ecole.test')
            ->call('updateAdmin')->assertHasNoErrors();
        $this->assertSame('Nom Corrigé', $a1->fresh()->name);
        $this->assertSame('nouveau@ecole.test', $a1->fresh()->email);

        $page->call('deactivateAdmin', $a1->id)->assertDontSee('dernier administrateur actif');
        $this->assertNotNull($a1->fresh()->deactivated_at);

        $page->call('deactivateAdmin', $a2->id)->assertSee('dernier administrateur actif');
        $this->assertNotNull($a2->fresh()->deactivated_at);

        $page->call('reactivateAdmin', $a1->id);
        $this->assertNull($a1->fresh()->deactivated_at);

        foreach (['update', 'deactivate', 'reactivate'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => "superadmin.admin_account.$action", 'school_id' => $school->id]);
        }
    }

    public function test_only_admins_of_this_school_can_be_managed_from_its_page(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();
        $ref = $this->makeReferent($school);
        $otherAdmin = $this->makeAdmin(School::factory()->create());

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])->call('deactivateAdmin', $ref->id)->assertStatus(404);
        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])->call('deactivateAdmin', $otherAdmin->id)->assertStatus(404);
        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])->call('deactivateAdmin', $sa->id)->assertStatus(404);
        $this->assertNull($ref->fresh()->deactivated_at);
        $this->assertNull($otherAdmin->fresh()->deactivated_at);
    }
}
