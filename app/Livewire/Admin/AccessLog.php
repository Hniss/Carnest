<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal d'accès (lot 1 §4) : vue filtrée de audit_logs pour l'école —
 * acteur, rôle, action, cible, date, IP. Jamais de contenu. Export CSV audité.
 */
#[Layout('layouts.app')]
class AccessLog extends Component
{
    use WithPagination;

    #[Url] public ?int $actorId = null;
    #[Url] public ?string $from = null;
    #[Url] public ?string $to = null;
    #[Url] public string $action = '';

    protected School $school;

    public function mount(): void
    {
        $this->school = $this->resolveSchool();
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveSchool();
    }

    private function resolveSchool(): School
    {
        $school = Auth::user()->schools()->first();
        abort_unless($school, 403);
        return $school;
    }

    public function updating($name): void
    {
        if (in_array($name, ['actorId', 'from', 'to', 'action'], true)) {
            $this->resetPage();
        }
    }

    private function query(): Builder
    {
        return AuditLog::query()
            ->with('actor:id,name,email')
            ->where('school_id', $this->school->id)
            ->when($this->actorId, fn ($q) => $q->where('actor_id', $this->actorId))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->action !== '', fn ($q) => $q->where('action', 'like', $this->action . '%'))
            ->latest('created_at')->latest('id');
    }

    public function exportCsv()
    {
        $rows = $this->query()->limit(5000)->get();
        Audit::log('admin.access_log.export', null, ['school_id' => $this->school->id]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Acteur', 'Rôle', 'Action', 'Cible', 'Identifiant cible', 'IP'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->created_at->format('d/m/Y H:i:s'),
                    $r->actor?->name ?? ($r->actor_id ? 'Compte ' . $r->actor_id : 'Système'),
                    User::roleLabel($r->actor_role) === 'Inconnu' ? $r->actor_role : User::roleLabel($r->actor_role),
                    $r->action,
                    $r->target_type ? class_basename($r->target_type) : '',
                    $r->target_id ?? '',
                    $r->ip ?? '',
                ], ';');
            }
            fclose($out);
        }, 'journal-acces-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $actors = User::whereIn('id', AuditLog::where('school_id', $this->school->id)->whereNotNull('actor_id')->distinct()->pluck('actor_id'))
            ->orderBy('name')->get(['id', 'name', 'role']);

        return view('livewire.admin.access-log', [
            'school' => $this->school,
            'logs'   => $this->query()->paginate(50),
            'actors' => $actors,
        ]);
    }
}
