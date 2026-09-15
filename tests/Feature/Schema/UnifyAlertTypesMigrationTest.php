<?php

namespace Tests\Feature\Schema;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D7 — après la migration d'unification, l'enum alerts.type accepte les 7 valeurs
 * et refuse `tristesse`. La conversion des données est vérifiée sur la base
 * locale par la migration elle-même (UPDATE tristesse -> detresse).
 */
class UnifyAlertTypesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): array
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(),
        ]);
        return [$school, $child, $session];
    }

    public function test_all_seven_types_are_insertable(): void
    {
        [$school, $child, $session] = $this->makeSession();
        foreach (['harcelement', 'detresse', 'pensees_negatives', 'danger', 'isolement', 'stress', 'humiliation_adulte'] as $type) {
            $alert = Alert::create([
                'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
                'type' => $type, 'level' => 'moderate',
            ]);
            $this->assertSame($type, $alert->fresh()->type);
        }
    }

    public function test_tristesse_is_rejected_by_the_schema(): void
    {
        [$school, $child, $session] = $this->makeSession();
        $this->expectException(\Illuminate\Database\QueryException::class);
        Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'tristesse', 'level' => 'moderate',
        ]);
    }
}
