<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lot 2 — battement écrit à chaque exécution de `carenest:escalate-alerts` (contrôle externe /up/pager). */
class PagerHeartbeat extends Model
{
    public $timestamps = false;

    protected $fillable = ['worker', 'beat_at'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime'];
    }
}
