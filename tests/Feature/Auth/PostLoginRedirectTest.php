<?php

namespace Tests\Feature\Auth;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Après connexion, chaque rôle arrive sur son propre espace — jamais sur un 403.
 *
 * Cause réelle reproduite dans un vrai navigateur (2026-10-02) : Laravel mémorise en session
 * l'adresse demandée avant la connexion (« url.intended »), et la page de connexion la suivait
 * sans regarder le rôle. Sur un poste partagé, l'adresse d'un référent déconnecté (ou d'un
 * parent) envoyait l'administrateur qui se connecte ensuite sur /dashboard-referent/... — 403.
 * Les écrans Breeze de confirmation du mot de passe et de vérification de l'adresse e-mail
 * renvoyaient en dur vers /dashboard, interdit au référent et au parent.
 */
class PostLoginRedirectTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    /** L'utilisateur déconnecté ouvre une adresse protégée : Laravel la mémorise. */
    private function visitWhileLoggedOut(string $path): void
    {
        $this->get($path)->assertRedirect('/login');
        $this->assertNotNull(session('url.intended'));
    }

    private function login(User $user): \Livewire\Features\SupportTesting\Testable
    {
        return Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors();
    }

    public function test_admin_whose_intended_url_is_a_referent_page_lands_on_his_dashboard_without_403(): void
    {
        $school = School::factory()->create();
        $this->makeReferent($school);
        $admin = $this->makeAdmin($school);

        $this->visitWhileLoggedOut('/dashboard-referent/eleves');
        $this->login($admin)->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk();
        $this->assertNull(session('url.intended'));
    }

    public function test_admin_is_not_sent_to_the_referent_home_nor_to_the_parent_or_child_spaces(): void
    {
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school);

        foreach (['/dashboard-referent', '/dashboard-referent/messages', '/parent/messages', '/chat'] as $path) {
            auth('web')->logout();
            $this->visitWhileLoggedOut($path);
            $this->login($admin)->assertRedirect('/dashboard');
        }
    }

    public function test_intended_url_of_the_users_own_space_is_still_honoured(): void
    {
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school);
        $referent = $this->makeReferent($school);

        $this->visitWhileLoggedOut('/dashboard/journal');
        $this->login($admin)->assertRedirect('/dashboard/journal');

        auth('web')->logout();
        $this->visitWhileLoggedOut('/dashboard-referent/messages?fil=3');
        $this->login($referent)->assertRedirect('/dashboard-referent/messages?fil=3');
    }

    public function test_referent_and_parent_never_land_on_another_roles_space(): void
    {
        $school = School::factory()->create();
        $referent = $this->makeReferent($school);
        $child = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child);

        $this->visitWhileLoggedOut('/dashboard');
        $this->login($referent)->assertRedirect('/dashboard-referent');

        auth('web')->logout();
        $this->visitWhileLoggedOut('/dashboard-referent/eleves');
        $this->login($parent)->assertRedirect('/parent');

        auth('web')->logout();
        $this->visitWhileLoggedOut('/dashboard/comptes');
        $this->login($parent)->assertRedirect('/parent');
    }

    public function test_delegate_admin_may_follow_an_intended_referent_alert_page_only_while_delegated(): void
    {
        $school = School::factory()->create();
        $referent = $this->makeReferent($school);
        $admin = $this->makeAdmin($school);
        $delegation = $this->makeActiveDelegation($school, $referent, $admin);

        $this->visitWhileLoggedOut('/dashboard-referent');
        $this->login($admin)->assertRedirect('/dashboard-referent');
        $this->get('/dashboard-referent')->assertOk();

        $delegation->update(['revoked_at' => now()]);
        auth('web')->logout();
        $this->visitWhileLoggedOut('/dashboard-referent');
        $this->login($admin)->assertRedirect('/dashboard');
    }

    public function test_password_confirmation_sends_each_role_home_instead_of_the_admin_dashboard(): void
    {
        $school = School::factory()->create();
        $referent = $this->makeReferent($school);

        $this->actingAs($referent);
        Volt::test('pages.auth.confirm-password')
            ->set('password', 'password')
            ->call('confirmPassword')
            ->assertHasNoErrors()
            ->assertRedirect('/dashboard-referent');
    }

    public function test_email_verification_sends_each_role_home_instead_of_the_admin_dashboard(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child);
        $parent->forceFill(['email_verified_at' => null])->save();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $parent->id, 'hash' => sha1($parent->email),
        ]);
        $this->actingAs($parent)->get($url)->assertRedirect('/parent?verified=1');

        $referent = $this->makeReferent($school);
        $this->actingAs($referent);
        Volt::test('pages.auth.verify-email')->call('sendVerification')->assertRedirect('/dashboard-referent');
        Volt::test('profile.update-profile-information-form')->call('sendVerification')->assertRedirect('/dashboard-referent');
    }
}
