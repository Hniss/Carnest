<?php
namespace App\Models;

use App\Observers\ChildObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[ObservedBy(ChildObserver::class)]
class Child extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'school_id', 'name', 'email', 'password',
        'age', 'age_group', 'classe', 'gender',
        'score_enfant', 'status', 'last_session_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'status' => 'ok',
    ];

    protected function casts(): array
    {
        return [
            'password'        => 'hashed',
            'score_enfant'    => 'float',
            'last_session_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(AdminNote::class);
    }
}
