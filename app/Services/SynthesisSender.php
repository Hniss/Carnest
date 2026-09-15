<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\ParentSynthesis;
use App\Models\User;
use App\Support\Humanize;
use Illuminate\Support\Collection;

/**
 * Lot 1 §3.4 étape 5 / §5.1 — synthèse au parent, formulation imposée en quatre
 * blocs. Jamais « l'IA a détecté », jamais le type précis du signal.
 */
class SynthesisSender
{
    public function __construct(private readonly Notifier $notifier)
    {
    }

    /** Textes par défaut proposés au référent, personnalisés avec la date d'entretien et la période de suivi. */
    public function defaults(Child $child, ?Alert $alert = null): array
    {
        $entretien = AlertAction::query()
            ->when($alert, fn ($q) => $q->where('alert_id', $alert->id), fn ($q) => $q->whereIn('alert_id', $child->alerts()->select('id')))
            ->where('action_type', 'entretien')
            ->latest('performed_at')
            ->first();

        $followUp = FollowUp::query()
            ->where('child_id', $child->id)
            ->when($alert, fn ($q) => $q->where('alert_id', $alert->id))
            ->whereNotNull('next_review_date')
            ->latest()
            ->first();

        $date    = $entretien ? Humanize::date($entretien->performed_at) : '{date}';
        $periode = $followUp ? 'autour du ' . Humanize::date($followUp->next_review_date) : '{période}';

        return [
            'identified'      => ParentSynthesis::DEFAULT_IDENTIFIED,
            'school_did'      => str_replace('{date}', $date, ParentSynthesis::DEFAULT_SCHOOL_DID),
            'school_proposes' => str_replace('{période}', $periode, ParentSynthesis::DEFAULT_SCHOOL_PROPOSES),
            'parent_can'      => ParentSynthesis::DEFAULT_PARENT_CAN,
        ];
    }

    /**
     * Envoie la synthèse à chaque parent au consentement actif, notifie chacun.
     * @return Collection<int, ParentSynthesis>
     */
    public function send(Child $child, ?Alert $alert, User $sender, array $texts): Collection
    {
        $parents = $child->consentingParents()->get();

        return $parents->map(function (User $parent) use ($child, $alert, $sender, $texts) {
            $synthesis = ParentSynthesis::create([
                'child_id'        => $child->id,
                'alert_id'        => $alert?->id,
                'parent_id'       => $parent->id,
                'sent_by'         => $sender->id,
                'identified'      => $texts['identified'],
                'school_did'      => $texts['school_did'],
                'school_proposes' => $texts['school_proposes'],
                'parent_can'      => $texts['parent_can'],
                'sent_at'         => now(),
            ]);

            $this->notifier->notify(
                $parent,
                'synthese',
                'Nouvelle information de l\'école',
                'L\'école vous a transmis un point concernant ' . $child->name . '.',
                '/parent'
            );

            Audit::log('referent.synthesis.send', $synthesis, ['school_id' => $child->school_id]);

            return $synthesis;
        });
    }
}
