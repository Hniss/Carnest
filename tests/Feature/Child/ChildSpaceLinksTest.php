<?php

namespace Tests\Feature\Child;

use App\Models\Child;
use App\Models\School;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Aucun lien de l'espace élève ne sort de l'espace élève (correction du 2026-10-02).
 *
 * Avant, le logo de la page de connexion élève menait à la page d'accueil, qui affiche
 * « Log in » vers la connexion des adultes : en deux clics, l'enfant lisait « Repérer la
 * détresse émotionnelle… ». Contrainte produit (Hamza) : l'enfant ne doit pas comprendre
 * que Care partage la situation et l'échange avec les parents et les référents.
 */
class ChildSpaceLinksTest extends TestCase
{
    use RefreshDatabase;

    /** Les seules adresses vers lesquelles un lien ou un formulaire de l'espace élève peut mener. */
    private const CHILD_SPACE = ['/child/login', '/child/logout', '/chat', '/chat/close'];

    /** @return array<int,array{0:string,1:string}> [balise, adresse] de chaque lien et de chaque formulaire */
    private function linksOf(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $links[] = ['a', $a->getAttribute('href')];
        }
        foreach ($xpath->query('//form[@action]') as $form) {
            $links[] = ['form', $form->getAttribute('action')];
        }

        return $links;
    }

    private function assertStaysInChildSpace(string $html, string $page): void
    {
        $links = $this->linksOf($html);
        $this->assertNotEmpty($links, "La {$page} devrait porter au moins un lien à contrôler.");

        $ownHost = parse_url(url('/'), PHP_URL_HOST);
        foreach ($links as [$tag, $href]) {
            $host = parse_url($href, PHP_URL_HOST);
            $path = parse_url($href, PHP_URL_PATH) ?: '/';

            $this->assertTrue(
                ($host === null || $host === $ownHost) && in_array($path, self::CHILD_SPACE, true),
                "Le lien « {$href} » (balise {$tag}) de la {$page} sort de l'espace élève."
            );
        }
    }

    public function test_the_logo_of_the_child_login_leads_back_to_the_child_login(): void
    {
        $html = $this->get('/child/login')->assertOk()->getContent();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $logoLinks = (new DOMXPath($dom))->query('//a[.//img[@alt="CareNest"]]');

        $this->assertGreaterThan(0, $logoLinks->length, 'Le logo cliquable de la page de connexion élève est introuvable.');
        foreach ($logoLinks as $link) {
            $this->assertSame(route('child.login'), $link->getAttribute('href'), 'Le logo doit ramener à la connexion élève.');
        }
    }

    public function test_no_link_of_the_child_login_page_leaves_the_child_space(): void
    {
        $this->assertStaysInChildSpace($this->get('/child/login')->assertOk()->getContent(), 'page de connexion élève');
    }

    public function test_no_link_of_the_chat_page_leaves_the_child_space(): void
    {
        $school = School::factory()->create();
        $this->actingAs(Child::factory()->for($school)->create(['age' => 10]), 'child');

        $this->assertStaysInChildSpace($this->get('/chat')->assertOk()->getContent(), 'page du chat');
    }

    /** Les pages d'erreur qu'un élève peut rencontrer ne portent aucun lien (ni accueil, ni connexion adulte). */
    public function test_the_error_pages_a_child_can_meet_carry_no_link(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_essai-erreur/{code}', fn (int $code) => abort($code));

        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = $this->get("/_essai-erreur/{$code}")->assertStatus($code)->getContent();
            $this->assertSame([], $this->linksOf($html), "La page d'erreur {$code} porte un lien.");
        }

        // Méthode refusée sur une adresse de l'espace élève (aucune page dédiée : rendu générique).
        $html = $this->get('/child/logout')->assertStatus(405)->getContent();
        $this->assertSame([], $this->linksOf($html), "La page d'erreur 405 porte un lien.");
    }
}
