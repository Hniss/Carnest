<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;

/** Notifications internes (cloche) — lot 1. Le paging d'alerte complet arrive au lot 2. */
class Notifier
{
    public function notify(User $user, string $type, string $title, ?string $body = null, ?string $link = null): AppNotification
    {
        return AppNotification::create([
            'user_id' => $user->id,
            'type'    => mb_substr($type, 0, 40),
            'title'   => mb_substr($title, 0, 255),
            'body'    => $body,
            'link'    => $link,
        ]);
    }
}
