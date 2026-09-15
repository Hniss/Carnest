<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Students;
use App\Livewire\Child\Login as ChildLogin;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Lot 1 §4 + §6 — gestion administrative des élèves, consentement à la création, import CSV. */
class StudentsAdminTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_create_child_with_consent_creates_parent_account_and_sends_reset_link(): void
    {
        Notification::fake();
        $school = School::factory()->create(['name' => 'École Démo']);
        $admin  = $this->makeAdmin($school);
        $this->actingAs($admin);

        Livewire::test(Students::class)
            ->call('openCreate')
            ->set('form.name', 'Eleve Demo Nouveau')
            ->set('form.classe', 'CM1')
            ->set('form.birth_date', now()->subYears(10)->toDateString())
            ->set('form.email', 'eleve.demo@carenest.test')
            ->set('form.parent_email', 'parent.demo@carenest.test')
            ->set('form.parent_name', 'Parent Demo')
            ->set('form.relation', 'mere')
            ->set('form.consent', true)
            ->call('save')->assertHasNoErrors();

        $child = Child::where('email', 'eleve.demo@carenest.test')->first();
        $this->assertNotNull($child);
        $this->assertNull($child->deactivated_at);
        $this->assertSame($school->id, $child->school_id);

        $parent = User::where('email', 'parent.demo@carenest.test')->first();
        $this->assertSame('parent', $parent->role);
        Notification::assertSentTo($parent, ResetPassword::class);

        $this->assertDatabaseHas('parent_child', ['parent_id' => $parent->id, 'child_id' => $child->id, 'relation' => 'mere', 'consent_given' => true]);
        $pivot = $parent->children()->first()->pivot;
        $this->assertStringContainsString('École Démo', $pivot->consent_text);
        $this->assertNotNull($pivot->consent_timestamp);
        $this->assertNotNull($pivot->consent_ip);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.child.create', 'target_id' => $child->id]);
    }

    public function test_child_created_without_consent_is_deactivated_and_cannot_log_in(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $this->actingAs($admin);

        Livewire::test(Students::class)
            ->call('openCreate')
            ->set('form.name', 'Eleve Demo Sans Consentement')
            ->set('form.classe', 'CE2')
            ->set('form.birth_date', now()->subYears(8)->toDateString())
            ->set('form.email', 'sans.consentement@carenest.test')
            ->set('form.password', 'Secret-Demo-123')
            ->set('form.parent_email', 'parent2.demo@carenest.test')
            ->set('form.parent_name', 'Parent Demo Deux')
            ->set('form.relation', 'pere')
            ->set('form.consent', false)
            ->call('save')->assertHasNoErrors();

        $child = Child::where('email', 'sans.consentement@carenest.test')->first();
        $this->assertNotNull($child->deactivated_at);

        auth()->logout();
        Livewire::test(ChildLogin::class)
            ->set('email', 'sans.consentement@carenest.test')->set('password', 'Secret-Demo-123')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertFalse(auth('child')->check());
    }

    public function test_deactivate_reactivate_and_edit(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $child  = Child::factory()->for($school)->create(['classe' => 'CM1']);
        $foreign = Child::factory()->for(School::factory()->create())->create();
        $this->actingAs($admin);

        Livewire::test(Students::class)->call('deactivate', $child->id);
        $this->assertNotNull($child->fresh()->deactivated_at);
        // Réactivation refusée sans consentement parental actif, acceptée ensuite.
        Livewire::test(Students::class)->call('reactivate', $child->id)->assertHasErrors('reactivate');
        $this->assertNotNull($child->fresh()->deactivated_at);
        $this->makeParent($child, true);
        Livewire::test(Students::class)->call('reactivate', $child->id)->assertHasNoErrors();
        $this->assertNull($child->fresh()->deactivated_at);

        Livewire::test(Students::class)->call('deactivate', $foreign->id)->assertForbidden();

        Livewire::test(Students::class)->call('openEdit', $child->id)->set('form.classe', 'CM2')->call('save')->assertHasNoErrors();
        $this->assertSame('CM2', $child->fresh()->classe);
    }

    public function test_csv_import_validates_line_by_line_and_reports_errors(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $this->actingAs($admin);

        $csv = "nom;prenom;classe;date_naissance;email_parent;relation\n"
             . "Demo;Alice;CE1;2018-03-04;alice.parent@carenest.test;mere\n"
             . "Demo;Bilal;CM2;pas-une-date;bilal.parent@carenest.test;pere\n"
             . "Demo;Chadi;CM1;2016-01-20;chadi.parent@carenest.test;tuteur\n";
        $file = UploadedFile::fake()->createWithContent('eleves.csv', $csv);

        $c = Livewire::test(Students::class)->set('importFile', $file)->call('import')->assertHasNoErrors();

        $this->assertSame(2, Child::where('school_id', $school->id)->count());
        $this->assertNotNull(Child::where('name', 'Alice Demo')->first()?->deactivated_at, 'importé sans consentement = désactivé');
        $this->assertDatabaseHas('users', ['email' => 'chadi.parent@carenest.test', 'role' => 'parent']);
        $c->assertSee('Ligne 3')->assertSee('date');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.children.import']);
    }
}
