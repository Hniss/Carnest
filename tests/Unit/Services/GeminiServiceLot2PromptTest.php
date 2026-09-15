<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lot 2 §3/§5 — indicateur hors horaires (D5) et règles de personnalité de Care. */
class GeminiServiceLot2PromptTest extends TestCase
{
    private function systemPrompt(array $flags): string
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response(['choices' => [['message' => ['content' => "Ok.\nALERT_TYPE: none\nZONE: green"], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 1]]);
        });
        (new GeminiService('k', 'gemini-2.5-flash'))->chat([['role' => 'user', 'content' => 'salut']], 10, null, null, $flags);
        return $captured[0]['messages'][0]['content'];
    }

    public function test_out_of_hours_section_present_when_flag_true(): void
    {
        $prompt = $this->systemPrompt(['hors_horaires_scolaires' => true]);

        $this->assertStringContainsString('HORS HORAIRES SCOLAIRES', $prompt);
        $this->assertStringContainsString('MAINTENANT', $prompt);
        $this->assertMatchesRegularExpression('/HORS HORAIRES SCOLAIRES.*adulte de confiance proche.*2511.*141/s', $prompt);
    }

    public function test_out_of_hours_section_absent_when_flag_false(): void
    {
        $prompt = $this->systemPrompt(['hors_horaires_scolaires' => false]);
        $this->assertStringNotContainsString('HORS HORAIRES SCOLAIRES', $prompt);

        $prompt = $this->systemPrompt([]);
        $this->assertStringNotContainsString('HORS HORAIRES SCOLAIRES', $prompt);
    }

    public function test_personality_rules_are_in_system_prompt(): void
    {
        $prompt = $this->systemPrompt([]);

        $this->assertStringContainsString('PERSONNALITÉ DE CARE', $prompt);
        $this->assertStringContainsString("quand j'étais petite", $prompt);
        $this->assertStringContainsString("n'initie jamais un sujet sensible", mb_strtolower($prompt));
        $this->assertStringContainsString('récompense', $prompt);
    }
}
