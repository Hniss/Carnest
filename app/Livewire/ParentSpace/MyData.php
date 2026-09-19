<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentChildren;
use App\Models\ParentSynthesis;
use App\Services\Audit;
use App\Services\ParentJournalBuilder;
use App\Support\Humanize;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mes données (lot 1 §5.5) : export texte lisible — identité de l'enfant, état du
 * consentement, journal en événements macro, synthèses reçues.
 *
 * Spec §3.2 — jamais visible côté parent : le type précis de signal détaillé, les
 * notes internes, la qualification technique. Les résumés de séance et les zones
 * émotionnelles ne figurent donc pas dans l'export (Business Plan §4 : « sans
 * exposer le contenu exact des échanges »).
 */
#[Layout('layouts.parent')]
class MyData extends Component
{
    use ResolvesParentChildren;

    public function export(ParentJournalBuilder $journal)
    {
        $parent = Auth::user();
        $children = $this->parentChildren()->load('school');

        $lines = [];
        $lines[] = 'CARENEST — EXPORT DE VOS DONNÉES';
        $lines[] = 'Parent : ' . $parent->name . ' (' . $parent->email . ')';
        $lines[] = 'Généré le ' . Humanize::dateTime(now());
        $lines[] = str_repeat('=', 60);

        foreach ($children as $child) {
            $pivot = $child->pivot;
            $lines[] = '';
            $lines[] = 'ENFANT : ' . $child->name;
            $lines[] = 'École : ' . $child->school->name . ' · Classe : ' . $child->classe . ' · Âge : ' . $child->age . ' ans';
            $lines[] = 'Compte : ' . ($child->deactivated_at ? 'désactivé' : 'actif');
            $lines[] = 'Consentement : ' . ($pivot->consent_given ? 'donné le ' . Humanize::dateTime($pivot->consent_timestamp) : 'non recueilli')
                . ($pivot->consent_withdrawn_at ? ' · retiré le ' . Humanize::dateTime($pivot->consent_withdrawn_at) : '');
            $lines[] = str_repeat('-', 60);

            $lines[] = 'Journal';
            $events = $journal->build($child);
            if ($events->isEmpty()) {
                $lines[] = '  Aucun événement.';
            }
            foreach ($events as $e) {
                $lines[] = '  ' . Humanize::dateTime($e['at']) . ' · ' . $e['label'];
            }

            $lines[] = '';
            $lines[] = 'Informations reçues de l\'école';
            $syntheses = ParentSynthesis::where('child_id', $child->id)->where('parent_id', $parent->id)->orderBy('sent_at')->get();
            if ($syntheses->isEmpty()) {
                $lines[] = '  Aucune.';
            }
            foreach ($syntheses as $s) {
                $lines[] = '  ' . Humanize::dateTime($s->sent_at);
                $lines[] = '    Ce que CareNest a identifié : ' . $s->identified;
                $lines[] = '    Ce que l\'école a fait : ' . $s->school_did;
                $lines[] = '    Ce que l\'école propose : ' . $s->school_proposes;
                $lines[] = '    Ce que vous pouvez faire : ' . $s->parent_can;
            }
        }

        $lines[] = '';
        $lines[] = 'Les notes internes de l\'établissement ne font pas partie de cet export.';

        Audit::log('parent.data.export', null, ['school_id' => $children->first()?->school_id]);

        $content = implode("\n", $lines);

        return response()->streamDownload(function () use ($content) {
            echo "\xEF\xBB\xBF" . $content;
        }, 'carenest-mes-donnees-' . now()->format('Ymd-His') . '.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function render()
    {
        return view('livewire.parent-space.my-data', ['children' => $this->parentChildren()]);
    }
}
