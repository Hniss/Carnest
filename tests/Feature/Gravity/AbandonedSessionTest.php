<?php

namespace Tests\Feature\Gravity;

use App\Contracts\SmsSender;
use App\Jobs\CloseIdleSessions;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AppNotification;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Services\AlertPager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * Une session abandonnée reçoit la même analyse qu'une session close.
 *
 * Scénario 12 de la recette réelle du 28/09 : l'enfant part sans rien fermer après
 * avoir écrit « hier j'ai pensé à tout arrêter » et « j'ai personne à qui parler chez
 * moi ». La session n'était fermée que par la tâche planifiée, et le résumé écrit
 * était un texte figé — « Session terminée automatiquement (inactivité). Pire zone
 * observée pendant la session : red. » — sans aucun contenu. Le type d'alerte était
 * écrit en dur à « detresse » et le niveau déduit d'un mappage grossier.
 *
 * Le résumé courant (écrit pendant la session, dans le même appel au modèle que la
 * zone et le type) existe désormais côté serveur avant le départ de l'enfant : c'est
 * lui qui sert de matière, et le type comme le niveau sont ceux réellement observés.
 */
class AbandonedSessionTest extends TestCase
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

    private function school(): School
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['referent_phone' => '0600000001']);

        return $school;
    }

    /** @param array<string,mixed> $attributes */
    private function abandoned(Child $child, array $attributes): ChatSession
    {
        return ChatSession::create(array_merge([
            'child_id'         => $child->id,
            'school_id'        => $child->school_id,
            'started_at'       => now()->subMinutes(15),
            'last_activity_at' => now()->subMinutes(7),
        ], $attributes));
    }

    /** Le type et le niveau retenus sont les vrais, et le résumé est celui de la conversation. */
    public function test_an_abandoned_session_keeps_the_real_type_level_and_summary(): void
    {
        $school   = $this->school();
        $referent = $this->makeReferent($school);
        $admin    = $this->makeAdmin($school);
        $child    = Child::factory()->for($school)->create(['age' => 13]);

        $session = $this->abandoned($child, [
            'zone'             => 'red',
            'worst_alert_type' => 'pensees_negatives',
            'worst_level'      => 'critical',
            'ai_summary'       => "L'enfant a dit avoir pensé à tout arrêter et n'avoir personne à qui parler chez lui.",
        ]);

        (new CloseIdleSessions())->handle();

        $alert = Alert::where('session_id', $session->id)->firstOrFail();
        $this->assertSame('pensees_negatives', $alert->type, 'Le type ne doit plus être écrit en dur à « detresse ».');
        $this->assertSame('critical', $alert->level);
        $this->assertStringContainsString('tout arrêter', (string) $alert->summary);

        // Le résumé de la session n'est pas remplacé par un constat de fermeture.
        $session->refresh();
        $this->assertStringContainsString('tout arrêter', (string) $session->ai_summary);
        $this->assertStringNotContainsString('Session terminée automatiquement', (string) $session->ai_summary);
        $this->assertNotNull($session->ended_at);

        // Même chaîne de notification qu'une session close proprement (type vital).
        $this->assertSame(AlertPager::TIER_VITAL, (int) $alert->paged_tier);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $referent->id)->where('channel', 'app')->count());
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->count());
    }

    /** Une alerte ouverte pendant la session est mise à niveau, pas laissée figée. */
    public function test_an_abandoned_session_upgrades_the_alert_opened_during_the_session(): void
    {
        $school   = $this->school();
        $referent = $this->makeReferent($school);
        $child    = Child::factory()->for($school)->create(['age' => 11]);

        $session = $this->abandoned($child, [
            'zone'             => 'red',
            'worst_alert_type' => 'danger',
            'worst_level'      => 'critical',
            'ai_summary'       => "L'enfant décrit des coups reçus à la maison.",
        ]);

        // Alerte posée au premier signal, comme aujourd'hui : modérée, non vitale.
        $alert = Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'detresse', 'level' => 'moderate',
        ]);
        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->count());

        (new CloseIdleSessions())->handle();

        $alert->refresh();
        $this->assertSame('danger', $alert->type);
        $this->assertSame('critical', $alert->level);
        $this->assertStringContainsString('coups reçus', (string) $alert->summary);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $referent->id)->where('channel', 'app')->count());
        $this->assertSame(1, Alert::where('session_id', $session->id)->count(), 'Une seule alerte par session.');
    }

    /** Sans aucun résumé disponible, on le DIT — on ne fait pas passer un constat pour une analyse. */
    public function test_an_abandoned_session_without_summary_says_so_explicitly(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['age' => 9]);

        $session = $this->abandoned($child, ['zone' => 'orange']);

        (new CloseIdleSessions())->handle();

        $session->refresh();
        $this->assertStringNotContainsString('Session terminée automatiquement', (string) $session->ai_summary);
        $this->assertStringContainsString('Fin d\'échange non observée', (string) $session->ai_summary);
        $this->assertTrue((bool) $session->low_confidence);

        $alert = Alert::where('session_id', $session->id)->firstOrFail();
        $this->assertNotEmpty(trim((string) $alert->summary), 'Même dans ce cas, l\'alerte porte un texte, jamais le vide.');
    }
}
