<?php

namespace Tests\Feature\Child;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un élève déconnecté reste dans l'espace élève (correction du 2026-10-02).
 *
 * Avant, ouvrir /chat sans être connecté menait à la page de connexion des ADULTES, qui
 * annonce « Repérer la détresse émotionnelle… » et « Accédez au suivi émotionnel de votre
 * établissement » : de quoi faire comprendre à l'enfant que la conversation est suivie.
 * Contrainte produit (Hamza, 2026-10-02) : l'enfant ne doit pas comprendre que Care
 * partage la situation et l'échange avec les parents et les référents.
 */
class ChildGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_logged_out_child_opening_the_chat_lands_on_the_child_login(): void
    {
        $this->get('/chat')->assertRedirect(route('child.login'));
    }

    public function test_an_adult_page_still_sends_visitors_to_the_adult_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/dashboard-referent')->assertRedirect(route('login'));
        $this->get('/parent')->assertRedirect(route('login'));
    }

    public function test_the_child_login_page_leads_nowhere_near_the_adult_login(): void
    {
        $this->get('/child/login')
            ->assertOk()
            ->assertDontSee(route('login'))
            ->assertDontSee('Espace administrateur');
    }
}
