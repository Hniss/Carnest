<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Préparation lot 2 (paging / escalade) — au lot 1 : accusé de réception du référent. */
class AlertNotification extends Model
{
    protected $fillable = ['alert_id', 'channel', 'recipient_id', 'escalation_step', 'sent_at', 'acked_at', 'payload'];

    protected function casts(): array
    {
        return [
            'sent_at'  => 'datetime',
            'acked_at' => 'datetime',
            'payload'  => 'array',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
