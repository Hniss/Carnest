<?php

namespace App\Livewire\ParentSpace;

use App\Enums\AlertType;
use App\Livewire\Concerns\ResolvesParentChildren;
use App\Models\ChatSession;
use App\Models\ParentSynthesis;
use App\Services\Audit;
use App\Services\ParentJournalBuilder;
use App\Support\Humanize;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mes données (lot 1 §5.5) : export texte lisible — résumés, zones, alertes
 * avec libellés, journal, synthèses. Jamais les notes internes ni la qualification.
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

            $lines[] = 'Sessions d\'écoute (résumés et zones)';
            $sessions = ChatSession::where('child_id', $child->id)->whereNotNull('ended_at')->orderBy('ended_at')->get();
            if ($sessions->isEmpty()) {
                $lines[] = '  Aucune session.';
            }
            foreach ($sessions as $s) {
                $lines[] = '  ' . Humanize::dateTime($s->ended_at) . ' · zone ' . self::zoneLabel($s->zone) . ($s->low_confidence ? ' (faible confiance)' : '');
                if ($s->ai_summary) {
                    $lines[] = '    Résumé : ' . $s->ai_summary;
                }
            }

            $lines[] = '';
            $lines[] = 'Signaux détectés';
            $alerts = $child->alerts()->orderBy('created_at')->get();
            if ($alerts->isEmpty()) {
                $lines[] = '  Aucun signal.';
            }
            foreach ($alerts as $a) {
                $lines[] = '  ' . Humanize::dateTime($a->created_at) . ' · ' . AlertType::labelFor($a->type) . ' · niveau ' . mb_strtolower(\App\Models\Alert::levelLabel($a->level)) . ' · ' . ($a->status === 'resolved' ? 'clôturé' : 'en cours');
                if ($a->summary) {
                    $lines[] = '    Résumé : ' . $a->summary;
                }
            }

            $lines[] = '';
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

    private static function zoneLabel(?string $zone): string
    {
        return match ($zone) {
            'green'  => 'verte',
            'yellow' => 'jaune',
            'orange' => 'orange',
            'red'    => 'rouge',
            default  => 'non déterminée',
        };
    }

    public function render()
    {
        return view('livewire.parent-space.my-data', ['children' => $this->parentChildren()]);
    }
}
