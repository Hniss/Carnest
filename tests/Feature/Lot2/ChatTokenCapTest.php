<?php

namespace Tests\Feature\Lot2;

use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\AppNotification;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\AIService;
use App\Services\Adjudicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** D8 — plafond journalier : clôture chaleureuse en vert, aucune coupure sinon, référent notifié une fois par jour. */
class ChatTokenCapTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private School $school;
    private Child $child;
    private User $ref;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-09-15 10:00:00');
        $this->school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $this->school->id], ['daily_token_cap' => 100]);
        $this->ref   = $this->makeReferent($this->school);
        $this->child = Child::factory()->for($this->school)->create(['age' => 10, 'name' => 'Yassine Démo']);
        $this->actingAs($this->child, 'child');
        $adj = Mockery::mock(Adjudicator::class);
        $adj->shouldReceive('adjudicate')->andReturn(['verdict' => 'confirmee', 'zone' => 'orange', 'type' => 'isolement', 'signals' => []]);
        $this->app->instance(Adjudicator::class, $adj);
    }

    private function reply(string $zone, ?string $type, int $tokens): array
    {
        return ['message' => "Je t'écoute.", 'zone' => $zone, 'alert_type' => $type, 'is_critical' => $zone === 'red',
            'low_confidence' => false, 'tokens' => $tokens, 'model' => 'gemini-test'];
    }

    private function mockAi(array ...$replies): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn(...$replies);
        $mock->shouldReceive('analyzeSession')->andReturn(['summary' => 'Résumé.', 'zone' => 'green', 'alert_type' => null, 'lowConfidence' => false, 'tokens' => 0, 'model' => 'gemini-test']);
        $mock->shouldReceive('generateCareMemory')->andReturn(['memory' => 'Aime le football.', 'tokens' => 0, 'model' => 'gemini-test']);
        $this->app->instance(AIService::class, $mock);
    }

    public function test_green_over_cap_closes_warmly_without_any_quota_wording(): void
    {
        $this->mockAi($this->reply('green', null, 120));

        $c = Livewire::test(ChatInterface::class)->set('input', 'salut')->call('sendMessage')->call('fetchReply');

        $this->assertTrue($c->get('sessionClosed'));
        $this->assertNotNull(ChatSession::find($c->get('sessionId'))->ended_at);
        $last = collect($c->get('messages'))->last()['content'];
        $this->assertContains($last, ChatInterface::CAP_CLOSING_MESSAGES);
        foreach ($c->get('messages') as $m) {
            foreach (['quota', 'limite', 'token', 'alerte', 'plafond'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $m['content']);
            }
        }
        $c->assertDontSee('quota')->assertDontSee('plafond')->assertDontSee('limite');
        $this->assertSame(0, Alert::count());
        $this->assertSame(1, AppNotification::where('user_id', $this->ref->id)->where('type', 'usage_eleve')->count());
        $notif = AppNotification::where('user_id', $this->ref->id)->first();
        $this->assertSame('Usage inhabituellement élevé', $notif->title);
        $this->assertStringContainsString("Yassine a dépassé le volume d'échanges habituel aujourd'hui, à surveiller.", $notif->body);
    }

    public function test_orange_over_cap_never_cuts_the_conversation(): void
    {
        $this->mockAi($this->reply('orange', 'isolement', 120), $this->reply('orange', 'isolement', 50));

        $c = Livewire::test(ChatInterface::class)
            ->set('input', 'je suis seul')->call('sendMessage')->call('fetchReply')
            ->set('input', 'encore')->call('sendMessage')->call('fetchReply');

        $this->assertFalse($c->get('sessionClosed'));
        $this->assertNull(ChatSession::find($c->get('sessionId'))->ended_at);
        $this->assertSame(1, Alert::count());
        $this->assertSame(1, AppNotification::where('user_id', $this->ref->id)->where('type', 'usage_eleve')->count());
    }

    public function test_yellow_over_cap_continues(): void
    {
        $this->mockAi($this->reply('yellow', null, 120));

        $c = Livewire::test(ChatInterface::class)->set('input', 'bof')->call('sendMessage')->call('fetchReply');

        $this->assertFalse($c->get('sessionClosed'));
    }

    public function test_referent_notified_once_per_day_only(): void
    {
        $this->mockAi($this->reply('green', null, 120));
        Livewire::test(ChatInterface::class)->set('input', 'salut')->call('sendMessage')->call('fetchReply');

        $this->mockAi($this->reply('green', null, 120));
        Livewire::test(ChatInterface::class)->set('input', 'salut')->call('sendMessage')->call('fetchReply');
        $this->assertSame(1, AppNotification::where('type', 'usage_eleve')->count());
        $this->assertSame('2026-09-15', $this->child->fresh()->high_usage_notified_on->toDateString());

        Carbon::setTestNow('2026-09-16 10:00:00');
        $this->mockAi($this->reply('green', null, 120));
        Livewire::test(ChatInterface::class)->set('input', 'salut')->call('sendMessage')->call('fetchReply');
        $this->assertSame(2, AppNotification::where('type', 'usage_eleve')->count());
    }

    public function test_cap_zero_disables_the_limit(): void
    {
        SchoolSetting::where('school_id', $this->school->id)->update(['daily_token_cap' => 0]);
        $this->mockAi($this->reply('green', null, 999999));

        $c = Livewire::test(ChatInterface::class)->set('input', 'salut')->call('sendMessage')->call('fetchReply');

        $this->assertFalse($c->get('sessionClosed'));
        $this->assertSame(0, AppNotification::count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
