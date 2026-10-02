<?php

namespace Tests\Feature\Child;

use App\Contracts\SmsSender;
use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * Le navigateur d'un élève ne peut ni écrire dans la session d'un autre élève, ni la clore
 * (correction du 2026-10-02).
 *
 * Avant, le numéro de session du chat était une propriété publique modifiable par le
 * navigateur, et l'envoi d'un message, la réponse de Care et la fin de session écrivaient
 * sur la session indiquée sans vérifier qu'elle appartenait à l'élève connecté : en
 * changeant ce numéro, un élève pouvait abaisser la zone d'un camarade, remplacer son
 * résumé, gonfler son compteur de conversation et clore sa session.
 *
 * Deux protections, chacune verrouillée ici :
 *  - le navigateur ne peut plus modifier le numéro de session ;
 *  - chaque action vérifie que la session appartient à l'élève connecté, ce qui couvre
 *    aussi la page restée ouverte sur un poste partagé après la connexion d'un autre élève
 *    (numéro non falsifié, que le verrou ne peut pas voir).
 *
 * Les messages d'un élève ne sont jamais stockés en base : l'état d'une session tient
 * dans sa ligne (zone, pire type et niveau, résumé chiffré, compteur de conversation,
 * dates) et dans ses alertes. C'est cet état complet qui doit rester identique.
 */
class ChatSessionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_NAME = 'Zelphine';
    private const OTHER_NAME = 'Quorentin';
    private const MESSAGE = 'Texte de test qui ne doit atterrir dans aucune autre session';
    private const SUMMARY = "Résumé de la session du camarade : moqueries répétées à la récréation.";
    private const REPLY_SUMMARY = 'Résumé produit pendant la conversation de test.';

    private int $chatCalls = 0;
    private int $closeCalls = 0;

    /** @var array<int,MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(SmsSender::class, new RecordsSms());
        Event::listen(MessageLogged::class, function (MessageLogged $entry) {
            $this->logs[] = $entry;
        });

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('chat')->andReturnUsing(function () {
            $this->chatCalls++;

            return [
                'message' => "D'accord, je t'écoute.", 'zone' => 'green', 'alert_type' => null,
                'is_critical' => false, 'low_confidence' => false, 'summary' => self::REPLY_SUMMARY,
                'tokens' => 7, 'model' => 'modele-test',
            ];
        });
        $ai->shouldReceive('analyzeSession')->andReturnUsing(function () {
            $this->closeCalls++;

            return ['summary' => self::REPLY_SUMMARY, 'zone' => 'green', 'alert_type' => null, 'lowConfidence' => false, 'model' => 'modele-test'];
        });
        $ai->shouldReceive('generateCareMemory')->andReturn(['memory' => '', 'tokens' => 0, 'model' => 'modele-test']);
        $this->app->instance(AIService::class, $ai);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array{0:Child,1:Child} deux élèves de la même classe */
    private function twoClassmates(): array
    {
        $school = School::factory()->create();

        return [
            Child::factory()->for($school)->create(['name' => self::OWNER_NAME, 'age' => 10, 'classe' => 'CM1']),
            Child::factory()->for($school)->create(['name' => self::OTHER_NAME, 'age' => 10, 'classe' => 'CM1']),
        ];
    }

    /** Une session déjà marquée par un signal : zone orange, harcèlement, résumé, compteur, alerte. */
    private function markWithSignal(int $sessionId, Child $owner): void
    {
        ChatSession::findOrFail($sessionId)->fill([
            'zone'             => 'orange',
            'worst_alert_type' => 'harcelement',
            'worst_level'      => 'moderate',
            'ai_summary'       => self::SUMMARY,
            'tokens_used'      => 42,
        ])->save();

        Alert::create([
            'session_id' => $sessionId, 'child_id' => $owner->id, 'school_id' => $owner->school_id,
            'type' => 'harcelement', 'level' => 'moderate', 'summary' => self::SUMMARY,
        ]);
    }

    /** Session ouverte d'un élève, créée hors de tout navigateur, déjà marquée par un signal. */
    private function openSessionOf(Child $owner): ChatSession
    {
        $session = ChatSession::create([
            'child_id' => $owner->id, 'school_id' => $owner->school_id,
            'started_at' => now()->subMinutes(10), 'last_activity_at' => now()->subMinutes(5),
        ]);
        $this->markWithSignal($session->id, $owner);

        return $session;
    }

    /** État complet d'une session : ligne brute (résumé chiffré compris), résumé lu, alertes rattachées. */
    private function stateOf(int $sessionId): array
    {
        return [
            'ligne'   => (array) DB::table('chat_sessions')->where('id', $sessionId)->first(),
            'resume'  => ChatSession::findOrFail($sessionId)->ai_summary,
            'alertes' => DB::table('alerts')->where('session_id', $sessionId)->orderBy('id')->get()
                ->map(fn ($alert) => (array) $alert)->all(),
        ];
    }

    /** Le refus est journalisé une fois, avec la seule action refusée, et le journal ne contient aucune donnée d'élève. */
    private function assertRefusalLogged(string $action, Child ...$children): void
    {
        $refusals = array_values(array_filter(
            $this->logs,
            fn (MessageLogged $entry) => ($entry->context['action'] ?? null) === $action,
        ));
        $this->assertCount(1, $refusals, "Le refus de « {$action} » doit être journalisé une fois.");
        $this->assertSame('warning', $refusals[0]->level);
        $this->assertSame(['action' => $action], $refusals[0]->context, 'Le journal ne porte que l\'action refusée.');

        $journal = json_encode(
            array_map(fn (MessageLogged $entry) => [$entry->message, $entry->context], $this->logs),
            JSON_UNESCAPED_UNICODE,
        );
        foreach ($children as $child) {
            $this->assertStringNotContainsString($child->name, $journal);
            $this->assertStringNotContainsString($child->email, $journal);
        }
        foreach ([self::MESSAGE, self::SUMMARY, 'harcelement', 'orange'] as $data) {
            $this->assertStringNotContainsString($data, $journal);
        }
    }

    // ── (a) Le navigateur ne peut plus modifier le numéro de session ──────────────────────

    public function test_the_browser_cannot_change_the_session_number_of_the_chat(): void
    {
        [$attacker, $classmate] = $this->twoClassmates();
        $target = $this->openSessionOf($classmate);
        $this->actingAs($attacker, 'child');

        $page = Livewire::test(ChatInterface::class);

        $locked = null;
        try {
            $page->set('sessionId', $target->id);
        } catch (CannotUpdateLockedPropertyException $e) {
            $locked = $e;
        }

        $this->assertNotNull($locked, 'Le navigateur a pu changer le numéro de session du chat.');
        $this->assertSame('sessionId', $locked->property);
    }

    /**
     * Scénario de la demande : viser la session d'un camarade, puis y écrire, y faire répondre
     * Care, la clore. Chaque tentative part d'une page neuve, pour que le scénario tienne
     * avec l'une OU l'autre protection : il échoue seulement si aucune ne joue.
     */
    public function test_a_child_who_targets_a_classmates_session_can_neither_write_in_it_nor_close_it(): void
    {
        [$attacker, $classmate] = $this->twoClassmates();
        $target = $this->openSessionOf($classmate);
        $before = $this->stateOf($target->id);
        $this->actingAs($attacker, 'child');
        $this->travel(2)->minutes();

        $aim = function ($page) use ($target) {
            try {
                $page->set('sessionId', $target->id);
            } catch (CannotUpdateLockedPropertyException) {
                // Refus attendu : la page garde la session de l'élève connecté.
            }

            return $page;
        };

        $attempts = [
            'écrire'          => fn () => $aim(Livewire::test(ChatInterface::class))
                ->set('input', self::MESSAGE)->call('sendMessage'),
            'faire répondre'  => fn () => $aim(Livewire::test(ChatInterface::class)
                ->set('input', self::MESSAGE)->call('sendMessage'))->call('fetchReply'),
            'clore'           => fn () => $aim(Livewire::test(ChatInterface::class))->call('endSession'),
        ];

        foreach ($attempts as $what => $attempt) {
            $attempt();
            $this->assertSame($before, $this->stateOf($target->id), "Tentative « {$what} » : la session du camarade a été modifiée.");
        }

        $this->assertNull(ChatSession::findOrFail($target->id)->ended_at, 'La session du camarade a été close.');
        $this->assertSame(1, Alert::where('session_id', $target->id)->count());
    }

    /** Après une tentative refusée, la page de l'élève continue de fonctionner sur SA session, et seulement la sienne. */
    public function test_after_a_refused_attempt_the_chat_keeps_working_on_the_childs_own_session(): void
    {
        [$attacker, $classmate] = $this->twoClassmates();
        $target = $this->openSessionOf($classmate);
        $before = $this->stateOf($target->id);
        $this->actingAs($attacker, 'child');

        $page = Livewire::test(ChatInterface::class);
        $ownSessionId = $page->get('sessionId');
        try {
            $page->set('sessionId', $target->id);
        } catch (CannotUpdateLockedPropertyException) {
        }
        $page->set('input', self::MESSAGE)->call('sendMessage')->call('fetchReply')->call('endSession');

        $own = ChatSession::findOrFail($ownSessionId);
        $this->assertSame($attacker->id, $own->child_id);
        $this->assertNotNull($own->ended_at, "L'élève clôt bien sa propre session.");
        $this->assertSame(7, $own->tokens_used, 'Le tour de conversation est compté sur sa propre session.');
        $this->assertSame($before, $this->stateOf($target->id));
    }

    // ── (b) Chaque action vérifie que la session appartient à l'élève connecté ───────────
    // Poste partagé : la page du chat d'un élève est restée ouverte, un autre élève s'est
    // connecté entre-temps. La page porte une session qui n'est plus celle de l'élève
    // connecté, sans aucune falsification : le verrou ne la voit pas.

    public function test_sending_a_message_is_refused_on_a_session_that_is_not_the_connected_childs(): void
    {
        [$owner, $other] = $this->twoClassmates();
        $this->actingAs($owner, 'child');
        $page = Livewire::test(ChatInterface::class);
        $sessionId = $page->get('sessionId');
        $this->markWithSignal($sessionId, $owner);
        $before = $this->stateOf($sessionId);

        $this->travel(2)->minutes();
        $this->actingAs($other, 'child');
        $this->logs = [];
        $page->set('input', self::MESSAGE)->call('sendMessage');

        $this->assertSame($before, $this->stateOf($sessionId), "La session de l'autre élève a été modifiée.");
        $page->assertForbidden();
        $this->assertSame(0, RateLimiter::attempts(ChatInterface::rateLimitKey($other->id)), 'Rien ne doit être écrit, pas même le compteur de débit.');
        $this->assertRefusalLogged('sendMessage', $owner, $other);
    }

    public function test_the_reply_of_care_is_refused_on_a_session_that_is_not_the_connected_childs(): void
    {
        [$owner, $other] = $this->twoClassmates();
        $this->actingAs($owner, 'child');
        $page = Livewire::test(ChatInterface::class);
        $sessionId = $page->get('sessionId');
        $this->markWithSignal($sessionId, $owner);
        $page->set('input', 'bonjour')->call('sendMessage');
        $this->assertTrue($page->get('isTyping'), 'Témoin : la réponse de Care est attendue.');
        $before = $this->stateOf($sessionId);

        $this->travel(2)->minutes();
        $this->actingAs($other, 'child');
        $this->logs = [];
        $page->call('fetchReply');

        $this->assertSame($before, $this->stateOf($sessionId), "La session de l'autre élève a été modifiée.");
        $this->assertSame(0, $this->chatCalls, 'Aucune conversation ne doit partir vers le modèle.');
        $page->assertForbidden();
        $this->assertRefusalLogged('fetchReply', $owner, $other);
    }

    public function test_ending_the_session_is_refused_on_a_session_that_is_not_the_connected_childs(): void
    {
        [$owner, $other] = $this->twoClassmates();
        $this->actingAs($owner, 'child');
        $page = Livewire::test(ChatInterface::class);
        $sessionId = $page->get('sessionId');
        $this->markWithSignal($sessionId, $owner);
        $before = $this->stateOf($sessionId);

        $this->travel(2)->minutes();
        $this->actingAs($other, 'child');
        $this->logs = [];
        $page->call('endSession');

        $this->assertSame($before, $this->stateOf($sessionId), "La session de l'autre élève a été modifiée.");
        $this->assertNull(ChatSession::findOrFail($sessionId)->ended_at, "La session de l'autre élève a été close.");
        $this->assertSame(0, $this->closeCalls, 'Aucune analyse de clôture ne doit partir vers le modèle.');
        $page->assertForbidden();
        $this->assertRefusalLogged('endSession', $owner, $other);
    }

    // ── hydrate() ne relit que la session de l'élève connecté ─────────────────────────────

    public function test_hydrate_reads_the_worst_moment_only_from_the_connected_childs_session(): void
    {
        [$owner, $other] = $this->twoClassmates();
        $this->actingAs($owner, 'child');
        $page = Livewire::test(ChatInterface::class);
        $sessionId = $page->get('sessionId');
        $this->markWithSignal($sessionId, $owner);

        $internal = fn () => (function () {
            return [$this->currentZone, $this->currentAlertType, $this->alertCreated];
        })->call($page->instance());

        // Témoin : pour son propriétaire, la session est bien relue à chaque requête.
        $page->call('$refresh');
        $this->assertSame(['orange', 'harcelement', true], $internal());

        // Pour un autre élève, rien de cette session ne remonte dans l'état du composant.
        $this->actingAs($other, 'child');
        $page->call('$refresh');
        $this->assertSame(['green', null, false], $internal());
    }
}
