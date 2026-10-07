<?php

namespace App\Livewire\Concerns;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Phase pilote (hp-v2nf) — alertes visibles par un parent : celles qui lui ont été
 * envoyées (ligne `alert_notifications` à son nom), pour un enfant dont son consentement
 * est ACTIF au moment de la lecture. Tout le reste est refusé côté serveur (403), et
 * l'interrupteur config('carenest.parent_alerts') éteint l'écran (404).
 */
trait ResolvesParentAlerts
{
    protected function parentUser(): User
    {
        abort_unless(config('carenest.parent_alerts'), 404);

        $user = Auth::guard('web')->user();
        abort_unless($user && $user->isParent() && ! $user->isDeactivated(), 403);

        return $user;
    }

    protected function parentAlertsQuery(User $parent): Builder
    {
        return Alert::query()
            ->whereIn('child_id', $parent->consentedChildren()->pluck('children.id'))
            ->whereHas('notifications', fn ($q) => $q->where('recipient_id', $parent->id)->where('channel', 'app'));
    }

    protected function ownAlert(int $alertId): Alert
    {
        $alert = $this->parentAlertsQuery($this->parentUser())->with('child')->find($alertId);
        abort_unless($alert, 403);

        return $alert;
    }
}
