<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Le curseur reste dans la case d'écriture : après chaque envoi de l'enfant et après
 * chaque réponse de Care, sur ordinateur comme sur téléphone.
 *
 * Causes réelles mesurées dans un vrai navigateur (2026-10-02) :
 *  1. le bloc @script du chat n'était JAMAIS exécuté. Alpine compile ce bloc en
 *     `__self.result = <contenu>` ; un contenu qui commence par un commentaire puis
 *     `const` donne « Unexpected token 'const' » et tout le bloc est abandonné — le
 *     retour du focus, l'auto-défilement et la clôture à la fermeture de la fenêtre
 *     n'ont jamais tourné ;
 *  2. la case d'écriture passait en `disabled` pendant la réponse de Care : un champ
 *     désactivé perd le focus (et, sur téléphone, le clavier se ferme) ;
 *  3. `wire:submit` met lui aussi la case en lecture seule pendant l'envoi, et un clic
 *     (ou un toucher) sur le bouton d'envoi y déplace le focus.
 */
class ChatInputFocusTest extends TestCase
{
    use RefreshDatabase;

    private function loginChild(): Child
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 10]);
        $this->actingAs($child, 'child');

        return $child;
    }

    private function fakeCare(): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn([
            'message' => 'Merci de me raconter. Qu\'est-ce qui t\'a fait plaisir ?', 'zone' => 'green', 'alert_type' => null,
            'is_critical' => false, 'low_confidence' => false, 'tokens' => 5, 'model' => 'test',
        ]);
        $this->app->instance(AIService::class, $mock);
    }

    /** Contenu du bloc @script tel que Livewire le transmet à Alpine (page réelle /chat). */
    private function chatScript(): string
    {
        $html = $this->get('/chat')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/wire:effects="([^"]*)"/', $html);
        preg_match('/wire:effects="([^"]*)"/', $html, $m);
        $effects = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        $scripts = $effects['scripts'] ?? [];
        $this->assertCount(1, $scripts, 'Le chat doit transmettre exactement un bloc @script.');

        // Même extraction que Livewire (extractScriptTagContent) : l'intérieur de <script>, rogné.
        preg_match('/<script\b[^>]*>([\s\S]*?)<\/script>/', (string) reset($scripts), $s);

        return trim($s[1] ?? '');
    }

    /** Balise ouvrante d'un élément repéré par un attribut data-*, dans le HTML rendu. */
    private function tagWith(string $html, string $dataAttribute): string
    {
        $this->assertMatchesRegularExpression('/<[a-z]+[^>]*\b' . preg_quote($dataAttribute, '/') . '\b[^>]*>/s', $html, "Élément {$dataAttribute} introuvable.");
        preg_match('/<[a-z]+[^>]*\b' . preg_quote($dataAttribute, '/') . '\b[^>]*>/s', $html, $m);

        return $m[0];
    }

    /** Vrai si la balise porte l'attribut (et non une classe Tailwind « disabled:… »). */
    private function hasAttribute(string $tag, string $attribute): bool
    {
        return preg_match('/\s' . preg_quote($attribute, '/') . '(?=[\s>\/=])/', $tag) === 1;
    }

    public function test_chat_script_is_compiled_by_alpine_instead_of_being_dropped(): void
    {
        $this->loginChild();
        $script = $this->chatScript();

        // Règle exacte d'Alpine (generateFunctionFromString) : seul un contenu qui commence
        // par `let`/`const` ou `if (...)` est enveloppé dans une fonction ; tout le reste est
        // compilé comme `__self.result = <contenu>`. Les commentaires ne comptent pas pour le
        // compilateur : après eux, le contenu doit donc être une EXPRESSION, jamais une instruction.
        $wrappedByAlpine = preg_match('/^[\n\s]*if.*\(.*\)/', $script) === 1 || preg_match('/^(let|const)\s/', $script) === 1;
        if (! $wrappedByAlpine) {
            $code = preg_replace('~^(\s*(//[^\n]*(\n|$)|/\*.*?\*/))*\s*~s', '', $script);
            $this->assertDoesNotMatchRegularExpression(
                '/^(const|let|var|if|for|while|function|class|return|try|switch)\b/',
                $code,
                "Alpine compilerait ce bloc en « __self.result = const … » : erreur de syntaxe, tout le bloc @script serait abandonné."
            );
        }

        $this->assertStringContainsString("\$wire.on('focus-input'", $script);
    }

    public function test_input_stays_writable_while_care_is_answering_and_only_sending_is_blocked(): void
    {
        $this->loginChild();
        $this->fakeCare();

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'salut, ça va')
            ->call('sendMessage');
        $this->assertTrue($component->get('isTyping'));

        $html = $component->html();
        $input = $this->tagWith($html, 'data-chat-input');
        $send = $this->tagWith($html, 'data-chat-send');

        $this->assertFalse($this->hasAttribute($input, 'disabled'), 'Un champ désactivé perd le focus et ferme le clavier du téléphone.');
        $this->assertFalse($this->hasAttribute($input, 'readonly'));
        $this->assertTrue($this->hasAttribute($send, 'disabled'), 'Pendant la réponse de Care, seul l\'envoi est bloqué.');

        $component->call('fetchReply');
        $this->assertFalse($component->get('isTyping'));
        $this->assertFalse($this->hasAttribute($this->tagWith($component->html(), 'data-chat-send'), 'disabled'));
    }

    public function test_sending_while_care_is_answering_is_refused_and_keeps_the_text(): void
    {
        $this->loginChild();
        $this->fakeCare();

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'premier message')
            ->call('sendMessage')
            ->set('input', 'deuxième message préparé')
            ->call('sendMessage');

        $userMessages = collect($component->get('messages'))->where('role', 'user')->pluck('content')->values()->all();
        $this->assertSame(['premier message'], $userMessages);
        $this->assertSame('deuxième message préparé', $component->get('input'));

        $component->call('fetchReply')->call('sendMessage');
        $userMessages = collect($component->get('messages'))->where('role', 'user')->pluck('content')->values()->all();
        $this->assertSame(['premier message', 'deuxième message préparé'], $userMessages);
    }

    public function test_focus_is_given_back_after_the_send_and_after_the_reply(): void
    {
        $this->loginChild();
        $this->fakeCare();

        Livewire::test(ChatInterface::class)
            ->set('input', 'coucou')
            ->call('sendMessage')
            ->assertDispatched('focus-input');

        Livewire::test(ChatInterface::class)
            ->set('input', 'coucou')
            ->call('sendMessage')
            ->call('fetchReply')
            ->assertDispatched('focus-input');
    }

    public function test_sending_never_takes_the_focus_away_from_the_input(): void
    {
        $this->loginChild();

        $html = Livewire::test(ChatInterface::class)->html();

        // `wire:submit` passe la case en lecture seule pendant chaque envoi (Livewire,
        // supportDisablingFormsDuringRequest) : sur téléphone, le clavier se ferme.
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*\bwire:submit\b/', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*(x-on:submit\.prevent|@submit\.prevent)="\$wire\.sendMessage\(\)"/', $html);

        // Appuyer sur le bouton d'envoi ne déplace pas le focus hors de la case.
        $this->assertMatchesRegularExpression('/(x-on:mousedown\.prevent|@mousedown\.prevent)/', $this->tagWith($html, 'data-chat-send'));
    }
}
