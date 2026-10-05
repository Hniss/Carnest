<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Clé d'IA saisie par le super-admin : chiffrée au repos (APP_KEY), jamais sérialisée. */
class AiCredential extends Model
{
    protected $fillable = ['provider', 'api_key', 'last4', 'updated_by'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
