<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    public const STATUSES = ['aucun', 'surveillance', 'accompagnement', 'intervention_urgente', 'termine'];

    protected $fillable = ['child_id', 'alert_id', 'status', 'next_review_date', 'objectif', 'responsable_id'];

    /** Objectif chiffré au repos. */
    protected function casts(): array
    {
        return [
            'next_review_date' => 'date',
            'objectif'         => 'encrypted',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'aucun'                => 'Aucun suivi',
            'surveillance'         => 'Surveillance',
            'accompagnement'       => 'Accompagnement',
            'intervention_urgente' => 'Intervention urgente',
            'termine'              => 'Terminé',
            default                => 'Aucun suivi',
        };
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
