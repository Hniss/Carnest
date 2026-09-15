<?php
namespace App\Models;

use App\Observers\ChildObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

#[ObservedBy(ChildObserver::class)]
class Child extends Authenticatable
{
    use HasFactory, Notifiable;

    /** D3 (v3) — tranches d'âge cibles 5-18. */
    public const AGE_GROUPS = ['5-7', '8-11', '12-18'];

    protected $fillable = [
        'school_id', 'name', 'email', 'password',
        'age', 'birth_date', 'age_group', 'classe', 'gender',
        'score_enfant', 'status', 'last_session_at', 'deactivated_at',
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
            'birth_date'      => 'date',
            'last_session_at' => 'datetime',
            'deactivated_at'  => 'datetime',
        ];
    }

    /**
     * D3 (v3) — Âge calculé depuis birth_date quand elle existe ; sinon la
     * colonne `age` (compatibilité avec les enfants créés sans date de naissance).
     */
    protected function age(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes) {
                if (! empty($attributes['birth_date'])) {
                    return Carbon::parse($attributes['birth_date'])->age;
                }
                return $value !== null ? (int) $value : null;
            },
        );
    }

    /** Tranche d'âge v3 pour un âge donné (5-7 / 8-11 / 12-18). */
    public static function ageGroupFor(int $age): string
    {
        return match (true) {
            $age <= 7  => '5-7',
            $age <= 11 => '8-11',
            default    => '12-18',
        };
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
