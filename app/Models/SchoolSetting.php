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
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'alert_threshold'     => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
