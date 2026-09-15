<?php

namespace Tests\Feature\Access;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Lot 1 — cloisonnement par rôle côté serveur (middleware `role`). */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function referent(School $school): User
    {
        $u = User::factory()->create(['role' => 'referent']);
        $school->users()->attach($u->id, ['role' => 'referent']);
        return $u;
    }

    private function admin(School $school): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        $school->users()->attach($u->id, ['role' => 'director']);
        return $u;
    }

    private function parent(Child $child): User
    {
        $u = User::factory()->create(['role' => 'parent']);
        $u->children()->attach($child->id, ['relation' => 'pere', 'consent_given' => true]);
        return $u;
    }

    public function test_parent_cannot_reach_referent_or_admin_space(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $parent = $this->parent($child);

        $this->actingAs($parent)->get('/dashboard-referent')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard-referent/eleves')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard-referent/eleves/' . $child->id)->assertForbidden();
        $this->actingAs($parent)->get('/dashboard-referent/messages')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard-referent/delegation')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard/eleves')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard/comptes')->assertForbidden();
        $this->actingAs($parent)->get('/dashboard/journal')->assertForbidden();
    }

    public function test_referent_cannot_reach_admin_or_parent_space(): void
    {
        $school = School::factory()->create();
        $ref = $this->referent($school);

        $this->actingAs($ref)->get('/dashboard')->assertForbidden();
        $this->actingAs($ref)->get('/dashboard/eleves')->assertForbidden();
        $this->actingAs($ref)->get('/parent')->assertForbidden();
    }

    public function test_admin_cannot_reach_parent_space_nor_referent_students(): void
    {
        $school = School::factory()->create();
        $admin = $this->admin($school);

        $this->actingAs($admin)->get('/parent')->assertForbidden();
        $this->actingAs($admin)->get('/dashboard-referent/eleves')->assertForbidden();
    }

    public function test_root_redirects_by_role(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();

        $this->actingAs($this->admin($school))->get('/')->assertRedirect('/dashboard');
        $this->actingAs($this->referent($school))->get('/')->assertRedirect('/dashboard-referent');
        $this->actingAs($this->parent($child))->get('/')->assertRedirect('/parent');
    }

    public function test_authenticated_user_opening_login_is_sent_to_his_own_space(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();

        $this->actingAs($this->referent($school))->get('/login')->assertRedirect('/dashboard-referent');
        $this->actingAs($this->parent($child))->get('/login')->assertRedirect('/parent');
        $this->actingAs($this->admin($school))->get('/login')->assertRedirect('/dashboard');
    }

    public function test_login_redirects_referent_and_parent_to_their_space(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $ref    = $this->referent($school);
        $parent = $this->parent($child);

        Volt::test('pages.auth.login')
            ->set('form.email', $ref->email)->set('form.password', 'password')
            ->call('login')->assertRedirect('/dashboard-referent');

        Volt::test('pages.auth.login')
            ->set('form.email', $parent->email)->set('form.password', 'password')
            ->call('login')->assertRedirect('/parent');
    }
}
