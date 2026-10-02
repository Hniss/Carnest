<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'inscription publique est fermée (correction du 2026-10-02).
 *
 * Faille : la page /register était ouverte aux visiteurs et la colonne users.role valait
 * « admin » par défaut — toute personne qui s'inscrivait devenait administrateur. Aucun
 * parcours légitime n'en dépendait : les comptes du référent et de l'administration sont
 * créés depuis l'écran Comptes de l'administration, les comptes parents par
 * ParentAccountProvisioner, les élèves par l'administration.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_cannot_open_a_registration_page(): void
    {
        $this->get('/register')->assertNotFound();

        $this->assertSame(0, User::count());
    }

    public function test_the_home_page_offers_no_way_to_register(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('/register')
            ->assertDontSee('Register');
    }

    public function test_the_adult_login_page_offers_no_way_to_register(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('/register')
            ->assertDontSee('Register');
    }
}
