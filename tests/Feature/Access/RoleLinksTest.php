<?php

namespace Tests\Feature\Access;

use App\Livewire\ParentSpace\Consent;
use App\Livewire\Referent\Delegation;
use App\Livewire\Referent\Messages as ReferentMessages;
use App\Livewire\Shared\NotificationBell;
use App\Models\AppNotification;
use App\Models\Child;
use App\Models\ReferentDelegation;
use App\Models\School;
use App\Models\User;
use App\Services\AlertPager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Les liens des menus et des notifications de chaque rôle ne mènent jamais vers un espace
 * qui lui est interdit (403).
 *
 * Défauts trouvés (2026-10-02) : le menu latéral donnait à tout compte qui n'est ni référent
 * ni administrateur délégué… le menu de l'ADMINISTRATION (branche par défaut) — un parent
 * sur /profile voyait cinq liens, tous en 403 ; et la cloche suivait aveuglément le lien
 * enregistré, y compris quand l'accès avait disparu depuis (délégation terminée).
 */
class RoleLinksTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    /** Liens internes (balises <a href>) d'une page, sans ancre ni doublon. */
    private function internalLinks(string $html): array
    {
        preg_match_all('/<a\s[^>]*href="([^"]+)"/', $html, $m);

        return collect($m[1])
            ->map(fn ($href) => html_entity_decode($href, ENT_QUOTES | ENT_HTML5))
            ->filter(fn ($href) => str_starts_with($href, '/') || str_starts_with($href, url('/')))
            ->map(function ($href) {
                $p = parse_url($href);
                return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
            })
            ->unique()->values()->all();
    }

    private function assertEveryLinkOpens(User $user, string $page): void
    {
        $html = $this->actingAs($user)->get($page)->assertOk()->getContent();
        $links = $this->internalLinks($html);
        $this->assertNotEmpty($links, "Aucun lien trouvé sur {$page}.");

        foreach ($links as $link) {
            $status = $this->actingAs($user)->get($link)->getStatusCode();
            $this->assertNotSame(403, $status, "Rôle {$user->role} : le lien {$link} présent sur {$page} mène à un 403.");
            $this->assertLessThan(500, $status, "Rôle {$user->role} : le lien {$link} présent sur {$page} plante ({$status}).");
        }
    }

    public function test_menu_links_of_every_role_open_without_403(): void
    {
        $school = School::factory()->create();
        $referent = $this->makeReferent($school);
        $admin = $this->makeAdmin($school);
        $delegateAdmin = $this->makeAdmin($school);
        $this->makeActiveDelegation($school, $referent, $delegateAdmin);
        $child = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child);

        foreach ([[$admin, '/dashboard'], [$admin, '/profile'],
                  [$referent, '/dashboard-referent'], [$referent, '/profile'],
                  [$delegateAdmin, '/dashboard-referent'], [$delegateAdmin, '/dashboard'],
                  [$parent, '/parent'], [$parent, '/profile']] as [$user, $page]) {
            $this->assertEveryLinkOpens($user, $page);
        }
    }

    public function test_parent_menu_outside_the_parent_layout_lists_parent_pages_only(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child);

        $links = $this->internalLinks($this->actingAs($parent)->get('/profile')->assertOk()->getContent());

        $this->assertContains('/parent', $links);
        foreach ($links as $link) {
            $this->assertFalse(str_starts_with($link, '/dashboard'), "Le menu d'un parent propose {$link}.");
        }
    }

    public function test_notification_links_open_without_403_for_their_recipient(): void
    {
        $school = School::factory()->create();
        $referent = $this->makeReferent($school);
        $admin = $this->makeAdmin($school);
        $delegateAdmin = $this->makeAdmin($school);
        $child = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child);

        // Délégation activée par le référent (notification au délégué).
        Livewire::actingAs($referent)->test(Delegation::class)
            ->set('delegateId', $delegateAdmin->id)
            ->set('startDate', now()->subDay()->toDateString())->set('endDate', now()->addDays(3)->toDateString())
            ->call('create')->assertHasNoErrors();
        Livewire::actingAs($referent)->test(Delegation::class)->call('activate', ReferentDelegation::latest('id')->value('id'));

        // Signal vital : référent, délégué et administration notifiés.
        app(AlertPager::class)->page($this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']));

        // Message du référent au parent.
        Livewire::actingAs($referent)->test(ReferentMessages::class)
            ->set('newParentId', $parent->id)->set('newChildId', $child->id)
            ->set('newSubject', 'Point de la semaine')->set('newBody', 'Bonjour, pouvons-nous échanger ?')
            ->call('createThread')->assertHasNoErrors();

        // Retrait de consentement : référent et administration notifiés.
        Livewire::actingAs($parent)->test(Consent::class)->call('withdraw', $child->id)->assertHasNoErrors();

        $notifications = AppNotification::whereNotNull('link')->get();
        $this->assertGreaterThanOrEqual(6, $notifications->count());
        $this->assertEqualsCanonicalizing(
            ['referent', 'admin', 'parent'],
            $notifications->map(fn ($n) => $n->user->role)->unique()->values()->all()
        );

        foreach ($notifications as $n) {
            $status = $this->actingAs($n->user)->get($n->link)->getStatusCode();
            $this->assertNotSame(403, $status, "Notification « {$n->title} » ({$n->user->role}) : {$n->link} mène à un 403.");
        }
    }

    public function test_bell_never_follows_a_link_that_became_forbidden(): void
    {
        $school = School::factory()->create();
        $referent = $this->makeReferent($school);
        $delegateAdmin = $this->makeAdmin($school);
        $delegation = $this->makeActiveDelegation($school, $referent, $delegateAdmin);
        $child = Child::factory()->for($school)->create();
        $alert = $this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']);
        app(AlertPager::class)->page($alert);

        $notification = AppNotification::where('user_id', $delegateAdmin->id)->where('link', '/dashboard-referent/alertes/' . $alert->id)->firstOrFail();

        // Délégation en cours : le lien est suivi.
        Livewire::actingAs($delegateAdmin)->test(NotificationBell::class)
            ->call('markRead', $notification->id)->assertReturned('/dashboard-referent/alertes/' . $alert->id);

        // Délégation révoquée : l'espace référent est fermé à cet administrateur → son accueil.
        $delegation->update(['revoked_at' => now()]);
        $this->actingAs($delegateAdmin)->get($notification->link)->assertForbidden();
        Livewire::actingAs($delegateAdmin)->test(NotificationBell::class)
            ->call('markRead', $notification->id)->assertReturned('/dashboard');
    }

    public function test_bell_navigates_to_the_link_returned_by_the_server(): void
    {
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school);
        app(\App\Services\Notifier::class)->notify($admin, 'alerte_admin', 'Signal vital détecté', null, '/dashboard');

        $html = Livewire::actingAs($admin)->test(NotificationBell::class)->html();

        // Le navigateur part vers l'adresse RENDUE par markRead (vérifiée côté serveur),
        // jamais vers le lien brut écrit dans la page.
        $this->assertStringContainsString('$wire.markRead(', $html);
        $this->assertStringNotContainsString('window.location.assign(&quot;\/dashboard&quot;)', $html);
        $this->assertDoesNotMatchRegularExpression('/location\.assign\([^)]*dashboard/', $html);
    }
}
