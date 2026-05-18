<?php

namespace App\Livewire\Admin;

use App\Models\AdminNote;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Services\ChildStatusResolver;
use App\Services\WellbeingTrendResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Page profil enfant — suivi psychique longitudinal.
 *
 * Sécurité (defense in depth) :
 *  1. Route protégée par middleware ['auth','verified'].
 *  2. Mount() vérifie que l'enfant appartient à une école de l'admin connecté.
 *  3. Toute action publique relit le scope école avant écriture.
 *  4. Tout accès / mutation est tracé dans le channel `admin_audit` (loi 09-08).
 *
 * Conformité (CONTEXT.md §4) :
 *  - Aucun contenu de message enfant n'est rendu dans la vue (uniquement
 *    `zone`, `ai_summary`, timestamps — `ai_summary` étant un résumé court IA).
 *  - Aucun message brut n'est loggé.
 */
#[Layout('layouts.app')]
class ChildProfile extends Component
{
    public Child $child;

    public function mount(Child $child): void
    {
        $userSchoolIds = auth()->user()->schools()->pluck('schools.id');

        abort_unless($userSchoolIds->contains($child->school_id), 403);

        Log::channel('admin_audit')->info('view_profile', [
            'admin_user_id' => auth()->id(),
            'child_id'      => $child->id,
            'school_id'     => $child->school_id,
            'ip'            => request()->ip(),
        ]);

        $this->child = $child;
    }

    public function resolveAlert(int $alertId): void
    {
        $alert = Alert::find($alertId);
        if (! $alert) {
            return;
        }

        $this->assertSameSchool($alert->child_id);

        $alert->status = 'resolved';
        $alert->save();

        // Recalcul du statut enfant : une alerte résolue peut faire retomber
        // l'enfant d'un palier (cf. ChildStatusResolver).
        $this->child->refresh();
        $resolver = app(ChildStatusResolver::class);
        $this->child->status = $resolver->resolve($this->child->score_enfant, $this->child);
        $this->child->save();

        Log::channel('admin_audit')->info('resolve_alert', [
            'admin_user_id' => auth()->id(),
            'child_id'      => $this->child->id,
            'school_id'     => $this->child->school_id,
            'alert_id'      => $alert->id,
            'ip'            => request()->ip(),
        ]);
    }

    public function addNote(string $content): void
    {
        $content = trim($content);

        if (mb_strlen($content) < 5 || mb_strlen($content) > 500) {
            throw ValidationException::withMessages([
                'note' => 'La note doit faire entre 5 et 500 caractères.',
            ]);
        }

        $note = AdminNote::create([
            'child_id' => $this->child->id,
            'user_id'  => auth()->id(),
            'content'  => $content,
        ]);

        Log::channel('admin_audit')->info('add_note', [
            'admin_user_id' => auth()->id(),
            'child_id'      => $this->child->id,
            'school_id'     => $this->child->school_id,
            'note_id'       => $note->id,
            'ip'            => request()->ip(),
        ]);
    }

    public function render()
    {
        $report = app(WellbeingTrendResolver::class)->resolve($this->child);

        $recentSessions = ChatSession::query()
            ->where('child_id', $this->child->id)
            ->whereNotNull('ended_at')
            ->latest('ended_at')
            ->limit(20)
            ->get(['id', 'zone', 'ai_summary', 'low_confidence', 'started_at', 'ended_at']);

        $recentAlerts = Alert::query()
            ->where('child_id', $this->child->id)
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'type', 'level', 'status', 'created_at', 'session_id']);

        $adminNotes = AdminNote::query()
            ->where('child_id', $this->child->id)
            ->latest()
            ->get();

        return view('livewire.admin.child-profile', compact(
            'report', 'recentSessions', 'recentAlerts', 'adminNotes'
        ));
    }

    /**
     * Vérifie que le child_id ciblé par une action appartient bien à l'école
     * de l'admin connecté. Évite qu'un admin trafique l'ID d'une alerte pour
     * agir sur un enfant d'une autre école.
     */
    private function assertSameSchool(int $childId): void
    {
        $target = Child::find($childId);
        $userSchoolIds = auth()->user()->schools()->pluck('schools.id');
        abort_unless(
            $target && $userSchoolIds->contains($target->school_id),
            403
        );
    }
}
