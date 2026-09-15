<?php

namespace Tests\Feature\Shared;

use App\Livewire\Shared\NotificationBell;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Lot 1 §7 — cloche de notifications : compteur, liste, marquer lu, cloisonnement par utilisateur. */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_counts_lists_and_marks_read_only_own_notifications(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $n1 = app(Notifier::class)->notify($user, 'message', 'Nouveau message', null, '/parent/messages');
        $n2 = app(Notifier::class)->notify($user, 'synthese', 'Nouvelle information');
        $foreign = app(Notifier::class)->notify($other, 'message', 'Message secret autrui');

        $c = Livewire::actingAs($user)->test(NotificationBell::class)
            ->assertSee('Nouveau message')->assertSee('Nouvelle information')->assertDontSee('Message secret autrui')
            ->assertSeeHtml('data-unread-count');
        $this->assertSame(2, $user->fresh()->unreadNotificationsCount());

        $c->call('markRead', $n1->id)->assertReturned('/parent/messages');
        $this->assertSame(1, $user->fresh()->unreadNotificationsCount());

        Livewire::actingAs($user)->test(NotificationBell::class)->call('markRead', $foreign->id)->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);

        $c->call('markAllRead');
        $this->assertSame(0, $user->fresh()->unreadNotificationsCount());
        $this->assertNull($foreign->fresh()->read_at);
    }
}
