<?php

namespace Tests\Feature\Child;

use App\Contracts\SmsSender;
use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\Adjudicator;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * Le navigateur de l'enfant ne reçoit plus l'état interne de la conversation
 * (correction du 2026-10-02).
 *
 * Avant, le composant du chat publiait au navigateur — dans le code de la page et dans
 * chaque réponse réseau — la pire zone atteinte, le type de signal, l'existence d'une
 * alerte et la mémoire de Care. Contrainte produit (Hamza, 2026-10-02) : l'enfant ne doit
 * pas comprendre que Care partage la situation et l'échange avec les parents et les
 * référents. L'état est désormais tenu côté serveur, sans rien changer à ce qu'il produit :
 * pire moment retenu, alerte mise à niveau, clôture au bon type et au bon résumé, mémoire
 * de Care transmise au modèle à chaque tour.
 */
class ChatStateHiddenTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    /** Les quatre éléments d'état que le navigateur de l'enfant ne doit jamais recevoir. */
    private const INTERNAL = ['currentZone', 'currentAlertType', 'alertCreated', 'childContext'];

    /** Souvenir neutre d'une conversation passée : il entre dans la mémoire de Care. */
    private const MEMORY = "L'enfant adore construire des cabanes en carton.";

    /** @var array<int,?string> mémoire de Care reçue par le modèle, tour par tour */
    private array $contexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(SmsSender::class, new RecordsSms());

        $adjudicator = Mockery::mock(Adjudicator::class);
        $adjudicator->shouldReceive('adjudicate')->andReturn([
            'verdict' => 'confirmee', 'zone' => 'orange', 'type' => null, 'signals' => [],
        ]);
        $this->app->instance(Adjudicator::class, $adjudicator);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Élève déjà venu : une conversation close d'avant-hier, avec un souvenir neutre. */
    private function returningChild(): Child
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 10, 'gender' => 'f']);
        ChatSession::create([
            'child_id'    => $child->id,
            'school_id'   => $school->id,
            'started_at'  => now()->subDays(2)->subMinutes(10),
            'ended_at'    => now()->subDays(2),
            'zone'        => 'green',
            'care_memory' => self::MEMORY,
        ]);
        $this->actingAs($child, 'child');

        return $child;
    }

    /**
     * @param array<int,array<string,mixed>> $turns réponses successives du modèle
     * @param array<string,mixed>|null $final analyse de clôture
     */
    private function fakeAi(array $turns, ?array $final = null): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('chat')->andReturnUsing(function ($messages, $age, $gender = null, $context = null) use (&$turns) {
            $this->contexts[] = $context;
            $t = array_shift($turns);

            return [
                'message'        => $t['message'],
                'zone'           => $t['zone'],
                'alert_type'     => $t['type'],
                'is_critical'    => false,
                'low_confidence' => false,
                'summary'        => $t['summary'],
                'tokens'         => 5,
                'model'          => 'modele-test',
            ];
        });
        $ai->shouldReceive('analyzeSession')->andReturn($final ?? [
            'summary' => 'Résumé final.', 'zone' => 'green', 'alert_type' => null, 'lowConfidence' => false, 'model' => 'modele-test',
        ]);
        $ai->shouldReceive('generateCareMemory')->andReturn(['memory' => '', 'tokens' => 0, 'model' => 'modele-test']);
        $this->app->instance(AIService::class, $ai);
    }

    private function orangeThenGreen(): array
    {
        return [
            ['message' => 'Merci de me le dire. Ça se passe souvent ?', 'zone' => 'orange', 'type' => 'harcelement',
             'summary' => "L'enfant raconte que des élèves se moquent d'elle chaque jour à la récréation."],
            ['message' => "D'accord. Et le reste de ta journée ?", 'zone' => 'green', 'type' => null,
             'summary' => "L'enfant a raconté des moqueries répétées, puis a parlé de sa journée."],
        ];
    }

    private function assertNothingInternal(string $payload, string $where): void
    {
        foreach (self::INTERNAL as $name) {
            $this->assertStringNotContainsString($name, $payload, "« {$name} » est visible côté navigateur ({$where}).");
        }
        // Contenu de la mémoire de Care et marqueur de son bloc (ASCII : survit à l'échappement JSON).
        $this->assertStringNotContainsString('cabanes en carton', $payload, "La mémoire de Care est visible ({$where}).");
        $this->assertStringNotContainsString('RAPPEL_EXPLICITE', $payload, "La mémoire de Care est visible ({$where}).");
    }

    public function test_the_chat_page_sent_to_the_browser_carries_no_internal_state(): void
    {
        $this->returningChild();
        $this->fakeAi([]);

        $html = $this->get('/chat')->assertOk()->getContent();

        $this->assertStringContainsString('wire:snapshot', $html);
        $this->assertNothingInternal($html, 'page du chat');
    }

    /** Le script du chat part tel quel dans le navigateur : ses commentaires aussi. */
    public function test_the_chat_script_sent_to_the_browser_says_nothing_about_the_mechanism(): void
    {
        $this->returningChild();
        $this->fakeAi([]);

        $html = $this->get('/chat')->assertOk()->getContent();
        preg_match('/wire:effects="([^"]*)"/', $html, $m);
        $effects = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true);
        $script = implode("\n", $effects['scripts'] ?? []);

        $this->assertStringContainsString("\$wire.on('focus-input'", $script, 'Le script du chat doit être celui de la page.');
        foreach (['beacon de clôture', 'résumé', 'alerte', 'préservé', 'référent', 'analyse', 'signalement'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $script, "Le script livré au navigateur mentionne « {$word} ».");
        }
    }

    public function test_no_round_trip_with_the_server_carries_the_internal_state(): void
    {
        $this->returningChild();
        $this->fakeAi($this->orangeThenGreen());

        $page = Livewire::test(ChatInterface::class);
        $this->assertSame([], array_intersect(self::INTERNAL, array_keys($page->snapshot['data'])));
        $this->assertNothingInternal(json_encode($page->snapshot), 'premier affichage');

        foreach (['des élèves se moquent de moi', 'sinon ça va'] as $message) {
            $page->set('input', $message)->call('sendMessage')->call('fetchReply');

            $this->assertSame([], array_intersect(self::INTERNAL, array_keys($page->snapshot['data'])));
            $payload = json_encode([$page->snapshot, $page->effects]);
            $this->assertNothingInternal($payload, "réponse au message « {$message} »");
            $this->assertStringNotContainsString('harcelement', $payload, 'Le type de signal est visible côté navigateur.');
        }

        $this->assertSame(1, Alert::where('session_id', $page->get('sessionId'))->count(), 'Le tour orange a bien créé une alerte.');
    }

    public function test_the_worst_moment_is_kept_and_the_closure_writes_the_right_type_and_summary(): void
    {
        $this->returningChild();
        $this->fakeAi($this->orangeThenGreen(), [
            'summary' => "Résumé de clôture : moqueries répétées à la récréation, l'enfant en a parlé.",
            'zone' => 'green', 'alert_type' => null, 'lowConfidence' => false, 'model' => 'modele-test',
        ]);

        $page = Livewire::test(ChatInterface::class);
        $page->set('input', 'des élèves se moquent de moi')->call('sendMessage')->call('fetchReply');
        $page->set('input', 'sinon ça va')->call('sendMessage')->call('fetchReply');

        $session = ChatSession::find($page->get('sessionId'));
        $this->assertSame('orange', $session->zone, 'Le pire moment de la session doit être retenu.');
        $this->assertSame('harcelement', $session->worst_alert_type);

        $page->call('endSession');

        $session->refresh();
        $alert = Alert::where('session_id', $session->id)->sole();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('orange', $session->zone);
        $this->assertSame('harcelement', $alert->type);
        $this->assertSame("Résumé de clôture : moqueries répétées à la récréation, l'enfant en a parlé.", $session->ai_summary);
        $this->assertSame($session->ai_summary, $alert->summary);
    }

    /**
     * La mémoire de Care est celle de l'ouverture du chat, à chaque tour : l'alerte créée
     * pendant la conversation n'y entre pas (elle n'appartient pas aux échanges précédents).
     */
    public function test_the_memory_of_care_reaches_the_model_unchanged_on_every_turn(): void
    {
        $child = $this->returningChild();
        // Un signal de harcèlement il y a dix jours : un seul, sous le seuil de récurrence.
        $this->makeAlert($child, ['type' => 'harcelement', 'created_at' => now()->subDays(10)]);
        $this->fakeAi($this->orangeThenGreen());

        $page = Livewire::test(ChatInterface::class);
        $page->set('input', 'des élèves se moquent de moi')->call('sendMessage')->call('fetchReply');
        $page->set('input', 'sinon ça va')->call('sendMessage')->call('fetchReply');

        $this->assertCount(2, $this->contexts);
        $this->assertNotNull($this->contexts[0]);
        $this->assertStringContainsString('cabanes en carton', $this->contexts[0]);
        $this->assertSame($this->contexts[0], $this->contexts[1]);
        $this->assertStringNotContainsString('harcèlement', $this->contexts[1]);
    }

    public function test_a_first_visit_still_sends_no_memory_to_the_model(): void
    {
        $school = School::factory()->create();
        $this->actingAs(Child::factory()->for($school)->create(['age' => 10]), 'child');
        $this->fakeAi([
            ['message' => 'Bonjour ! Comment tu vas ?', 'zone' => 'green', 'type' => null, 'summary' => null],
        ]);

        Livewire::test(ChatInterface::class)->set('input', 'salut')->call('sendMessage')->call('fetchReply');

        $this->assertSame([null], $this->contexts);
    }
}
