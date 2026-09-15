<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Lien parent ↔ enfant avec consentement parental (au nom de l'école,
 * responsable de traitement — loi 09-08).
 */
class ParentChild extends Pivot
{
    protected $table = 'parent_child';

    public $incrementing = true;

    public const RELATIONS = ['pere', 'mere', 'tuteur'];

    protected $fillable = [
        'parent_id', 'child_id', 'relation', 'consent_given', 'consent_text',
        'consent_timestamp', 'consent_ip', 'consent_withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_given'        => 'boolean',
            'consent_timestamp'    => 'datetime',
            'consent_withdrawn_at' => 'datetime',
        ];
    }

    public static function relationLabel(?string $relation): string
    {
        return match ($relation) {
            'pere'   => 'Père',
            'mere'   => 'Mère',
            'tuteur' => 'Tuteur',
            default  => 'Parent',
        };
    }

    /** Texte du consentement, au nom de l'école responsable de traitement. */
    public static function consentText(string $schoolName): string
    {
        return "J'autorise {$schoolName} à créer un compte CareNest pour mon enfant et à traiter ses données conformément à la loi 09-08.";
    }

    public function hasActiveConsent(): bool
    {
        return $this->consent_given && $this->consent_withdrawn_at === null;
    }
}
