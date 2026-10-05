<?php

namespace Tests\Feature\Lot4;

use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\ThirdPartyNameScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Retour des pédopsychiatres (2026-10-05) — un résumé destiné aux adultes ne nomme jamais un
 * tiers : « Adam m'a frappé » devient « un camarade a frappé Yassine ». Le prénom de l'élève
 * concerné reste. Contrôle serveur : les prénoms des autres élèves de l'école deviennent
 * « un camarade », ceux du personnel de l'école « un adulte ».
 */
class ThirdPartyNamesInSummariesTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private function classroom(): array
    {
        $school  = School::factory()->create();
        $yassine = Child::factory()->for($school)->create(['name' => 'Yassine Benali', 'age' => 10]);
        $adam    = Child::factory()->for($school)->create(['name' => 'Adam Kettani']);

        return [$school, $yassine, $adam];
    }

    private function scrub(string $text, Child $child): string
    {
        return app(ThirdPartyNameScrubber::class)->scrub($text, $child);
    }

    public function test_classmate_first_name_becomes_un_camarade_and_child_name_stays(): void
    {
        [, $yassine] = $this->classroom();

        $this->assertSame('Un camarade a frappé Yassine à la récréation.', $this->scrub('Adam a frappé Yassine à la récréation.', $yassine));
        $this->assertSame('Yassine raconte qu\'un camarade l\'a frappé.', $this->scrub('Yassine raconte qu\'Adam l\'a frappé.', $yassine));
        $this->assertSame('Yassine a peur d\'un camarade. Un camarade le pousse.', $this->scrub('Yassine a peur d\'Adam Kettani. adam le pousse.', $yassine));
    }

    public function test_school_staff_name_becomes_un_adulte(): void
    {
        [$school, $yassine] = $this->classroom();
        $this->makeReferent($school, ['name' => 'Nadia Tazi']);

        $this->assertSame('Yassine dit qu\'un adulte lui a crié dessus.', $this->scrub('Yassine dit que Nadia Tazi lui a crié dessus.', $yassine));
    }

    public function test_words_that_are_not_names_are_left_alone(): void
    {
        [, $yassine] = $this->classroom();

        $this->assertSame('Yassine parle de madame et d\'adamantium.', $this->scrub('Yassine parle de madame et d\'adamantium.', $yassine));
    }

    public function test_summaries_are_scrubbed_when_saved_on_session_and_alert(): void
    {
        [$school, $yassine] = $this->classroom();

        $session = ChatSession::create(['child_id' => $yassine->id, 'school_id' => $school->id, 'started_at' => now(), 'ai_summary' => 'Yassine raconte qu\'Adam l\'a frappé.']);
        $this->assertSame('Yassine raconte qu\'un camarade l\'a frappé.', $session->fresh()->ai_summary);

        $alert = Alert::create(['session_id' => $session->id, 'child_id' => $yassine->id, 'school_id' => $school->id, 'type' => 'harcelement', 'level' => 'moderate', 'summary' => 'Adam frappe Yassine.']);
        $this->assertSame('Un camarade frappe Yassine.', $alert->fresh()->summary);
    }

    public function test_adam_m_a_frappe_in_the_chat_never_reaches_the_adult_summary(): void
    {
        [, $yassine] = $this->classroom();
        $this->actingAs($yassine, 'child');

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn([
            'message' => 'Merci de me le dire. Est-ce qu\'un adulte de l\'école est au courant ?',
            'zone' => 'orange', 'alert_type' => 'harcelement', 'is_critical' => false, 'low_confidence' => false,
            'summary' => 'Yassine raconte qu\'Adam l\'a frappé à la récréation.', 'tokens' => 5, 'model' => 'test',
        ]);
        $this->app->instance(AIService::class, $mock);

        Livewire::test(ChatInterface::class)->set('input', 'Adam m\'a frappé')->call('sendMessage')->call('fetchReply');

        $session = ChatSession::where('child_id', $yassine->id)->first();
        $this->assertSame('Yassine raconte qu\'un camarade l\'a frappé à la récréation.', $session->ai_summary);
        $this->assertSame('Yassine raconte qu\'un camarade l\'a frappé à la récréation.', Alert::where('session_id', $session->id)->first()->summary);
    }
}
