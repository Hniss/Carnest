<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLifecycle extends Model
{
    protected $table = 'alert_lifecycle';

    public const STATUSES = ['nouveau', 'qualifie', 'en_traitement', 'suivi', 'cloture'];
    public const QUALIFICATIONS = ['pertinent', 'faux_positif', 'a_surveiller', 'confirme', 'urgent'];

    protected $fillable = ['alert_id', 'status', 'qualification', 'changed_by', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'nouveau'       => 'Nouveau',
            'qualifie'      => 'Qualifié',
            'en_traitement' => 'En traitement',
            'suivi'         => 'Suivi',
            'cloture'       => 'Clôturé',
            default         => 'Inconnu',
        };
    }

    public static function qualificationLabel(?string $q): string
    {
        return match ($q) {
            'pertinent'    => 'Pertinent',
            'faux_positif' => 'Faux positif',
            'a_surveiller' => 'À surveiller',
            'confirme'     => 'Confirmée',
            'urgent'       => 'Urgente',
            default        => 'Non qualifiée',
        };
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
