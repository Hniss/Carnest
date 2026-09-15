<?php

namespace App\Jobs;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Services\Adjudicator;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Lot 2 §1 — adjudication d'une alerte APRÈS la réponse à l'enfant.
 *
 * Ce job n'implémente volontairement PAS ShouldQueue : il est exécuté via
 * dispatchAfterResponse() (en mémoire, une fois la réponse envoyée), jamais
 * sérialisé dans une file. L'historique de conversation ne transite qu'en
 * mémoire : il n'est ni stocké ni journalisé.
 *
 * Règles :
 *  - accord → adjudication = confirmee ;
 *  - désaccord sur un type vital → l'alerte part quand même, confirmee ;
 *  - désaccord sur un autre type → a_confirmer (file du référent) ;
 *  - échec technique → a_confirmer + chat_sessions.low_confidence = true (défaut « monter ») ;
 *  - idempotence : une seule adjudication par alerte.
 */
class AdjudicateSignal
{
    use Dispatchable;

    /** @param array<int,array{role:string,content:string}> $messages */
    public function __construct(
        private readonly int $alertId,
        private readonly array $messages,
        private readonly string $proposedZone,
        private readonly ?string $proposedType,
    ) {}

    public function handle(Adjudicator $adjudicator): void
    {
        $alert = Alert::find($this->alertId);
        if ($alert === null || ! in_array($alert->adjudication, [null, 'non_applicable'], true)) {
            return;
        }

        $type = AlertType::tryFrom((string) $alert->type);

        try {
            $result = $adjudicator->adjudicate($this->messages, $this->proposedZone, $this->proposedType ?? $alert->type);
        } catch (\Throwable $e) {
            Log::warning('Adjudication technique en échec', ['alert' => $alert->id, 'error' => $e->getMessage()]);
            $alert->update(['adjudication' => 'a_confirmer']);
            $alert->session?->update(['low_confidence' => true]);
            return;
        }

        $confirmed = $result['verdict'] === 'confirmee' || ($type?->isVital() ?? false);

        $alert->update([
            'adjudication' => $confirmed ? 'confirmee' : 'a_confirmer',
            'signals'      => $result['signals'],
        ]);
    }
}
