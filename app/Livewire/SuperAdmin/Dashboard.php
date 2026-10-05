<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\ChatSession;
use App\Models\School;
use App\Services\Audit;
use App\Services\TokenBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tableau de bord des fondateurs (bead hp-wulm) : des CHIFFRES par école, jamais un nom
 * d'enfant, un identifiant d'élève, une conversation ou un résumé.
 *
 * « Jetons échangés » = somme de chat_sessions.tokens_used : le texte échangé seulement,
 * pas la consommation facturée par le fournisseur (découverte D4). Les sessions d'avant le
 * 22/09/2026 sont exclues (ancienne unité du compteur, migration 2026_09_22_000001).
 * Plafond : jours où un élève l'a atteint sur les 7 derniers jours, sans aucune étiquette.
 */
#[Layout('layouts.superadmin')]
class Dashboard extends Component
{
    use RequiresSuperAdmin;

    /** Début de la mesure fiable du compteur (correction du 21-22/09/2026). */
    public const COUNTER_RELIABLE_FROM = '2026-09-22 00:00:00';

    public const PERIODS = ['1' => "Aujourd'hui", '7' => '7 derniers jours', '30' => '30 derniers jours'];

    #[Url]
    public string $period = '7';

    public function mount(): void
    {
        Audit::log('superadmin.view.dashboard');
    }

    private function since(int $days): Carbon
    {
        $start = now()->startOfDay()->subDays($days - 1);
        $floor = Carbon::parse(self::COUNTER_RELIABLE_FROM);

        return $start->lt($floor) ? $floor : $start;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $days  = array_key_exists($this->period, self::PERIODS) ? (int) $this->period : 7;
        $since = $this->since($days);
        $week  = $this->since(7);

        $usage = ChatSession::query()
            ->where('started_at', '>=', $since)
            ->groupBy('school_id')
            ->select('school_id', DB::raw('COUNT(*) as sessions'), DB::raw('COUNT(DISTINCT child_id) as pupils'), DB::raw('COALESCE(SUM(tokens_used), 0) as tokens'))
            ->get()->keyBy('school_id');

        $perDay = ChatSession::query()
            ->where('started_at', '>=', $week)
            ->groupBy('school_id', 'child_id', DB::raw('DATE(started_at)'))
            ->select('school_id', 'child_id', DB::raw('SUM(tokens_used) as tokens'))
            ->get()->groupBy('school_id');

        return School::with('setting')->orderBy('name')->get()->map(function (School $school) use ($usage, $perDay) {
            $cap = app(TokenBudget::class)->cap($school);
            $atCap = ($perDay->get($school->id) ?? collect())
                ->filter(fn ($r) => $cap > 0 && (int) $r->tokens >= $cap);
            $u = $usage->get($school->id);

            return [
                'school_id'  => $school->id,
                'name'       => $school->name,
                'city'       => $school->city,
                'sessions'   => (int) ($u->sessions ?? 0),
                'pupils'     => (int) ($u->pupils ?? 0),
                'tokens'     => (int) ($u->tokens ?? 0),
                'cap'        => $cap,
                'cap_days'   => $atCap->count(),
                'cap_pupils' => $atCap->pluck('child_id')->unique()->count(),
            ];
        });
    }

    public function render()
    {
        $rows = $this->rows();

        return view('livewire.superadmin.dashboard', [
            'rows'   => $rows,
            'totals' => [
                'sessions' => $rows->sum('sessions'),
                'pupils'   => $rows->sum('pupils'),
                'tokens'   => $rows->sum('tokens'),
                'cap_days' => $rows->sum('cap_days'),
            ],
        ])->title('Tableau de bord');
    }
}
