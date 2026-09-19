<?php

namespace Tests\Feature\ParentSpace;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use App\Services\ParentAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Spec §5.1 — « Aucun compte parent actif sans ce consentement — bloquant, non optionnel. »
 * Le compte parent lui-même est désactivé tant qu'aucun enfant consenti ne lui est rattaché.
 */
class ParentConsentGateTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function provisioner(): ParentAccountProvisioner
    {
        return app(ParentAccountProvisioner::class);
    }

    public function test_parent_created_without_consent_is_deactivated_and_cannot_log_in(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();

        $parent = $this->provisioner()->findOrCreateParent('parent.sans.consentement@test.ma', 'Parent Demo');
        $this->provisioner()->link($parent, $child, 'mere', false, $school, '127.0.0.1');

        $this->assertNotNull($parent->fresh()->deactivated_at);
        $this->assertNotNull($child->fresh()->deactivated_at);

        // Mot de passe connu : seule la désactivation doit bloquer la connexion.
        $parent->forceFill(['password' => Hash::make('motdepasse-valide')])->save();

        Volt::test('pages.auth.login')
            ->set('form.email', $parent->email)
            ->set('form.password', 'motdepasse-valide')
            ->call('login')
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_consent_activates_the_parent_account(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();

        $parent = $this->provisioner()->findOrCreateParent('parent.consentant@test.ma', 'Parent Demo');
        $this->assertNotNull($parent->fresh()->deactivated_at);

        $this->provisioner()->link($parent, $child, 'mere', true, $school, '127.0.0.1');

        $this->assertNull($parent->fresh()->deactivated_at);
    }

    public function test_withdrawing_the_last_consent_deactivates_the_parent_account(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $parent = $this->provisioner()->findOrCreateParent('parent.retrait@test.ma', 'Parent Demo');
        $this->provisioner()->link($parent, $child, 'mere', true, $school, '127.0.0.1');
        $this->assertNull($parent->fresh()->deactivated_at);

        $parent->children()->updateExistingPivot($child->id, ['consent_withdrawn_at' => now()]);
        $this->provisioner()->syncActivation($parent->fresh());

        $this->assertNotNull($parent->fresh()->deactivated_at);
    }

    public function test_parent_stays_active_while_another_child_is_still_consented(): void
    {
        $school = School::factory()->create();
        $one    = Child::factory()->for($school)->create();
        $two    = Child::factory()->for($school)->create();
        $parent = $this->provisioner()->findOrCreateParent('parent.deux.enfants@test.ma', 'Parent Demo');
        $this->provisioner()->link($parent, $one, 'mere', true, $school, '127.0.0.1');
        $this->provisioner()->link($parent, $two, 'mere', true, $school, '127.0.0.1');

        $parent->children()->updateExistingPivot($one->id, ['consent_withdrawn_at' => now()]);
        $this->provisioner()->syncActivation($parent->fresh());

        $this->assertNull($parent->fresh()->deactivated_at);
    }

    public function test_existing_parent_account_is_not_deactivated_by_lookup_alone(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $this->assertNull($parent->deactivated_at);

        $found = $this->provisioner()->findOrCreateParent($parent->email);

        $this->assertTrue($found->is($parent));
        $this->assertNull($found->fresh()->deactivated_at);
        $this->assertSame(1, User::where('email', $parent->email)->count());
    }
}
