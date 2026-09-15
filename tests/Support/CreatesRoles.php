<?php

namespace Tests\Support;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\ReferentDelegation;
use App\Models\School;
use App\Models\User;

/** Fabriques de rôles pour les tests du lot 1. */
trait CreatesRoles
{
    protected function makeReferent(School $school, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => 'referent'] + $attrs);
        $school->users()->attach($u->id, ['role' => 'referent']);
        return $u;
    }

    protected function makeAdmin(School $school, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => 'admin'] + $attrs);
        $school->users()->attach($u->id, ['role' => 'director']);
        return $u;
    }

    protected function makeParent(Child $child, bool $consent = true, string $relation = 'mere'): User
    {
        $u = User::factory()->create(['role' => 'parent']);
        $u->children()->attach($child->id, [
            'relation'          => $relation,
            'consent_given'     => $consent,
            'consent_text'      => $consent ? 'Consentement de test' : null,
            'consent_timestamp' => $consent ? now() : null,
            'consent_ip'        => $consent ? '127.0.0.1' : null,
        ]);
        return $u;
    }

    protected function makeAlert(Child $child, array $attrs = []): Alert
    {
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $child->school_id,
            'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(50), 'zone' => 'orange',
        ]);
        return Alert::create($attrs + [
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $child->school_id,
            'type' => 'isolement', 'level' => 'moderate',
        ]);
    }

    protected function makeActiveDelegation(School $school, User $referent, User $delegate): ReferentDelegation
    {
        return ReferentDelegation::create([
            'school_id' => $school->id, 'referent_id' => $referent->id, 'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'activated_at' => now(),
        ]);
    }
}
