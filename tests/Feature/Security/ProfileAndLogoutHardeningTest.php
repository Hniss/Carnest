<?php

namespace Tests\Feature\Security;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Audit sécurité du 2026-10-05 — /profile ne permet plus la suppression physique de son compte
 * (spec : aucune suppression tant qu'un journal y fait référence) ; la déconnexion élève
 * détruit la session ; F11 l'interdiction de lister les dossiers ne dépend plus d'un module.
 */
class ProfileAndLogoutHardeningTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public static function roles(): array
    {
        return [['admin'], ['referent'], ['parent']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_profile_page_offers_no_account_deletion(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertDontSee('delete-user-form')
            ->assertDontSee('deleteUser');

        $this->assertFileDoesNotExist(resource_path('views/livewire/profile/delete-user-form.blade.php'));
        $this->assertNotNull($user->fresh());
    }

    public function test_child_logout_invalidates_the_session(): void
    {
        $child = Child::factory()->for(School::factory()->create())->create();
        $this->actingAs($child, 'child');
        $this->withSession(['marqueur' => 'valeur']);
        $oldToken = session()->token();

        $this->post(route('child.logout'))->assertRedirect(route('child.login'));

        $this->assertGuest('child');
        $this->assertNull(session('marqueur'));
        $this->assertNotSame($oldToken, session()->token());
    }

    public function test_directory_listing_is_disabled_outside_mod_negotiation_block(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $outside = preg_replace('/<IfModule\s+mod_negotiation\.c>.*?<\/IfModule>/s', '', $htaccess);
        $this->assertMatchesRegularExpression('/^\s*Options\s+-Indexes\s*$/m', $outside);
    }
}
