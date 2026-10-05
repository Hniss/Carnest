<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Adresse qui reçoit les e-mails d'alerte d'une école (les mêmes que le référent). */
class SchoolAlertRecipient extends Model
{
    protected $fillable = ['school_id', 'email', 'created_by'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
