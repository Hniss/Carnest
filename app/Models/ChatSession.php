<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatSession extends Model
{
    protected $fillable = [
        'child_id', 'school_id', 'zone',
        'ai_summary', 'low_confidence',
        'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'low_confidence' => 'boolean',
            'started_at'     => 'datetime',
            'ended_at'       => 'datetime',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class, 'session_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
