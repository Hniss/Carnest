<?php

namespace Tests\Feature\Lot3;

use App\Services\Adjudicator;
use App\Services\AIService;
use App\Services\FakeAIService;
use Tests\TestCase;

/** Lot 3 — le faux fournisseur n'est lié qu'en local avec AI_FAKE=1 ; jamais par défaut. */
class FakeAIServiceTest extends TestCase
{
    public function test_fake_provider_is_never_bound_by_default(): void
    {
        config(['services.ai.fake' => true]);
        $this->assertNotInstanceOf(FakeAIService::class, app(AIService::class), 'APP_ENV=testing : jamais le faux fournisseur.');
    }

    public function test_fake_provider_bound_only_in_local_with_flag(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.ai.fake' => false]);
        $this->app->forgetInstance(AIService::class);
        $this->assertNotInstanceOf(FakeAIService::class, app(AIService::class));

        config(['services.ai.fake' => '1']);
        $this->app->forgetInstance(AIService::class);
        $this->assertInstanceOf(FakeAIService::class, app(AIService::class));
    }

    public function test_fake_chat_returns_full_shape_and_detects_red_pattern(): void
    {
        $ai = new FakeAIService();

        $green = $ai->chat([['role' => 'user', 'content' => "j'ai bien joué à la récré"]], 9, 'f');
        $this->assertSame('green', $green['zone']);
        $this->assertFalse($green['is_critical']);
        $this->assertGreaterThan(0, $green['tokens']);
        $this->assertSame(FakeAIService::MODEL, $green['model']);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}]/u', $green['message'], 'aucun emoji dans les réponses ajoutées au lot 3');

        $red = $ai->chat([['role' => 'user', 'content' => 'je veux disparaître']], 9, 'f');
        $this->assertSame('red', $red['zone']);
        $this->assertSame('pensees_negatives', $red['alert_type']);
        $this->assertFalse($red['is_critical'], 'le message de sécurité officiel (ChatInterface::safetyMessage) prend le relais');
        $this->assertStringNotContainsStringIgnoringCase('alerte', $red['message']);

        $analysis = $ai->analyzeSession([['role' => 'user', 'content' => 'je veux disparaître']], 9);
        $this->assertSame('red', $analysis['zone']);
        $this->assertArrayHasKey('lowConfidence', $analysis);
    }

    public function test_fake_client_feeds_the_adjudicator_with_valid_json(): void
    {
        $result = (new Adjudicator(new FakeAIService()))->adjudicate(
            [['role' => 'user', 'content' => 'je veux disparaître']], 'red', 'pensees_negatives'
        );
        $this->assertSame('confirmee', $result['verdict']);
        $this->assertSame('red', $result['zone']);
        $this->assertSame('pensees_negatives', $result['type']);
        $this->assertNotEmpty($result['signals']);
    }
}
