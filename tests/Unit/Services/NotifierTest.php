<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_creates_unread_app_notification_and_counts(): void
    {
        $user = User::factory()->create();

        $n = app(Notifier::class)->notify($user, 'message', 'Nouveau message', 'Corps', '/parent/messages');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id, 'type' => 'message', 'title' => 'Nouveau message', 'link' => '/parent/messages', 'read_at' => null,
        ]);
        $this->assertSame(1, $user->fresh()->unreadNotificationsCount());

        $n->markRead();
        $this->assertSame(0, $user->fresh()->unreadNotificationsCount());
    }
}
