<?php

namespace Tests\Feature\Gravity;

use App\Contracts\SmsSender;
use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Services\Adjudicator;
use App\Services\AIService;
use App\Services\AlertPager;
use App\Services\AlertUpgrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * L'alerte suit la gravité RÉELLE de la conversation.
 *
 * Scénario 3 de la recette réelle du 28/09, celui qui a tout révélé : une
 * adolescente de 13 ans écrit « bof », puis « jsp jai plus envie de rien », puis
 * « des fois je me dis que tout le monde serait mieux sans moi », puis « jaimerai
 * juste disparaitre ». L'alerte avait été créée au 2e échange sous l'étiquette
 * « détresse / modérée » et n'a plus jamais bougé : donc non vitale, donc
 * ZÉRO notification, zéro e-mail, zéro SMS, zéro information de l'administration.
 * Le référent lisait « Modérée », « détresse », « Aucun résumé disponible ».
 *
 * Ce que ces tests verrouillent :
 *  - le type et le niveau montent avec la conversation, jamais l'inverse ;
 *  - la chaîne de notification part sur le palier nouvellement atteint ;
 *  - aucune notification ne part deux fois pour le même palier ;
 *  - la politique « qui est notifié pour quel niveau » est INCHANGÉE ;
 *  - aucune alerte ne part sans résumé.
 */
class AlertFollowsConversationTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private RecordsSms $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->sms = new RecordsSms();
        $this->app->instance(SmsSender::class, $this->sms);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function school(): School
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['referent_phone' => '0600000001']);

        return $school;
    }

    /** L'adjudicateur est neutralisé : ces tests portent sur la chaîne, pas sur le second passage. */
    private function neutralAdjudicator(): void
    {
        $mock = Mockery::mock(Adjudicator::class);
        $mock->shouldReceive('adjudicate')->andReturn([
            'verdict' => 'confirmee', 'zone' => 'red', 'type' => null, 'signals' => [],
        ]);
        $this->app->instance(Adjudicator::class, $mock);
    }

    /** @param array<int,array<string,mixed>> $turns */
    private function fakeAi(array $turns): void
    {
        $ai = Mockery::mock(AIService::class);
        $expectation = $ai->shouldReceive('chat');
        $expectation->andReturn(...array_map(fn (array $t) => [
            'message'        => $t['message'] ?? 'Je comprends.',
            'zone'           => $t['zone'],
            'alert_type'     => $t['type'],
            'is_critical'    => false,
            'low_confidence' => false,
            'summary'        => $t['summary'] ?? null,
            'tokens'         => 5,
            'model'          => 'modele-test',
        ], $turns));
        $this->app->instance(AIService::class, $ai);
    }

    /**
     * LE test du scénario 3 : zone jaune/orange au départ, rouge trois messages plus
     * tard. L'alerte doit finir au bon niveau ET une notification doit partir.
     */
    public function test_a_conversation_that_turns_red_upgrades_the_alert_and_notifies(): void
    {
        $school = $this->school();
        $referent = $this->makeReferent($school);
        $admin    = $this->makeAdmin($school);
        $child    = Child::factory()->for($school)->create(['age' => 13, 'name' => 'TEST RECETTE 03']);
        $this->actingAs($child, 'child');

        $this->neutralAdjudicator();
        $this->fakeAi([
            ['zone' => 'orange', 'type' => 'detresse', 'summary' => "L'enfant dit qu'elle n'a plus envie de rien."],
            ['zone' => 'red', 'type' => 'pensees_negatives', 'summary' => "L'enfant exprime le souhait de disparaître et pense que les autres seraient mieux sans elle."],
        ]);

        $page = Livewire::test(ChatInterface::class)
            ->set('input', 'jsp jai plus envie de rien')->call('sendMessage')->call('fetchReply');

        $alert = Alert::where('session_id', $page->get('sessionId'))->firstOrFail();

        // État intermédiaire, conforme à la politique EXISTANTE : modérée non vitale,
        // personne n'est prévenu. Mais le résumé est là, dès la création.
        $this->assertSame('detresse', $alert->type);
        $this->assertSame('moderate', $alert->level);
        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->count());
        $this->assertStringContainsString("n'a plus envie de rien", (string) $alert->summary);

        // Trois messages plus tard, la conversation bascule.
        $page->set('input', 'jaimerai juste disparaitre')->call('sendMessage')->call('fetchReply');

        $alert->refresh();

        // Le type et le niveau ont suivi.
        $this->assertSame('pensees_negatives', $alert->type);
        $this->assertSame('critical', $alert->level);
        $this->assertSame(AlertPager::TIER_VITAL, (int) $alert->paged_tier);
        $this->assertStringContainsString('disparaître', (string) $alert->summary);

        // La chaîne de notification est partie — mêmes destinataires que pour toute
        // alerte vitale : référent (app + e-mail + SMS) et administration.
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'app')->where('recipient_id', $referent->id)->count());
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->count());
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.vital.notify')->where('target_id', $alert->id)->count());

        // Le pire moment est scellé côté session, pas seulement dans le navigateur.
        $session = $alert->session;
        $this->assertSame('red', $session->zone);
        $this->assertSame('pensees_negatives', $session->worst_alert_type);
        $this->assertSame('critical', $session->worst_level);
    }

    /** Une alerte ne redescend jamais : un message anodin après un signal grave ne l'adoucit pas. */
    public function test_an_alert_never_goes_back_down(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child = Child::factory()->for($school)->create(['age' => 12]);
        $this->actingAs($child, 'child');

        $this->neutralAdjudicator();
        $this->fakeAi([
            ['zone' => 'red', 'type' => 'danger', 'summary' => "L'enfant rapporte des violences à la maison."],
            ['zone' => 'green', 'type' => 'stress', 'summary' => "L'enfant parle ensuite du match de football de son école."],
        ]);

        $page = Livewire::test(ChatInterface::class)
            ->set('input', 'papa il tape maman')->call('sendMessage')->call('fetchReply');
        $alert = Alert::where('session_id', $page->get('sessionId'))->firstOrFail();

        $page->set('input', 'sinon on a gagné le match de foot')->call('sendMessage')->call('fetchReply');

        $alert->refresh();
        $this->assertSame('danger', $alert->type, 'Le type du pire moment doit survivre aux banalités qui suivent.');
        $this->assertSame('critical', $alert->level);
        $this->assertSame('red', $alert->session->zone);
        $this->assertSame('danger', $alert->session->worst_alert_type);
    }

    /**
     * Aucune notification deux fois pour le même palier : une alerte déjà notifiée au
     * référent qui devient vitale reçoit UNIQUEMENT les canaux du palier vital.
     */
    public function test_a_palier_already_served_is_never_notified_twice(): void
    {
        $school   = $this->school();
        $referent = $this->makeReferent($school);
        $admin    = $this->makeAdmin($school);
        $child    = Child::factory()->for($school)->create();
        $session  = \App\Models\ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id,
            'started_at' => now(), 'last_activity_at' => now(), 'zone' => 'orange',
        ]);

        $alert = Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'harcelement', 'level' => 'high', 'summary' => 'Moqueries répétées.',
        ]);

        // Palier référent servi : 1 notification dans l'application, 1 e-mail, 0 SMS.
        app(AlertPager::class)->page($alert);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'app')->count());
        $this->assertCount(0, $this->sms->sent);

        // Aggravation SANS changement de palier : rien de nouveau ne part.
        app(AlertUpgrader::class)->sync($alert->fresh(), 'harcelement', 'critical', 'Moqueries répétées et coups.');
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'app')->count());
        $this->assertCount(0, $this->sms->sent);

        // Passage en type vital : seuls les canaux du palier vital s'ajoutent.
        app(AlertUpgrader::class)->sync($alert->fresh(), 'danger', 'critical', 'Violence subie à la maison.');

        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'app')->where('recipient_id', $referent->id)->count(), 'Le référent ne doit pas être resonné dans l\'application.');
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->count(), 'Aucun second e-mail pour un palier déjà servi.');
        $this->assertCount(1, $this->sms->sent, 'Le SMS appartient au palier vital, il part une seule fois.');
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->count());

        // Rejouer le paging ne produit plus rien.
        app(AlertPager::class)->page($alert->fresh());
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->count());
    }

    /**
     * La politique de destination n'a pas changé : une alerte relevée de « faible » à
     * « modérée », non vitale, ne prévient toujours personne.
     */
    public function test_upgrading_to_a_non_notifying_level_still_notifies_nobody(): void
    {
        $school  = $this->school();
        $this->makeReferent($school);
        $child   = Child::factory()->for($school)->create();
        $session = \App\Models\ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id,
            'started_at' => now(), 'last_activity_at' => now(), 'zone' => 'yellow',
        ]);
        $alert = Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'stress', 'level' => 'low', 'summary' => 'Trac avant un contrôle.',
        ]);

        app(AlertUpgrader::class)->sync($alert, 'isolement', 'moderate', 'Se sent à l\'écart à la récréation.');

        $alert->refresh();
        $this->assertSame('moderate', $alert->level);
        $this->assertSame('isolement', $alert->type);
        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->count());
        $this->assertSame(AlertPager::TIER_NONE, (int) $alert->paged_tier);
        Mail::assertNothingSent();
    }

    /**
     * Le niveau est calculé pour le type observé AU TOUR, pas seulement pour le type
     * retenu. Les deux classements ne coïncident pas : `humiliation_adulte` est plus bas
     * que `harcelement` dans l'ordre des types, mais il vaut +2 facteurs de gravité
     * (base fondatrice B6 : humiliation par un adulte de l'école = orange minimum, type
     * dédié). Résoudre le niveau sur le seul type retenu ferait perdre ce +2 — donc
     * l'alerte resterait « modérée » et le référent ne serait pas prévenu.
     */
    public function test_an_adult_humiliation_after_another_signal_still_reaches_the_referent(): void
    {
        $school   = $this->school();
        $referent = $this->makeReferent($school);
        $child    = Child::factory()->for($school)->create(['age' => 10]);
        $this->actingAs($child, 'child');

        $this->neutralAdjudicator();
        $this->fakeAi([
            ['zone' => 'yellow', 'type' => 'harcelement', 'summary' => "Des camarades rient quand l'enfant parle."],
            ['zone' => 'orange', 'type' => 'humiliation_adulte', 'summary' => "L'enfant rapporte que son enseignante l'a rabaissé."],
        ]);

        $page = Livewire::test(ChatInterface::class)
            ->set('input', 'des eleves rigolent quand je parle')->call('sendMessage')->call('fetchReply');

        // Zone jaune : aucune alerte encore.
        $this->assertSame(0, Alert::where('session_id', $page->get('sessionId'))->count());

        $page->set('input', 'la maitresse ma rabaissé aujourdhui')->call('sendMessage')->call('fetchReply');

        $alert = Alert::where('session_id', $page->get('sessionId'))->firstOrFail();
        $this->assertSame('high', $alert->level, 'Une humiliation par un adulte de l\'école ne doit pas retomber en « modérée ».');
        $this->assertSame(AlertPager::TIER_REFERENT, (int) $alert->paged_tier);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $referent->id)->where('channel', 'app')->count());
    }

    /** Une alerte créée en cours de session porte son résumé dès la création. */
    public function test_an_alert_created_mid_session_carries_a_summary(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child = Child::factory()->for($school)->create(['age' => 6]);
        $this->actingAs($child, 'child');

        $this->neutralAdjudicator();
        $this->fakeAi([
            ['zone' => 'orange', 'type' => 'detresse', 'summary' => "L'enfant a peur et se cache dans sa chambre."],
        ]);

        $page = Livewire::test(ChatInterface::class)
            ->set('input', 'jai peur')->call('sendMessage')->call('fetchReply');

        $alert = Alert::where('session_id', $page->get('sessionId'))->firstOrFail();
        $this->assertNotEmpty(trim((string) $alert->summary), 'Une alerte ne doit jamais partir sans résumé.');
        $this->assertStringContainsString('se cache dans sa chambre', (string) $alert->summary);
    }
}
