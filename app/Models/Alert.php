<?php
namespace App\Models;

use App\Observers\AlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy(AlertObserver::class)]
class Alert extends Model
{
    protected $fillable = [
        'session_id', 'child_id', 'school_id',
        'type', 'level', 'status', 'notified_at',
        'summary', 'signals', 'prompt_version', 'model', 'adjudication',
        'reopened_from_id',
    ];

    /**
     * D10 (v3) — summary chiffré au repos (cast `encrypted`) ; jamais filtré en SQL.
     */
    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'summary'     => 'encrypted',
            'signals'     => 'array',
        ];
    }

    public static function levelLabel(?string $level): string
    {
        return match ($level) {
            'critical' => 'Critique',
            'high'     => 'Élevée',
            'moderate' => 'Modérée',
            'low'      => 'Faible',
            default    => ucfirst((string) $level),
        };
    }

    public static function adjudicationLabel(?string $adjudication): string
    {
        return match ($adjudication) {
            'confirmee'   => 'Confirmée par la double vérification',
            'infirmee'    => 'Infirmée par la double vérification',
            'a_confirmer' => 'À confirmer',
            default       => 'Sans double vérification',
        };
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(AdminNote::class);
    }

    public function lifecycle(): HasMany
    {
        return $this->hasMany(AlertLifecycle::class)->orderBy('changed_at')->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AlertAction::class)->orderBy('performed_at');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function syntheses(): HasMany
    {
        return $this->hasMany(ParentSynthesis::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }

    public function reopenedFrom(): BelongsTo
    {
        return $this->belongsTo(Alert::class, 'reopened_from_id');
    }

    /** Dernière entrée du cycle de vie (statut de traitement courant). */
    public function latestLifecycle(): HasOne
    {
        return $this->hasOne(AlertLifecycle::class)->latestOfMany('changed_at');
    }

    public function currentStage(): string
    {
        return $this->latestLifecycle?->status ?? 'nouveau';
    }

    public function currentQualification(): ?string
    {
        return $this->lifecycle()->whereNotNull('qualification')->latest('changed_at')->latest('id')->value('qualification');
    }

    public function isQualified(): bool
    {
        return $this->lifecycle()->where('status', '!=', 'nouveau')->exists();
    }

    public function isClosed(): bool
    {
        return $this->currentStage() === 'cloture';
    }
}
