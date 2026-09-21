<?php
namespace App\Models;

use App\Observers\ChildObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'score_enfant', 'status', 'last_session_at', 'deactivated_at', 'high_usage_notified_on',
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
            // Marqueur interne CareNest (dépassement du plafond du jour) — aucune notification.
            'high_usage_notified_on' => 'date',
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

    // ── Lot 1 (MVP v3) ─────────────────────────────────────────────────

    /** Parents rattachés via parent_child (avec consentement). */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'parent_child', 'child_id', 'parent_id')
                    ->using(ParentChild::class)
                    ->withPivot(['relation', 'consent_given', 'consent_text', 'consent_timestamp', 'consent_ip', 'consent_withdrawn_at'])
                    ->withTimestamps();
    }

    /** Parents dont le consentement est actif (donné et non retiré). */
    public function consentingParents(): BelongsToMany
    {
        return $this->parents()
            ->wherePivot('consent_given', true)
            ->wherePivotNull('consent_withdrawn_at');
    }

    public function hasActiveConsent(): bool
    {
        return $this->consentingParents()->exists();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function latestFollowUp(): HasOne
    {
        return $this->hasOne(FollowUp::class)->latestOfMany();
    }

    public function parentThreads(): HasMany
    {
        return $this->hasMany(ParentThread::class);
    }

    public function syntheses(): HasMany
    {
        return $this->hasMany(ParentSynthesis::class);
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }
}
