<?php

namespace Tests\Unit\Services;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\ChildContextBuilder;
use App\Services\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lot 2 §5 — mémoire de Care (sujets neutres) séparée du résumé clinique. */
class CareMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_care_memory_uses_dedicated_neutral_prompt(): void
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response(['choices' => [['message' => ['content' => 'Aime le football et les mathématiques. Prépare un exposé sur les dauphins.'], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 9], 'model' => 'gemini-2.5-flash']);
        });

        $r = (new GeminiService('k', 'gemini-2.5-flash'))->generateCareMemory([['role' => 'user', 'content' => "j'aime le foot"]], 10);

        $this->assertSame('Aime le football et les mathématiques. Prépare un exposé sur les dauphins.', $r['memory']);
        $this->assertSame(9, $r['tokens']);
        $system = $captured[0]['messages'][0]['content'];
        $this->assertStringContainsString("n'écris rien qui concerne une difficulté, une émotion négative, un conflit ou une personne nommée", $system);
        $this->assertStringNotContainsString('ALERT_TYPE', $system);
    }

    public function test_context_builder_injects_care_memories_not_clinical_summary(): void
    {
        $now = CarbonImmutable::parse('2026-09-15 12:00:00');
        $child = Child::factory()->for(School::factory()->create())->create(['age' => 10]);
        foreach ([1, 2, 3, 4] as $i) {
            $s = ChatSession::create(['child_id' => $child->id, 'school_id' => $child->school_id, 'started_at' => $now->subDays($i), 'ended_at' => $now->subDays($i), 'zone' => 'orange',
                'ai_summary' => "Résumé clinique {$i} : tristesse marquée.", 'care_memory' => "Sujet neutre {$i}."]);
            $s->ended_at = $now->subDays($i);
            $s->save();
        }

        $block = app(ChildContextBuilder::class)->build($child, $now);

        $this->assertStringContainsString('Sujet neutre 1.', $block);
        $this->assertStringContainsString('Sujet neutre 3.', $block);
        $this->assertStringNotContainsString('Sujet neutre 4.', $block);
        $this->assertStringNotContainsString('Résumé clinique', $block);
        $this->assertStringNotContainsString('tristesse marquée', $block);
        $this->assertStringContainsString('ne cite jamais', mb_strtolower($block));
    }

    public function test_context_builder_keeps_recurring_signals_as_posture_without_detail(): void
    {
        $now = CarbonImmutable::parse('2026-09-15 12:00:00');
        $child = Child::factory()->for(School::factory()->create())->create(['age' => 10]);
        foreach ([2, 6] as $i) {
            $s = ChatSession::create(['child_id' => $child->id, 'school_id' => $child->school_id, 'started_at' => $now->subDays($i), 'ended_at' => $now->subDays($i), 'zone' => 'orange']);
            $s->ended_at = $now->subDays($i);
            $s->save();
            $a = Alert::create(['session_id' => $s->id, 'child_id' => $child->id, 'school_id' => $child->school_id, 'type' => 'isolement', 'level' => 'moderate']);
            $a->created_at = $now->subDays($i);
            $a->save();
        }

        $block = app(ChildContextBuilder::class)->build($child, $now);

        $this->assertStringContainsString("sois particulièrement attentive au thème de l'isolement", $block);
        $this->assertStringNotContainsString('(2×)', $block);
    }
}
