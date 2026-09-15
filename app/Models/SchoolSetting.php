<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolSetting extends Model
{
    protected $fillable = [
        'school_id',
        'alert_threshold',
        'email_notifications',
        'language',
        'school_hours_start',
        'school_hours_end',
        'daily_token_cap',
        'session_max_minutes',
        'referent_phone',
        'admin_phone',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'alert_threshold'     => 'integer',
            'daily_token_cap'     => 'integer',
            'session_max_minutes' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
