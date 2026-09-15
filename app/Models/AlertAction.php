<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertAction extends Model
{
    public const TYPES = ['entretien', 'contact_parent', 'surveillance', 'orientation_professionnel', 'autre'];

    protected $fillable = ['alert_id', 'action_type', 'performed_by', 'performed_at', 'notes'];

    /** Notes chiffrées au repos ; jamais filtrées en SQL. */
    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'notes'        => 'encrypted',
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'entretien'                 => 'Entretien avec l\'élève',
            'contact_parent'            => 'Contact du parent',
            'surveillance'              => 'Surveillance renforcée',
            'orientation_professionnel' => 'Orientation vers un professionnel',
            'autre'                     => 'Autre action',
            default                     => 'Action',
        };
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
