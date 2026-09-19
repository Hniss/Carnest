<?php

namespace Tests\Feature\ParentSpace;

use App\Livewire\ParentSpace\Consent;
use App\Livewire\ParentSpace\Home;
use App\Livewire\ParentSpace\Messages;
use App\Livewire\ParentSpace\MyData;
use App\Models\AdminNote;
use App\Models\AlertAction;
use App\Models\AlertLifecycle;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\ParentSynthesis;
use App\Models\ParentThread;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Lot 1 §5 — espace parent : accueil, journal, messagerie, consentement, mes données. */
class ParentSpaceTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_home_shows_latest_synthesis_in_four_blocks_and_marks_it_read(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['name' => 'Enfant Demo Un']);
        $child2 = Child::factory()->for($school)->create(['name' => 'Enfant Demo Deux']);
        $parent = $this->makeParent($child, true);
        $parent->children()->attach($child2->id, ['relation' => 'mere', 'consent_given' => true]);
        $alert  = $this->makeAlert($child, ['type' => 'harcelement']);

        $s = ParentSynthesis::create([
            'child_id' => $child->id, 'alert_id' => $alert->id, 'parent_id' => $parent->id, 'sent_by' => $ref->id,
            'identified' => ParentSynthesis::DEFAULT_IDENTIFIED, 'school_did' => 'Un entretien a été réalisé.',
            'school_proposes' => 'Un suivi est prévu.', 'parent_can' => ParentSynthesis::DEFAULT_PARENT_CAN, 'sent_at' => now(),
        ]);

        $this->actingAs($parent)->get('/parent?enfant=' . $child->id)
            ->assertOk()
            ->assertSee('Ce que CareNest a identifié')
            ->assertSee('Ce que l\'école a fait')
            ->assertSee('Ce que l\'école propose')
            ->assertSee('Ce que vous pouvez faire')
            ->assertSee('Enfant Demo Un')
            ->assertSee('Enfant Demo Deux')
            ->assertDontSee('harcèlement')
            ->assertDontSee('Harcèlement')
            ->assertDontSee('IA a détecté');

        $this->assertNotNull($s->fresh()->read_at);

        Livewire::actingAs($parent)->test(Home::class)->call('selectChild', $child2->id)->assertSee('Aucune information');
    }

    public function test_journal_shows_macro_events_only(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'danger']);
        AlertLifecycle::create(['alert_id' => $alert->id, 'status' => 'qualifie', 'qualification' => 'urgent', 'changed_by' => $ref->id, 'changed_at' => now()]);
        AlertAction::create(['alert_id' => $alert->id, 'action_type' => 'contact_parent', 'performed_by' => $ref->id, 'performed_at' => now(), 'notes' => 'Note interne secrète']);
        FollowUp::create(['child_id' => $child->id, 'alert_id' => $alert->id, 'status' => 'surveillance', 'next_review_date' => now()->addWeek(), 'objectif' => 'Objectif interne', 'responsable_id' => $ref->id]);

        $this->actingAs($parent)->get('/parent/journal')
            ->assertOk()
            ->assertSee('Un signal a été identifié')
            ->assertSee('Le référent a évalué la situation')
            ->assertSee('L\'école vous a contacté')
            ->assertSee('Un suivi a été planifié')
            ->assertDontSee('Danger')
            ->assertDontSee('Urgente')
            ->assertDontSee('Note interne')
            ->assertDontSee('Objectif interne');
    }

    public function test_parent_messages_only_with_own_child_thread_and_notifies_referent(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $otherChild  = Child::factory()->for($school)->create();
        $otherParent = $this->makeParent($otherChild, true);
        $otherThread = ParentThread::create(['school_id' => $school->id, 'child_id' => $otherChild->id, 'parent_id' => $otherParent->id, 'referent_id' => $ref->id, 'subject' => 'Privé']);

        Livewire::actingAs($parent)->test(Messages::class)->call('selectThread', $otherThread->id)->assertForbidden();

        Livewire::actingAs($parent)->test(Messages::class)
            ->set('newChildId', $child->id)->set('newSubject', 'Question')->set('newBody', 'Bonjour, je souhaite un rendez-vous.')
            ->call('createThread')->assertHasNoErrors();

        $thread = ParentThread::where('parent_id', $parent->id)->first();
        $this->assertSame($ref->id, $thread->referent_id);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $ref->id, 'type' => 'message']);

        Livewire::actingAs($parent)->test(Messages::class)->call('selectThread', $thread->id)->set('body', 'Merci.')->call('send')->assertHasNoErrors();
        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_consent_withdrawal_deactivates_child_and_notifies_school(): void
    {
        $school = School::factory()->create(['name' => 'École Démo Retrait']);
        $ref    = $this->makeReferent($school);
        $admin  = $this->makeAdmin($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);

        $this->actingAs($parent)->get('/parent/consentement')->assertOk()->assertSee('École Démo Retrait')->assertSee('loi 09-08')->assertSee('Retirer mon consentement');

        Livewire::actingAs($parent)->test(Consent::class)->call('withdraw', $child->id)->assertHasNoErrors();

        $pivot = $parent->children()->first()->pivot;
        $this->assertNotNull($pivot->consent_withdrawn_at);
        $this->assertNotNull($child->fresh()->deactivated_at);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $ref->id, 'type' => 'consentement']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $admin->id, 'type' => 'consentement']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $parent->id, 'action' => 'parent.consent.withdraw', 'target_id' => $child->id]);

        $foreign = Child::factory()->for($school)->create();
        Livewire::actingAs($parent)->test(Consent::class)->call('withdraw', $foreign->id)->assertForbidden();
    }

    public function test_my_data_export_is_readable_audited_and_excludes_internal_notes(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['name' => 'Enfant Demo Export']);
        $parent = $this->makeParent($child, true);
        ChatSession::create(['child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(), 'ended_at' => now(), 'zone' => 'green', 'ai_summary' => 'Résumé de session de démonstration']);
        $this->makeAlert($child, ['type' => 'isolement']);
        AdminNote::create(['child_id' => $child->id, 'user_id' => $ref->id, 'referent_id' => $ref->id, 'content' => 'NOTE INTERNE ULTRA SECRETE']);

        // L'écran annonce ce que contient réellement l'export (spec §3.2).
        $this->actingAs($parent)->get('/parent/mes-donnees')
            ->assertOk()
            ->assertSee('Ce que contient')
            ->assertDontSee('résumés et zones émotionnelles')
            ->assertDontSee('Les signaux détectés');

        $response = Livewire::actingAs($parent)->test(MyData::class)->call('export');
        $response->assertFileDownloaded();
        $content = $response->effects['download']['content'] ?? '';
        $content = base64_decode($content, true) ?: $content;

        $this->assertStringContainsString('Enfant Demo Export', $content);
        $this->assertStringNotContainsString('ULTRA SECRETE', $content);

        // Spec §3.2 — ni le type précis de signal, ni le niveau, ni les résumés côté parent.
        $this->assertStringNotContainsString('Résumé de session de démonstration', $content);
        $this->assertStringNotContainsString('Isolement', $content);
        $this->assertStringNotContainsString('Résumé', $content);
        $this->assertStringNotContainsString('niveau', $content);
        $this->assertStringNotContainsString('zone', $content);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $parent->id, 'action' => 'parent.data.export']);
    }

    public function test_parent_cannot_reach_other_child_data(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $other  = Child::factory()->for($school)->create(['name' => 'Enfant Demo Autre']);
        $parent = $this->makeParent($child, true);
        $this->makeParent($other, true);

        Livewire::actingAs($parent)->test(Home::class)->call('selectChild', $other->id)->assertForbidden();
        $this->actingAs($parent)->get('/parent/journal')->assertOk()->assertDontSee('Enfant Demo Autre');
    }
}
