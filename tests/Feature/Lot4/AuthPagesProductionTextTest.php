<?php

namespace Tests\Feature\Lot4;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matrice QA du déploiement pilote (2026-10-05) : aucune mention de comptes de démonstration
 * en production, aucun emoji, et tout le parcours d'authentification en français.
 */
class AuthPagesProductionTextTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH = [
        'Forgot your password', 'Email Password Reset Link', 'Reset Password', 'Confirm Password',
        'This is a secure area', 'Thanks for signing up', 'Resend Verification Email', 'Log Out',
        'Update Password', 'Current Password', 'New Password', 'Profile Information', 'Saved.',
        "Update your account's profile", 'Ensure your account', '>Email<', '>Password<', '>Name<',
        '>Save<', '>Confirm<', '>Profile<', '>Dashboard<',
    ];

    private function assertFrenchOnly(string $html): void
    {
        foreach (self::ENGLISH as $english) {
            $this->assertStringNotContainsString($english, $html, "Texte anglais restant : {$english}");
        }
    }

    private function assertNoEmoji(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html);
    }

    public function test_adult_login_shows_no_demo_accounts(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('démo', $html);
        $this->assertStringNotContainsString('@carenest.ma', $html);
    }

    public function test_child_login_shows_no_demo_account_and_no_emoji(): void
    {
        $html = $this->get('/child/login')->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('démo', $html);
        $this->assertStringNotContainsString('yassine@', $html);
        $this->assertNoEmoji($html);
    }

    public function test_forgot_password_page_is_in_french(): void
    {
        $html = $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertFrenchOnly($html);
        $this->assertStringContainsString('Mot de passe oublié', $html);
        $this->assertStringContainsString('Envoyer le lien de réinitialisation', $html);
    }

    public function test_reset_password_page_is_in_french(): void
    {
        $html = $this->get('/reset-password/jeton-de-test?email=test%40exemple.test')->assertOk()->getContent();

        $this->assertFrenchOnly($html);
        $this->assertStringContainsString('Réinitialiser le mot de passe', $html);
    }

    public function test_confirm_password_and_verify_email_pages_are_in_french(): void
    {
        $verified = User::factory()->create(['role' => 'admin']);
        $this->assertFrenchOnly($this->actingAs($verified)->get('/confirm-password')->assertOk()->getContent());

        $unverified = User::factory()->unverified()->create(['role' => 'admin']);
        $this->assertFrenchOnly($this->actingAs($unverified)->get('/verify-email')->assertOk()->getContent());
    }

    public function test_profile_page_is_in_french(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['role' => 'admin']);
        $school->users()->attach($user->id, ['role' => 'director']);

        $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

        $this->assertFrenchOnly($html);
        $this->assertStringContainsString('Modifier le mot de passe', $html);
    }

    public function test_password_reset_and_verification_emails_are_in_french(): void
    {
        $user = User::factory()->create();

        $reset = (new ResetPassword('jeton'))->toMail($user);
        $this->assertSame('Réinitialisation du mot de passe', $reset->subject);
        $this->assertSame('Réinitialiser le mot de passe', $reset->actionText);

        $verify = (new VerifyEmail)->toMail($user);
        $this->assertSame('Vérification de votre adresse e-mail', $verify->subject);
    }

    public function test_guest_pages_are_titled_carenest(): void
    {
        foreach (['/login', '/child/login', '/forgot-password'] as $path) {
            $this->assertMatchesRegularExpression('/<title>CareNest/', $this->get($path)->getContent(), $path);
        }
    }
}
