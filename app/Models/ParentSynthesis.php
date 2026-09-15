<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Synthèse envoyée au parent — quatre blocs à formulation imposée (addendum v3 §5).
 * Tous les textes sont chiffrés au repos.
 */
class ParentSynthesis extends Model
{
    protected $table = 'parent_syntheses';

    public const DEFAULT_IDENTIFIED = 'CareNest a identifié des signaux pouvant indiquer une difficulté dans l\'expérience scolaire de votre enfant.';
    public const DEFAULT_SCHOOL_DID = 'Un entretien a été réalisé avec votre enfant le {date}.';
    public const DEFAULT_SCHOOL_PROPOSES = 'Un suivi est prévu {période}.';
    public const DEFAULT_PARENT_CAN = 'Vous pouvez échanger avec votre enfant sur son ressenti à l\'école, sans insister, en lui montrant que vous êtes disponible.';

    protected $fillable = [
        'child_id', 'alert_id', 'parent_id', 'sent_by',
        'identified', 'school_did', 'school_proposes', 'parent_can',
        'sent_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'identified'      => 'encrypted',
            'school_did'      => 'encrypted',
            'school_proposes' => 'encrypted',
            'parent_can'      => 'encrypted',
            'sent_at'         => 'datetime',
            'read_at'         => 'datetime',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
