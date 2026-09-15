<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model
{
    protected $fillable = [
        'session_id', 'child_id', 'school_id',
        'type', 'level', 'status', 'notified_at',
        'summary', 'signals', 'prompt_version', 'model', 'adjudication',
    ];

    /**
     * D10 (v3) — summary chiffré au repos (cast `encrypted`) ; jamais filtré en SQL.
     */
    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'summary'     => 'encrypted',
            'signals'     => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(AdminNote::class);
    }
}
