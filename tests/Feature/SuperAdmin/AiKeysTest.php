<?php

namespace Tests\Feature\SuperAdmin;

use App\Livewire\SuperAdmin\AiKeys as AiKeysPage;
use App\Models\AiCredential;
use App\Models\School;
use App\Models\User;
use App\Services\Adjudicator;
use App\Services\AIService;
use App\Services\AiKeys;
use App\Services\ClaudeAIService;
use App\Services\GeminiService;
use App\Services\OpenAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Clés d'IA saisies par le super-admin : chiffrées en base, jamais réaffichées (4 derniers
 * caractères), prioritaires sur le fichier du serveur, choix automatique du fournisseur
 * (Gemini, sinon OpenAI, sinon Claude), double vérification sur un fournisseur différent.
 */
class AiKeysTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    private const GEMINI = 'gemini-cle-de-test-0000000001ab12';
    private const OPENAI = 'sk-openai-cle-de-test-00000000cd34';
    private const CLAUDE = 'sk-ant-cle-de-test-000000000ef56';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.fake' => false,
            'services.ai.provider' => 'gemini',
            'services.ai.adjudicator_provider' => 'anthropic',
            'services.ai.gemini_key' => '', 'services.ai.openai_key' => '', 'services.ai.anthropic_key' => '',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function fresh(string $abstract): object
    {
        app()->forgetInstance($abstract);

        return app($abstract);
    }

    private function adjudicatorClient(): object
    {
        $adj = $this->fresh(Adjudicator::class);

        return (new \ReflectionProperty(Adjudicator::class, 'client'))->getValue($adj);
    }

    public function test_saved_key_is_encrypted_never_redisplayed_and_journaled_without_value(): void
    {
        $sa = $this->superAdmin();

        $page = Livewire::actingAs($sa)->test(AiKeysPage::class)
            ->set('inputs.gemini', self::GEMINI)
            ->call('save', 'gemini')
            ->assertHasNoErrors()
            ->assertSet('inputs.gemini', '');

        $raw = DB::table('ai_credentials')->where('provider', 'gemini')->value('api_key');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::GEMINI, $raw);
        $this->assertSame(self::GEMINI, AiCredential::where('provider', 'gemini')->first()->api_key);
        $this->assertSame('ab12', AiCredential::where('provider', 'gemini')->first()->last4);

        $page->assertSee('ab12')->assertDontSee(self::GEMINI);
        $this->actingAs($sa)->get('/superadmin/cles')->assertOk()->assertSee('ab12')->assertDontSee(self::GEMINI);

        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.ai_key.set.gemini', 'actor_id' => $sa->id]);
        $this->assertFalse(DB::table('audit_logs')->get()->contains(fn ($row) => str_contains(json_encode($row), self::GEMINI)));
    }

    public function test_key_in_database_wins_over_server_file_and_removal_falls_back(): void
    {
        config(['services.ai.gemini_key' => 'cle-du-fichier-serveur']);
        $sa = $this->superAdmin();

        $this->assertSame('cle-du-fichier-serveur', AiKeys::key('gemini'));

        Livewire::actingAs($sa)->test(AiKeysPage::class)->set('inputs.gemini', self::GEMINI)->call('save', 'gemini');
        $this->assertSame(self::GEMINI, AiKeys::key('gemini'));
        $this->assertSame('base', AiKeys::source('gemini'));

        Livewire::actingAs($sa)->test(AiKeysPage::class)->call('remove', 'gemini');
        $this->assertSame('cle-du-fichier-serveur', AiKeys::key('gemini'));
        $this->assertSame('serveur', AiKeys::source('gemini'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.ai_key.remove.gemini']);
    }

    public function test_provider_is_chosen_automatically_gemini_then_openai_then_claude(): void
    {
        AiKeys::store('openai', self::OPENAI, null);
        $this->assertSame('openai', AiKeys::primary());
        $this->assertInstanceOf(OpenAIService::class, $this->fresh(AIService::class));

        AiKeys::store('gemini', self::GEMINI, null);
        $this->assertSame('gemini', AiKeys::primary());
        $this->assertInstanceOf(GeminiService::class, $this->fresh(AIService::class));

        AiKeys::remove('gemini', null);
        AiKeys::remove('openai', null);
        AiKeys::store('anthropic', self::CLAUDE, null);
        $this->assertSame('anthropic', AiKeys::primary());
        $this->assertInstanceOf(ClaudeAIService::class, $this->fresh(AIService::class));
    }

    public function test_double_verification_uses_a_different_provider_read_from_the_same_source(): void
    {
        AiKeys::store('openai', self::OPENAI, null);
        AiKeys::store('anthropic', self::CLAUDE, null);

        $this->assertSame('openai', AiKeys::primary());
        $this->assertInstanceOf(ClaudeAIService::class, $this->adjudicatorClient());

        AiKeys::store('gemini', self::GEMINI, null);
        $this->assertSame('gemini', AiKeys::primary());
        $this->assertNotInstanceOf(GeminiService::class, $this->adjudicatorClient());
    }

    public function test_key_validation_and_unknown_provider(): void
    {
        $sa = $this->superAdmin();

        Livewire::actingAs($sa)->test(AiKeysPage::class)
            ->set('inputs.openai', '  ')->call('save', 'openai')->assertHasErrors('inputs.openai');
        $this->assertDatabaseCount('ai_credentials', 0);

        Livewire::actingAs($sa)->test(AiKeysPage::class)->call('save', 'mistral')->assertStatus(404);
    }

    public function test_school_admin_cannot_use_the_keys_component(): void
    {
        $admin = $this->makeAdmin(School::factory()->create());

        Livewire::actingAs($admin)->test(AiKeysPage::class)->assertForbidden();
        $this->assertDatabaseCount('ai_credentials', 0);
    }

    public function test_opening_the_page_is_journaled(): void
    {
        $sa = $this->superAdmin();

        $this->actingAs($sa)->get('/superadmin/cles')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.view.ai_keys', 'actor_id' => $sa->id]);
    }
}
