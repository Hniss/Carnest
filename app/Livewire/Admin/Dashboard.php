<?php
namespace App\Livewire\Admin;

use App\Enums\AlertType;
use App\Enums\ZoneScore;
use App\Models\Alert;
use App\Models\AlertLifecycle;
use App\Models\ChatSession;
use App\Models\Child;
use App\Services\Audit;
use App\Services\BusinessTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tableau de bord administration RÉDUIT (lot 1 §4) : aucun nom d'élève —
 * score climat, tendance, zones par classe (≥ 5 élèves), alertes par gravité
 * et statut, temps moyen de qualification. Seule exception : alertes vitales
 * sans accusé du référent depuis plus de 60 minutes ouvrées (nom + type),
 * chaque affichage tracé (`admin.vital.view`).
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    public const MIN_CLASS_SIZE = 5;
    public const VITAL_ACK_MINUTES = 60;

    /**
     * D10 (v3) — cloisonnement multi-école : l'alerte doit appartenir à une
     * école de l'utilisateur connecté, sinon 403. Conservé pour compatibilité ;
     * l'interface administration n'affiche plus d'alerte individuelle.
     */
    public function resolveAlert(int $alertId): void
    {
        $alert = Alert::find($alertId);
        abort_unless($alert, 404);

        $userSchoolIds = Auth::user()->schools()->pluck('schools.id');
        abort_unless($userSchoolIds->contains($alert->school_id), 403);

        $alert->update(['status' => 'resolved']);
    }

    public function render()
    {
        $user   = Auth::user();
        $school = $user->schools()->with('setting')->first();
        $schoolId = $school?->id;

        $children = $schoolId ? Child::where('school_id', $schoolId)->get(['id', 'classe', 'score_enfant', 'last_session_at', 'deactivated_at']) : collect();
        $climateScore = $children->whereNotNull('score_enfant')->avg('score_enfant');

        // Tendance : moyenne des zones des sessions closes sur 7 j vs 7 j précédents.
        $avgZone = fn ($from, $to) => $schoolId
            ? ChatSession::where('school_id', $schoolId)->whereNotNull('ended_at')->whereNotNull('zone')
                ->whereBetween('ended_at', [$from, $to])->pluck('zone')->map(fn ($z) => ZoneScore::fromZone($z))->avg()
            : null;
        $current  = $avgZone(now()->subDays(7), now());
        $previous = $avgZone(now()->subDays(14), now()->subDays(7));
        $trend = ($current === null || $previous === null) ? 'unknown'
            : (($current - $previous) >= 10 ? 'improving' : (($current - $previous) <= -10 ? 'worsening' : 'stable'));

        // Zones par classe (dernière session close de chaque élève), classes ≥ 5 élèves seulement.
        $lastZones = $schoolId ? ChatSession::query()
            ->select('child_id', DB::raw('MAX(ended_at) as last_ended'))
            ->where('school_id', $schoolId)->whereNotNull('ended_at')->whereNotNull('zone')
            ->groupBy('child_id')->get()
            ->mapWithKeys(function ($row) {
                $zone = ChatSession::where('child_id', $row->child_id)->where('ended_at', $row->last_ended)->value('zone');
                return [$row->child_id => $zone];
            }) : collect();

        $zonesByClass = $children->groupBy('classe')->map(function ($group) use ($lastZones) {
            $count = $group->count();
            if ($count < self::MIN_CLASS_SIZE) {
                return ['count' => $count, 'insufficient' => true, 'zones' => []];
            }
            $zones = ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0, 'none' => 0];
            foreach ($group as $c) {
                $z = $lastZones[$c->id] ?? 'none';
                $zones[array_key_exists($z, $zones) ? $z : 'none']++;
            }
            return ['count' => $count, 'insufficient' => false, 'zones' => $zones];
        })->sortKeys();

        $alertsBase = Alert::where('school_id', $schoolId);
        $byLevel  = (clone $alertsBase)->select('level', DB::raw('count(*) as n'))->groupBy('level')->pluck('n', 'level');
        $byStatus = (clone $alertsBase)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $byStage  = $schoolId ? AlertLifecycle::query()
            ->whereIn('alert_id', (clone $alertsBase)->select('id'))
            ->whereIn('id', AlertLifecycle::select(DB::raw('MAX(id)'))->whereIn('alert_id', (clone $alertsBase)->select('id'))->groupBy('alert_id'))
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status') : collect();
        $byStage = collect($byStage);
        $byStage['nouveau'] = ($byStage['nouveau'] ?? 0) + max(0, (int) $byStatus->sum() - (int) $byStage->sum());

        // Temps moyen de qualification (minutes) : première entrée « qualifie » − création de l'alerte.
        $qualDurations = $schoolId ? AlertLifecycle::query()
            ->join('alerts', 'alerts.id', '=', 'alert_lifecycle.alert_id')
            ->where('alerts.school_id', $schoolId)->where('alert_lifecycle.status', 'qualifie')
            ->select('alert_lifecycle.alert_id', DB::raw('MIN(alert_lifecycle.changed_at) as first_q'), DB::raw('MIN(alerts.created_at) as created'))
            ->groupBy('alert_lifecycle.alert_id')->get()
            ->map(fn ($r) => max(0, \Illuminate\Support\Carbon::parse($r->created)->diffInMinutes(\Illuminate\Support\Carbon::parse($r->first_q)))) : collect();
        $avgQualificationMinutes = $qualDurations->isNotEmpty() ? (int) round($qualDurations->avg()) : null;

        // Urgences vitales sans accusé de réception du référent depuis > 60 min ouvrées.
        $hoursStart = (string) ($school?->setting?->school_hours_start ?? '08:00');
        $hoursEnd   = (string) ($school?->setting?->school_hours_end ?? '17:00');
        // Spec §5.4 / §0 : seuls les signaux VITAUX peuvent porter un nom ici ; une alerte
        // non vitale dont l'escalade est épuisée n'ouvre aucun drill-down individuel.
        $vital = $schoolId ? Alert::with('child:id,name')
            ->where('school_id', $schoolId)->where('status', '!=', 'resolved')
            ->whereIn('type', AlertType::vitalValues())
            ->whereDoesntHave('notifications', fn ($q) => $q->whereNotNull('acked_at'))
            ->orderBy('created_at')->get()
            ->filter(fn ($a) => $a->escalation_exhausted_at !== null
                || BusinessTime::minutesBetween($a->created_at, now(), $hoursStart, $hoursEnd) > self::VITAL_ACK_MINUTES)
            ->values() : collect();
        foreach ($vital as $a) {
            Audit::log('admin.vital.view', $a);
        }

        return view('livewire.admin.dashboard', [
            'school'        => $school,
            'childrenCount' => $children->count(),
            'activeCount'   => $children->whereNull('deactivated_at')->count(),
            'sessions7d'    => $children->whereNotNull('last_session_at')->where('last_session_at', '>=', now()->subDays(7))->count(),
            'climateScore'  => $climateScore,
            'trend'         => $trend,
            'zonesByClass'  => $zonesByClass,
            'byLevel'       => $byLevel,
            'byStatus'      => $byStatus,
            'byStage'       => $byStage,
            'avgQualificationMinutes' => $avgQualificationMinutes,
            'vital'         => $vital,
        ]);
    }
}
