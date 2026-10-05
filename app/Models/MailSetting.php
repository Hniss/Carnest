<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Boîte d'envoi des e-mails (une seule ligne) ; mot de passe chiffré au repos, jamais sérialisé. */
class MailSetting extends Model
{
    protected $fillable = ['host', 'port', 'encryption', 'username', 'password', 'password_last4', 'from_address', 'from_name', 'updated_by'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer'];
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
