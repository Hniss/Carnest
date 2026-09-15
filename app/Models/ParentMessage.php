<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentMessage extends Model
{
    protected $fillable = ['thread_id', 'sender_id', 'sender_role', 'body', 'sent_at', 'read_at'];

    /** Corps chiffré au repos ; jamais filtré en SQL. */
    protected function casts(): array
    {
        return [
            'body'    => 'encrypted',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ParentThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
