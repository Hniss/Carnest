<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Optimisation de l'interface enfant (2026-10-21), point 1 : Care au style peluche (visuels
 * validés par Hamza), bloc d'accueil dans la discussion, rebond unique à chaque réponse,
 * réaction à l'humeur choisie par un bouton de réponse rapide, pictogrammes à 48 px.
 */
class CarePelucheTest extends TestCase
{
    use RefreshDatabase;

    private const EXPRESSIONS = ['base', 'heureuse', 'calme', 'attentive', 'encourageante'];

    private const MOODS = [
        'ca-va' => 'heureuse',
        'bof' => 'attentive',
        'pas-top' => 'encourageante',
        'complique' => 'calme',
        'je-ne-sais-pas-trop' => 'attentive',
    ];

    private function loginChild(int $age = 10): Child
    {
        $child = Child::factory()->for(School::factory()->create())->create(['age' => $age]);
        $this->actingAs($child, 'child');
        RateLimiter::clear(ChatInterface::rateLimitKey((int) $child->id));
        RateLimiter::clear(ChatInterface::replyRateLimitKey((int) $child->id));

        return $child;
    }

    private function aiReply(): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn([
            'message' => "D'accord, je t'écoute.", 'zone' => 'green', 'alert_type' => null,
            'is_critical' => false, 'low_confidence' => false, 'summary' => null, 'tokens' => 5, 'model' => 'test',
        ]);
        $this->app->instance(AIService::class, $mock);
    }

    private function css(string $html): string
    {
        return substr($html, strpos($html, '<style>'));
    }

    public function test_validated_visuals_are_in_place_and_the_old_avatar_is_kept(): void
    {
        foreach ([...self::EXPRESSIONS, 'yeux-fermes'] as $name) {
            $file = public_path("img/care/care-peluche-{$name}.webp");
            $this->assertFileExists($file);
            $this->assertLessThan(60 * 1024, filesize($file));
        }
        $this->assertFileExists(public_path('img/care/care-peluche-visage.webp'));
        $this->assertFileExists(public_path('img/care/care-avatar.png'));
        foreach (array_keys(self::MOODS) as $slug) {
            $this->assertFileExists(public_path("img/care/meteo/{$slug}.png"));
        }
    }

    public function test_the_flat_drawing_is_no_longer_shown_in_the_chat(): void
    {
        $this->loginChild(9);

        Livewire::test(ChatInterface::class)
            ->assertDontSee('care-avatar.png', false)
            ->assertSee('img/care/care-peluche-base.webp', false);
    }

    public function test_header_keeps_its_current_size(): void
    {
        $this->loginChild(9);
        $html = Livewire::test(ChatInterface::class)->html();
        $this->assertMatchesRegularExpression('/<img[^>]*care-peluche-base\.webp[^>]*data-care-avatar="header"[^>]*height="64"/s', $html);

        $this->loginChild(13);
        $html = Livewire::test(ChatInterface::class)->html();
        $this->assertMatchesRegularExpression('/<img[^>]*care-peluche-base\.webp[^>]*data-care-avatar="header"[^>]*height="40"/s', $html);
    }

    public function test_blink_uses_the_validated_closed_eyes_visual(): void
    {
        $this->loginChild(9);
        $html = Livewire::test(ChatInterface::class)->html();

        $this->assertMatchesRegularExpression('/<img[^>]*care-peluche-yeux-fermes\.webp[^>]*data-care-blink/s', $html);
        $this->assertStringNotContainsString('care-eyelid', $html);
        $this->assertStringContainsString('care-avatar-greet', $html);
    }

    public function test_welcome_block_sits_above_the_welcome_message_inside_the_scrolling_thread(): void
    {
        $this->loginChild(9);
        $html = Livewire::test(ChatInterface::class)->html();
        $html = substr($html, strpos($html, 'wire:id='));

        $thread = strpos($html, 'id="messages"');
        $block = strpos($html, 'data-care-welcome');
        $firstReply = strpos($html, 'data-care-reply');
        $this->assertNotFalse($block);
        $this->assertTrue($thread < $block && $block < $firstReply);
        $this->assertStringContainsString('wire:ignore', substr($html, $block - 300, 600));
    }

    public function test_welcome_block_is_96px_on_mobile_120px_from_tablet_and_64px_for_teens(): void
    {
        $this->loginChild(9);
        $css = $this->css(Livewire::test(ChatInterface::class)->html());

        $this->assertMatchesRegularExpression('/\.care-welcome-figure\s*\{[^}]*height:\s*96px/', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 640px\)\s*\{[^@]*\.care-welcome-figure\s*\{[^}]*height:\s*120px/', $css);
        $this->assertMatchesRegularExpression('/\.care-welcome\.is-teen \.care-welcome-figure\s*\{[^}]*height:\s*64px\s*!important/', $css);

        $this->loginChild(13);
        Livewire::test(ChatInterface::class)->assertSee('care-welcome is-teen', false);
    }

    public function test_bubble_thumbnail_is_28px_face_crop_and_absent_for_teens(): void
    {
        $this->loginChild(9);
        $html = Livewire::test(ChatInterface::class)->html();
        $this->assertMatchesRegularExpression('/<img[^>]*care-peluche-visage\.webp[^>]*data-care-avatar="bubble"[^>]*height="28"/s', $html);

        $this->loginChild(13);
        Livewire::test(ChatInterface::class)
            ->assertDontSee('data-care-avatar="bubble"', false)
            ->assertDontSee('care-peluche-visage', false);
    }

    public function test_each_quick_reply_carries_the_mood_decided_by_hamza(): void
    {
        $this->loginChild(10);
        $html = Livewire::test(ChatInterface::class)->html();

        foreach (self::MOODS as $slug => $mood) {
            $this->assertMatchesRegularExpression(
                '/quick-reply--' . preg_quote($slug, '/') . '"[^>]*data-care-mood="' . $mood . '"/s',
                $html,
                "Expression attendue pour {$slug} : {$mood}"
            );
        }
    }

    public function test_welcome_care_holds_one_image_per_expression_and_no_sad_or_worried_face(): void
    {
        $this->loginChild(10);
        $html = Livewire::test(ChatInterface::class)->html();
        $block = substr($html, strpos($html, 'data-care-welcome'));
        $block = substr($block, 0, strpos($block, 'data-care-reply'));

        foreach (self::EXPRESSIONS as $expression) {
            $this->assertStringContainsString("care-peluche-{$expression}.webp", $block);
            $this->assertStringContainsString("data-care-expression=\"{$expression}\"", $block);
        }
        foreach (['triste', 'inquiet', 'peur', 'colere'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_mood_lives_only_in_the_browser_and_never_depends_on_the_server_zone(): void
    {
        $this->loginChild(10);
        $this->aiReply();

        $c = Livewire::test(ChatInterface::class)->call('quickReply', 2)->call('fetchReply');

        $this->assertArrayNotHasKey('mood', $c->instance()->all());
        $html = $c->html();
        $this->assertStringNotContainsString('data-care-mood="{{', $html);
        $this->assertDoesNotMatchRegularExpression('/data-care-(mood|expression)="[^"]*(green|yellow|orange|red|zone)/', $html);
        foreach (['chat_sessions', 'children'] as $table) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn($table, 'mood'));
        }
    }

    public function test_mood_fades_in_300ms_and_returns_to_base_when_care_replies(): void
    {
        $this->loginChild(10);
        $html = Livewire::test(ChatInterface::class)->html();

        $this->assertMatchesRegularExpression('/\.care-welcome-figure img\s*\{[^}]*transition:\s*opacity\s*\.3s/', $this->css($html));
        $this->assertStringContainsString("x-on:care-mood.window", $html);
        $this->assertStringContainsString("new CustomEvent('care-mood', { detail: 'base' })", html_entity_decode($html, ENT_QUOTES));
    }

    public function test_bounce_is_a_single_400ms_jump_on_each_care_reply(): void
    {
        $this->loginChild(10);
        $html = Livewire::test(ChatInterface::class)->html();
        $css = $this->css($html);

        $this->assertMatchesRegularExpression('/\.care-bounce\s*\{[^}]*animation:\s*care-bounce\s+\.4s[^;}]*\b1\b/', $css);
        $this->assertStringNotContainsString('care-bounce .4s ease-out infinite', $css);
        $this->assertStringContainsString('data-care-reply', $html);
        $this->assertStringContainsString("classList.add('care-bounce')", html_entity_decode($html, ENT_QUOTES));
    }

    public function test_reduced_motion_stops_bounce_greet_blink_and_mood_fade(): void
    {
        $this->loginChild(10);
        $css = $this->css(Livewire::test(ChatInterface::class)->html());
        $reduced = substr($css, strpos($css, '@media (prefers-reduced-motion: reduce)'));

        $this->assertMatchesRegularExpression('/\.care-bounce[^{]*\{[^}]*animation:\s*none/', $reduced);
        $this->assertMatchesRegularExpression('/\.care-avatar-greet[^{]*\{[^}]*animation:\s*none/', $reduced);
        $this->assertMatchesRegularExpression('/\.care-blink[^{]*\{[^}]*animation:\s*none/', $reduced);
        $this->assertMatchesRegularExpression('/\.care-welcome-figure img[^{]*\{[^}]*transition:\s*none/', $reduced);
    }

    public function test_pictos_are_48px_with_the_label_always_written(): void
    {
        $this->loginChild(10);
        $html = Livewire::test(ChatInterface::class)->html();

        $this->assertMatchesRegularExpression('/\.quick-reply-picto\s*\{[^}]*width:\s*48px;\s*height:\s*48px/', $this->css($html));
        $this->assertSame(5, substr_count($html, 'data-quick-reply-picto'));
        $this->assertSame(5, preg_match_all('/data-quick-reply-picto[^>]*width="48" height="48"/', $html));
        foreach (ChatInterface::QUICK_REPLIES as $reply) {
            $this->assertStringContainsString('<span>' . e($reply['label']) . '</span>', $html);
        }
    }
}
