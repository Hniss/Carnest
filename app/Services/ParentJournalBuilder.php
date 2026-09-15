<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\AlertLifecycle;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\ParentSynthesis;
use Illuminate\Support\Collection;

/**
 * Lot 1 §5.2 — journal parent : événements MACRO formulés, dérivés de
 * alert_lifecycle / alert_actions / follow_ups / parent_syntheses.
 * Jamais le type du signal, la qualification ni les notes internes.
 *
 * @return Collection<int, array{at: \Illuminate\Support\Carbon, label: string, icon: string}>
 */
class ParentJournalBuilder
{
    public function build(Child $child): Collection
    {
        $alertIds = $child->alerts()->pluck('id');

        $events = collect();

        foreach (Alert::whereIn('id', $alertIds)->get(['id', 'created_at']) as $a) {
            $events->push(['at' => $a->created_at, 'label' => 'Un signal a été identifié', 'icon' => 'activity']);
        }

        foreach (AlertLifecycle::whereIn('alert_id', $alertIds)->get(['status', 'changed_at']) as $l) {
            $label = match ($l->status) {
                'qualifie'      => 'Le référent a évalué la situation',
                'en_traitement' => 'L\'école a engagé une action',
                'suivi'         => 'Un suivi a été planifié',
                'cloture'       => 'La situation a été clôturée par l\'école',
                default         => null,
            };
            if ($label) {
                $events->push(['at' => $l->changed_at, 'label' => $label, 'icon' => 'check-circle']);
            }
        }

        foreach (AlertAction::whereIn('alert_id', $alertIds)->get(['action_type', 'performed_at']) as $a) {
            $label = match ($a->action_type) {
                'contact_parent'            => 'L\'école vous a contacté',
                'entretien'                 => 'Un échange a eu lieu avec votre enfant',
                'orientation_professionnel' => 'L\'école a proposé un accompagnement',
                default                     => 'L\'école a mis en place une attention particulière',
            };
            $events->push(['at' => $a->performed_at, 'label' => $label, 'icon' => 'users']);
        }

        foreach (FollowUp::where('child_id', $child->id)->get(['created_at', 'next_review_date']) as $f) {
            $events->push([
                'at'    => $f->created_at,
                'label' => 'Un suivi a été planifié' . ($f->next_review_date ? ' (prochain point le ' . $f->next_review_date->format('d/m/Y') . ')' : ''),
                'icon'  => 'calendar',
            ]);
        }

        foreach (ParentSynthesis::where('child_id', $child->id)->get(['sent_at']) as $s) {
            $events->push(['at' => $s->sent_at, 'label' => 'L\'école vous a transmis une information', 'icon' => 'mail']);
        }

        return $events->sortByDesc('at')->values();
    }
}
