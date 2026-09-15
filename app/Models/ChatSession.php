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
        'started_at', 'ended_at', 'last_activity_at',
        'tokens_used', 'prompt_version', 'model', 'care_memory',
    ];

    /**
     * D10 (v3) — ai_summary et care_memory sont chiffrés au repos (cast `encrypted`).
     * Aucune requête ne doit filtrer (where / like) sur ces colonnes.
     */
    protected function casts(): array
    {
        return [
            'low_confidence'   => 'boolean',
            'started_at'       => 'datetime',
            'ended_at'         => 'datetime',
            'last_activity_at' => 'datetime',
            'ai_summary'       => 'encrypted',
            'care_memory'      => 'encrypted',
            'tokens_used'      => 'integer',
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
