<?php

namespace App\Livewire\Concerns;

use App\Models\School;
use Illuminate\Support\Facades\Auth;

/**
 * Lot 1 — accès à l'espace référent, vérifié CÔTÉ SERVEUR à chaque requête :
 *  - référent titulaire de l'école (school_user.role = referent) → accès complet ;
 *  - délégué temporaire actif (referent_delegations) → accès limité : alertes
 *    actives + qualifier / agir / accuser réception, jamais les fiches complètes
 *    ni les notes internes ni l'information du parent ni la clôture.
 *
 * `$delegateMode` est volontairement NON public : il n'est jamais sérialisé
 * vers le client et se recalcule à chaque requête (mount + hydrate).
 */
trait ResolvesReferentAccess
{
    protected bool $delegateMode = false;

    /** École de travail du référent connecté (titulaire d'abord, délégation ensuite), sinon 403. */
    protected function resolveReferentSchool(?School $expected = null): School
    {
        $user = Auth::guard('web')->user();
        abort_unless($user !== null, 403);

        if ($user->isReferent()) {
            $school = $user->referentSchool();
            if ($school && ($expected === null || $expected->id === $school->id)) {
                $this->delegateMode = false;
                return $school;
            }
        }

        $candidates = $expected ? collect([$expected]) : $user->delegatedSchools();
        foreach ($candidates as $school) {
            if ($user->delegationFor($school)) {
                $this->delegateMode = true;
                return $school;
            }
        }

        abort(403);
    }

    /** Refuse (403) les actions réservées au référent titulaire. */
    protected function denyDelegate(): void
    {
        abort_if($this->delegateMode, 403);
    }

    public function isDelegateMode(): bool
    {
        return $this->delegateMode;
    }
}
