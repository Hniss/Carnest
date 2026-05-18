<?php
namespace App\Jobs;

use App\Enums\ZoneScore;
use App\Models\ChatSession;
use App\Models\Child;
use App\Services\ChildStatusResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSessionClosure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ChatSession $session) {}

    public function handle(ChildStatusResolver $statusResolver): void
    {
        $child = $this->session->child;

        $scoreEnfant = $this->calculateScoreEnfant($child);

        $child->score_enfant    = $scoreEnfant;
        $child->last_session_at = $this->session->ended_at;

        // P5 (V4) : statut à 4 paliers, override par alertes critical/high non résolues 7j.
        $child->status = $statusResolver->resolve($scoreEnfant, $child);

        $child->save();
    }

    private function calculateScoreEnfant(Child $child): ?float
    {
        $sessions = $child->chatSessions()
            ->whereNotNull('ended_at')
            ->whereNotNull('zone')
            ->where('ended_at', '>=', now()->subDays(7))
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        $total = $sessions->sum(fn ($s) => ZoneScore::fromZone((string) $s->zone));

        return $total / $sessions->count();
    }
}
