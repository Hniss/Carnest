<?php

namespace Tests\Feature\Gravity;

use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AlertPager;
use App\Services\SignalSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration des valeurs déjà en base — la signification de trois données change.
 *
 * Sans ces conversions, la base resterait porteuse de valeurs de l'ancienne
 * définition : alertes sans résumé, sessions sans type du pire moment, et surtout
 * alertes déjà notifiées que le nouveau mécanisme de paliers renotifierait.
 * C'est exactement l'incident du plafond de jetons du 22/09 : code sain, base
 * polluée, symptôme persistant chez le testeur.
 *
 * Le jeu de test est monté à la main puis la migration est REJOUÉE dessus.
 */
class GravityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_29_000001_signal_gravity_summary_and_paged_tier';

    /** Rejoue la seule migration de gravité sur les données déjà présentes. */
    private function replayMigration(): void
    {
        Artisan::call('migrate:rollback', [
            '--path'  => 'database/migrations/' . self::MIGRATION . '.php',
            '--force' => true,
        ]);

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('chat_sessions', 'worst_alert_type'),
            'Le rollback de la migration doit bien retirer les colonnes avant de la rejouer.'
        );

        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        Artisan::call('migrate', [
            '--path'  => 'database/migrations/' . self::MIGRATION . '.php',
            '--force' => true,
        ]);
    }

    private function seedLegacy(): array
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();

        // Session dont le résumé existe, alerte partie sans résumé (le défaut B3).
        $withSummary = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'zone' => 'red',
            'started_at' => now()->subDay(), 'ended_at' => now()->subDay(),
            'ai_summary' => "L'enfant a exprimé des pensées très préoccupantes sur son existence.",
        ]);
        $alertWithSessionSummary = Alert::create([
            'session_id' => $withSummary->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'detresse', 'level' => 'moderate',
        ]);

        // Session sans aucun résumé : rien à recopier.
        $withoutSummary = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'zone' => 'orange',
            'started_at' => now()->subDay(), 'ended_at' => now()->subDay(),
        ]);
        $alertWithoutAnySummary = Alert::create([
            'session_id' => $withoutSummary->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'isolement', 'level' => 'moderate',
        ]);

        // Alerte vitale DÉJÀ notifiée : elle ne doit pas être renotifiée après bascule.
        $notified = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'zone' => 'red',
            'started_at' => now()->subDay(), 'ended_at' => now()->subDay(),
            'ai_summary' => 'Violence subie rapportée par l\'enfant.',
        ]);
        $alertAlreadyNotified = Alert::create([
            'session_id' => $notified->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'danger', 'level' => 'critical', 'summary' => 'Violence subie rapportée par l\'enfant.',
        ]);
        AlertNotification::create([
            'alert_id' => $alertAlreadyNotified->id, 'channel' => 'app',
            'recipient_id' => null, 'escalation_step' => 0, 'sent_at' => now()->subDay(),
        ]);

        return compact('alertWithSessionSummary', 'alertWithoutAnySummary', 'alertAlreadyNotified', 'withSummary', 'withoutSummary');
    }

    /** M2 — plus aucune alerte sans résumé ; celui de la session est recopié quand il existe. */
    public function test_existing_alerts_without_summary_get_one(): void
    {
        $seed = $this->seedLegacy();

        $this->replayMigration();

        $recovered = $seed['alertWithSessionSummary']->fresh();
        $this->assertStringContainsString('pensées très préoccupantes', (string) $recovered->summary);

        // Rien à recopier : mention explicite, pour que le référent sache qu'il regarde
        // un défaut historique et non un signal vide.
        $explicit = $seed['alertWithoutAnySummary']->fresh();
        $this->assertStringContainsString('Résumé indisponible', (string) $explicit->summary);
        $this->assertStringContainsString('antérieure à la correction', (string) $explicit->summary);
    }

    /** M3 — le type et le niveau du pire moment sont repris de l'alerte liée, jamais devinés. */
    public function test_sessions_recover_the_worst_signal_from_their_alert(): void
    {
        $seed = $this->seedLegacy();

        $this->replayMigration();

        $this->assertSame('detresse', $seed['withSummary']->fresh()->worst_alert_type);
        $this->assertSame('moderate', $seed['withSummary']->fresh()->worst_level);

        // Session sans alerte : rien n'est inventé.
        $orphan = ChatSession::create([
            'child_id' => $seed['withSummary']->child_id, 'school_id' => $seed['withSummary']->school_id,
            'zone' => 'green', 'started_at' => now(), 'ended_at' => now(),
        ]);
        $this->assertNull($orphan->fresh()->worst_alert_type);
        $this->assertNull($orphan->fresh()->worst_level);
    }

    /** M1 — une alerte déjà notifiée est marquée à son palier : elle ne sera pas renotifiée. */
    public function test_already_notified_alerts_are_not_renotified_after_the_switch(): void
    {
        $seed = $this->seedLegacy();

        $this->replayMigration();

        $alert = $seed['alertAlreadyNotified']->fresh();
        $this->assertSame(AlertPager::TIER_VITAL, (int) $alert->paged_tier);

        // Nouveau paging : rien ne part, le palier est déjà servi.
        $before = AlertNotification::where('alert_id', $alert->id)->count();
        app(AlertPager::class)->page($alert);
        $this->assertSame($before, AlertNotification::where('alert_id', $alert->id)->count());

        // Une alerte jamais notifiée reste au palier zéro : son sort dépendra de sa nature.
        $this->assertSame(AlertPager::TIER_NONE, (int) $seed['alertWithoutAnySummary']->fresh()->paged_tier);
    }

    /** Aucune alerte rétroactive n'est créée par la migration : un vieux signal ne se notifie pas. */
    public function test_the_migration_creates_no_retroactive_alert(): void
    {
        $seed = $this->seedLegacy();
        $before = Alert::count();

        // Session ancienne restée ouverte, en zone rouge, sans alerte.
        $child = Child::find($seed['withSummary']->child_id);
        ChatSession::create([
            'child_id' => $child->id, 'school_id' => $child->school_id, 'zone' => 'red',
            'started_at' => now()->subWeeks(3), 'last_activity_at' => now()->subWeeks(3),
        ]);

        $this->replayMigration();

        $this->assertSame($before, Alert::count());
    }

    /** L'échelle de gravité ne redescend jamais — c'est la base fondatrice B4. */
    public function test_severity_is_monotonic(): void
    {
        $this->assertSame('danger', SignalSeverity::maxType('danger', 'stress'));
        $this->assertSame('pensees_negatives', SignalSeverity::maxType('detresse', 'pensees_negatives'));
        // Deux types de même rang ne se remplacent pas mutuellement d'un tour à l'autre.
        $this->assertSame('danger', SignalSeverity::maxType('danger', 'pensees_negatives'));
        // Des coups entre enfants (harcèlement) ne se font pas écraser par une tristesse.
        $this->assertSame('harcelement', SignalSeverity::maxType('harcelement', 'detresse'));
        // Valeur inconnue ou nulle : ignorée.
        $this->assertSame('isolement', SignalSeverity::maxType('isolement', 'inconnu'));
        $this->assertNull(SignalSeverity::maxType(null, null));

        $this->assertSame('critical', SignalSeverity::maxLevel('critical', 'low'));
        $this->assertSame('high', SignalSeverity::maxLevel('moderate', 'high'));
        $this->assertSame('moderate', SignalSeverity::maxLevel('moderate', null));

        $this->assertSame('critical', SignalSeverity::levelFromZone('red'));
        $this->assertSame('moderate', SignalSeverity::levelFromZone('orange'));
    }
}
