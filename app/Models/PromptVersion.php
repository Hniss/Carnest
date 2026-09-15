<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lot 2 §6 — registre des versions du prompt système (version, empreinte SHA-256, modèle cible). */
class PromptVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['version', 'hash', 'target_model', 'notes', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
