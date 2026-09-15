<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferentDelegation extends Model
{
    protected $fillable = ['school_id', 'referent_id', 'delegate_id', 'start_date', 'end_date', 'activated_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'start_date'   => 'date',
            'end_date'     => 'date',
            'activated_at' => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    /** Délégation active aujourd'hui : activée, non révoquée, dans la fenêtre de dates. */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNotNull('activated_at')
            ->whereNull('revoked_at')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }

    public function isActive(): bool
    {
        $today = now()->startOfDay();

        return $this->activated_at !== null
            && $this->revoked_at === null
            && $this->start_date->lte($today)
            && $this->end_date->gte($today);
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->revoked_at !== null                 => 'Révoquée',
            $this->activated_at === null               => 'En attente d\'activation',
            $this->end_date->lt(now()->startOfDay())   => 'Expirée',
            $this->start_date->gt(now()->startOfDay()) => 'Programmée',
            default                                    => 'Active',
        };
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function referent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referent_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
