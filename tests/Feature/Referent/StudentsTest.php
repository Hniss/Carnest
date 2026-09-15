<?php

namespace Tests\Feature\Referent;

use App\Livewire\Referent\StudentProfile;
use App\Livewire\Referent\Students;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

class StudentsTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_list_is_scoped_to_school_searchable_and_paginated(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        Child::factory()->for($school)->count(22)->create(['classe' => 'CM1']);
        Child::factory()->for($school)->create(['name' => 'Aaron Demo Cible', 'classe' => 'CE2']);
        Child::factory()->for(School::factory()->create())->create(['name' => 'Autre Demo Ecole']);

        $this->actingAs($ref);

        Livewire::test(Students::class)
            ->assertSee('Aaron Demo Cible')
            ->assertDontSee('Autre Demo Ecole')
            ->assertViewHas('children', fn ($p) => $p->perPage() === 20 && $p->total() === 23);

        Livewire::test(Students::class)->set('search', 'Aaron')
            ->assertViewHas('children', fn ($p) => $p->total() === 1);

        Livewire::test(Students::class)->set('classe', 'CE2')
            ->assertViewHas('children', fn ($p) => $p->total() === 1);
    }

    public function test_csv_export_is_audited(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        Child::factory()->for($school)->create(['name' => 'Eleve Demo Export']);
        $this->actingAs($ref);

        Livewire::test(Students::class)->call('exportCsv')->assertFileDownloaded();

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $ref->id, 'action' => 'referent.students.export', 'school_id' => $school->id]);
    }

    public function test_profile_is_audited_and_note_is_linked_to_referent(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['name' => 'Eleve Demo Profil']);
        $this->makeParent($child, true);
        $this->actingAs($ref);

        $this->get('/dashboard-referent/eleves/' . $child->id)
            ->assertOk()->assertSee('Eleve Demo Profil')->assertSee('Informer le parent')->assertSee('Tendance');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $ref->id, 'action' => 'referent.child.view', 'target_id' => $child->id]);

        Livewire::test(StudentProfile::class, ['child' => $child])
            ->set('newNote', 'Observation interne de démonstration.')
            ->call('addNote')->assertHasNoErrors();
        $this->assertDatabaseHas('admin_notes', ['child_id' => $child->id, 'referent_id' => $ref->id, 'user_id' => $ref->id]);
    }

    public function test_profile_of_other_school_is_forbidden(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $foreign = Child::factory()->for(School::factory()->create())->create();

        $this->actingAs($ref)->get('/dashboard-referent/eleves/' . $foreign->id)->assertForbidden();
    }
}
