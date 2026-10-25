<?php

namespace Tests\Feature\Security;

use App\Livewire\Shared\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E-001 (test d'intrusion Nergal du 2026-10-22, modéré) — un instantané Livewire n'était lié ni à la
 * session qui l'a produit ni au temps : rejouable depuis une autre session, sans expiration, avec un
 * jeton de version figé (« a-a-a »).
 *
 * Correctif : le jeton de version suit le déploiement (LIVEWIRE_RELEASE) ; chaque instantané porte
 * l'empreinte de la session émettrice et sa date d'émission, vérifiées avant tout traitement.
 */
class LivewireSnapshotHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function bell(?User $user = null)
    {
        $user ??= User::factory()->create(['role' => 'parent']);

        return Livewire::actingAs($user)->test(NotificationBell::class);
    }

    // ── Jeton de version ──────────────────────────────────────────────────────

    public function test_release_token_is_no_longer_the_livewire_default(): void
    {
        $this->assertNotSame('a', config('livewire.release_token'));
        $this->assertNotSame('', (string) config('livewire.release_token'));
    }

    public function test_snapshot_release_carries_the_configured_token(): void
    {
        config(['livewire.release_token' => 'build-test-123']);

        $this->assertStringContainsString('-build-test-123-', $this->bell()->snapshot['memo']['release']);
    }

    public function test_snapshot_from_a_previous_release_is_rejected_with_419(): void
    {
        $c = $this->bell();
        config(['livewire.release_token' => 'build-suivant']);

        $c->call('markAllRead')->assertStatus(419);
    }

    // ── Liaison à la session ──────────────────────────────────────────────────

    public function test_snapshot_memo_carries_a_session_fingerprint_not_the_raw_session_id(): void
    {
        $memo = $this->bell()->snapshot['memo'];

        $this->assertArrayHasKey('sid', $memo);
        $this->assertArrayHasKey('iat', $memo);
        $this->assertNotSame(session()->getId(), $memo['sid']);
        $this->assertStringNotContainsString(session()->getId(), json_encode($memo));
        $this->assertEqualsWithDelta(time(), $memo['iat'], 5);
    }

    public function test_normal_subsequent_request_from_the_same_session_is_accepted(): void
    {
        $this->bell()->call('markAllRead')->assertOk();
    }

    public function test_snapshot_replayed_from_another_session_is_rejected_with_419_and_french_message(): void
    {
        $c = $this->bell();

        session()->regenerate(); // une AUTRE session (même compte) rejoue l'instantané

        $c->call('markAllRead')->assertStatus(419)
            ->assertJsonPath('message', \App\Exceptions\SnapshotRejectedException::MESSAGE)
            ->assertJsonPath('reason', 'session-differente');
    }

    public function test_snapshot_replayed_by_another_account_is_rejected(): void
    {
        $victim = User::factory()->create(['role' => 'parent']);
        $c = $this->bell($victim);

        session()->regenerate();
        $this->actingAs(User::factory()->create(['role' => 'referent']));

        $c->call('markAllRead')->assertStatus(419);
    }

    // ── Expiration ────────────────────────────────────────────────────────────

    public function test_snapshot_older_than_thirty_minutes_is_rejected_with_419(): void
    {
        $c = $this->bell();

        $this->travel(31)->minutes();

        $c->call('markAllRead')->assertStatus(419);
    }

    public function test_snapshot_younger_than_thirty_minutes_is_still_accepted(): void
    {
        $c = $this->bell();

        $this->travel(29)->minutes();

        $c->call('markAllRead')->assertOk();
    }

    public function test_each_accepted_request_renews_the_snapshot_age(): void
    {
        $c = $this->bell();

        $this->travel(20)->minutes();
        $c->call('markAllRead')->assertOk();
        $this->travel(20)->minutes();
        $c->call('markAllRead')->assertOk(); // 40 min depuis l'émission initiale, 20 depuis le dernier échange
    }

    public function test_lifetime_follows_the_session_lifetime_when_shorter(): void
    {
        config(['session.lifetime' => 10]);
        $c = $this->bell();

        $this->travel(11)->minutes();

        $c->call('markAllRead')->assertStatus(419);
    }

    // ── Rejeu HTTP réel (route /livewire/update) ──────────────────────────────

    public function test_http_replay_of_a_login_page_snapshot_from_another_browser_session_is_refused(): void
    {
        $pageA = $this->get('/login')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $pageA->getContent(), $m);
        $snapshotA = html_entity_decode($m[1], ENT_QUOTES);

        // Session B : un autre navigateur, son propre jeton CSRF.
        $this->get('/login')->assertOk();
        $tokenB = csrf_token();

        $response = $this->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => $tokenB])
            ->postJson('/livewire/update', ['_token' => $tokenB, 'components' => [[
                'snapshot' => $snapshotA,
                'updates' => ['form.email' => 'x@example.com'],
                'calls' => [],
            ]]]);

        $response->assertStatus(419);
        $response->assertJsonPath('message', fn ($m) => str_contains($m, 'session'));
    }

    public function test_http_replay_with_a_rewritten_fingerprint_is_not_accepted(): void
    {
        $pageA = $this->get('/login')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $pageA->getContent(), $m);
        $snapshot = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        $this->get('/login')->assertOk(); // session B
        $tokenB = csrf_token();
        $snapshot['memo']['sid'] = \App\Livewire\Hooks\SnapshotSessionBinding::fingerprint(); // empreinte de B, somme de contrôle de A

        $response = $this->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => $tokenB])
            ->postJson('/livewire/update', ['_token' => $tokenB, 'components' => [[
                'snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => [],
            ]]]);

        $this->assertNotSame(200, $response->getStatusCode());
    }
}
