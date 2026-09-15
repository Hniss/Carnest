<?php

namespace App\Livewire\Shared;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Cloche de notifications internes (lot 1 §7) : compteur non lues, liste, marquer lu. */
class NotificationBell extends Component
{
    public bool $open = false;

    public function markRead(int $id): ?string
    {
        $n = AppNotification::where('user_id', Auth::id())->find($id);
        abort_unless($n, 404);
        $n->markRead();

        return $n->link;
    }

    public function markAllRead(): void
    {
        AppNotification::where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.shared.notification-bell', [
            'unread'        => $user->unreadNotificationsCount(),
            'notifications' => $user->appNotifications()->limit(10)->get(),
        ]);
    }
}
