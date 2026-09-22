<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\TokenBudget;
use App\Services\UsageReset;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * D8 (MVP v3) — remise en cohérence des données d'usage, à la demande.
 *
 * Même traitement que la migration `2026_09_22_000001_reset_legacy_token_usage`,
 * rejouable quand on veut, pour une école ou pour toutes : personne n'a jamais
 * à écrire de SQL à la main pour débloquer un plafond journalier.
 *
 *   php artisan carenest:reset-usage --force
 *   php artisan carenest:reset-usage --school=3 --force
 */
class ResetUsage extends Command
{
    protected $signature = 'carenest:reset-usage
                            {--school= : Identifiant de l\'école à traiter (toutes par défaut)}
                            {--force : Exécute sans demander de confirmation}';

    protected $description = "Remet le plafond journalier au défaut lorsqu'il est hérité de l'ancienne unité, et remet à zéro les compteurs de tokens antérieurs au changement d'unité.";

    public function handle(UsageReset $reset): int
    {
        $schoolId = $this->option('school');

        if ($schoolId !== null) {
            if (! ctype_digit((string) $schoolId)) {
                $this->error("L'option --school attend un identifiant d'école numérique.");

                return self::FAILURE;
            }

            $schoolId = (int) $schoolId;

            $school = School::find($schoolId);
            if ($school === null) {
                $this->error("Aucune école ne porte l'identifiant {$schoolId}.");

                return self::FAILURE;
            }

            $perimetre = "l'école « {$school->name} » (#{$schoolId})";
        } else {
            $perimetre = 'toutes les écoles';
        }

        $seuil   = number_format(UsageReset::LEGACY_CAP_THRESHOLD, 0, ',', ' ');
        $defaut  = number_format(TokenBudget::DEFAULT_CAP, 0, ',', ' ');
        $bascule = Carbon::parse(UsageReset::UNIT_CHANGED_AT)->format('d/m/Y H:i');

        $this->line("Périmètre : {$perimetre}.");
        $this->line("Plafonds strictement inférieurs à {$seuil} (hors 0, qui signifie « désactivé ») remis à {$defaut}.");
        $this->line("Compteurs des sessions créées avant le {$bascule} (UTC) remis à zéro.");

        if (! $this->option('force')) {
            // Hors terminal (hook de déploiement, cron, CI), `confirm()` ne pose
            // aucune question et retourne le défaut : la commande ne ferait rien
            // en annonçant un succès, et l'installation resterait bloquée. On le
            // dit et on échoue, plutôt que de rendre un vert mensonger.
            if (! $this->input->isInteractive()) {
                $this->error('Exécution non interactive : ajoutez --force pour confirmer. Aucune donnée modifiée.');

                return self::FAILURE;
            }

            if (! $this->confirm('Confirmer ?', false)) {
                $this->warn('Abandon : aucune donnée modifiée.');

                return self::SUCCESS;
            }
        }

        $ecoles   = $reset->resetLegacyCaps($schoolId);
        $sessions = $reset->resetLegacyCounters($schoolId);

        $this->newLine();
        $this->info("Plafonds journaliers remis à {$defaut} : {$ecoles} école(s).");
        $this->info("Compteurs de tokens remis à zéro : {$sessions} session(s).");

        return self::SUCCESS;
    }
}
