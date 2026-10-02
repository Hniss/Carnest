<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ce que l'enfant voit s'arrête à la PREMIÈRE ligne technique de la réponse du modèle
 * (correction du 2026-10-02).
 *
 * Constat : le modèle continue parfois d'écrire après sa ligne RESUME. Seules les lignes
 * techniques reconnues étaient retirées, donc la suite arrivait à l'enfant — une question
 * en double a été observée en conversation réelle avec gemini-2.5-flash. Risque grave : un
 * résumé écrit sur plusieurs lignes ferait lire à l'enfant le résumé destiné aux adultes.
 * Le serveur, lui, exploite toujours ces lignes (zone, type, résumé).
 */
class ChildVisibleReplyTest extends TestCase
{
    private function reply(string $raw): array
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => $raw], 'finish_reason' => 'stop']],
            'model'   => 'gemini-2.5-flash',
            'usage'   => ['prompt_tokens' => 100, 'completion_tokens' => 40, 'total_tokens' => 140],
        ])]);

        return (new GeminiService('fake-key', 'gemini-2.5-flash'))
            ->chat([['role' => 'user', 'content' => 'ce que je te dis, ça va où ?']], 9);
    }

    /** Sortie réelle du modèle, relevée le 2026-10-02 (âge 9, « ce que je te dis, ça va où ? »). */
    public function test_text_written_after_the_technical_lines_never_reaches_the_child(): void
    {
        $answer = "Je comprends que tu te poses des questions sur moi. Je suis un peu comme une coach : je t'écoute, je te donne des conseils et je t'aide à grandir, à prendre confiance en toi et à devenir un jour quelqu'un qui montre l'exemple.\n\n"
            . "Qu'est-ce qui t'a fait poser cette question aujourd'hui ?";
        $summary = "L'enfant a demandé où allaient ses messages. Care s'est présentée comme une coach qui écoute et donne des conseils pour l'aider à grandir, puis a ramené la conversation vers l'enfant.";

        $result = $this->reply(
            $answer . "\nALERT_TYPE: none\nZONE: green\nRESUME: " . $summary . $answer
            . "\nALERT_TYPE: none\nZONE: green\nRESUME: " . $summary
        );

        $this->assertSame($answer, $result['message']);
        $this->assertSame(1, substr_count($result['message'], '?'), 'Une seule question doit arriver à l\'enfant.');
        $this->assertSame('green', $result['zone']);
        $this->assertStringStartsWith("L'enfant a demandé où allaient ses messages.", (string) $result['summary']);
    }

    public function test_a_summary_written_on_several_lines_is_never_shown_to_the_child(): void
    {
        $result = $this->reply(
            "Merci de me le dire. Ça arrive souvent ?\n"
            . "ALERT_TYPE: harcelement\n"
            . "ZONE: orange\n"
            . "RESUME: L'enfant raconte que des élèves se moquent de lui à la récréation,\n"
            . "qu'il mange seul et qu'il n'ose pas en parler à ses parents."
        );

        $this->assertSame('Merci de me le dire. Ça arrive souvent ?', $result['message']);
        $this->assertSame('harcelement', $result['alert_type']);
        $this->assertSame('orange', $result['zone']);
        $this->assertStringContainsString('se moquent de lui', (string) $result['summary']);
    }

    /** La première ligne technique, quelle qu'elle soit, ferme l'affichage. */
    public function test_the_display_stops_at_the_first_technical_line_whatever_it_is(): void
    {
        $result = $this->reply(
            "Je t'écoute.\n"
            . "ZONE: yellow\n"
            . "Tu veux m'en dire plus ?\n"
            . "ALERT_TYPE: stress\n"
            . "RESUME: L'enfant se dit fatigué avant un contrôle de mathématiques."
        );

        $this->assertSame("Je t'écoute.", $result['message']);
        $this->assertSame('yellow', $result['zone']);
        $this->assertSame('stress', $result['alert_type']);
        $this->assertStringContainsString('contrôle de mathématiques', (string) $result['summary']);
    }

    /** Une ligne technique mise en forme (gras, titre) reste une ligne technique. */
    public function test_a_technical_line_dressed_in_markdown_still_closes_the_display(): void
    {
        $result = $this->reply(
            "Je suis là pour toi.\n"
            . "**RESUME:** L'enfant exprime une tristesse persistante depuis plusieurs jours.\n"
            . "Est-ce que tu veux qu'on en parle ?"
        );

        $this->assertSame('Je suis là pour toi.', $result['message']);
    }

    /** Le modèle respecte le protocole : rien ne change pour l'enfant. */
    public function test_a_reply_that_follows_the_protocol_is_shown_whole(): void
    {
        $result = $this->reply(
            "Coucou ! Tu as passé une bonne journée ?\n\nRaconte-moi ce que tu as fait.\n"
            . "ALERT_TYPE: none\nZONE: green\nRESUME: L'enfant salue Care et commence à parler de sa journée."
        );

        $this->assertSame("Coucou ! Tu as passé une bonne journée ?\n\nRaconte-moi ce que tu as fait.", $result['message']);
    }
}
