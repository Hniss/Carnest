<?php
namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'email_verified_at', 'deactivated_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** D2 / D9 (v3) — rôles techniques des comptes adultes. */
    public const ROLES = ['admin', 'referent', 'parent'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'deactivated_at'    => 'datetime',
        ];
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    // ── Rôles ─────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isReferent(): bool
    {
        return $this->role === 'referent';
    }

    public function isParent(): bool
    {
        return $this->role === 'parent';
    }

    /** Page d'accueil après connexion, selon le rôle (lot 1 §2). */
    public function homePath(): string
    {
        return match ($this->role) {
            'referent' => '/dashboard-referent',
            'parent'   => '/parent',
            default    => '/dashboard',
        };
    }

    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            'admin'    => 'Administration',
            'referent' => 'Référent',
            'parent'   => 'Parent',
            default    => 'Inconnu',
        };
    }

    // ── Relations ─────────────────────────────────────────────────────────

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_user')
                    ->withPivot('role');
    }

    /** Première école du compte (pivot school_user). Un parent n'en a pas. */
    public function school(): ?School
    {
        return $this->schools()->first();
    }

    /** Enfants rattachés (parents uniquement, via parent_child). */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'parent_child', 'parent_id', 'child_id')
                    ->using(ParentChild::class)
                    ->withPivot(['relation', 'consent_given', 'consent_text', 'consent_timestamp', 'consent_ip', 'consent_withdrawn_at'])
                    ->withTimestamps();
    }

    /** Enfants dont le consentement est actif (donné et non retiré). */
    public function consentedChildren(): BelongsToMany
    {
        return $this->children()
            ->wherePivot('consent_given', true)
            ->wherePivotNull('consent_withdrawn_at');
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(AdminNote::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class)->latest();
    }

    public function unreadNotificationsCount(): int
    {
        return $this->appNotifications()->whereNull('read_at')->count();
    }

    /** Délégations reçues (en tant que délégué). */
    public function delegationsReceived(): HasMany
    {
        return $this->hasMany(ReferentDelegation::class, 'delegate_id');
    }

    // ── Délégation temporaire ─────────────────────────────────────────────

    /**
     * Délégation active aujourd'hui pour cette école (l'utilisateur est le
     * délégué désigné, la délégation est activée, non révoquée, dans ses dates).
     */
    public function delegationFor(School $school): ?ReferentDelegation
    {
        return ReferentDelegation::query()
            ->active()
            ->where('school_id', $school->id)
            ->where('delegate_id', $this->id)
            ->latest('activated_at')
            ->first();
    }

    /** Première école où l'utilisateur est le référent titulaire (school_user.role = referent). */
    public function referentSchool(): ?School
    {
        return $this->schools()->wherePivot('role', 'referent')->first();
    }

    /** Écoles où l'utilisateur est délégué actif aujourd'hui. */
    public function delegatedSchools()
    {
        $ids = ReferentDelegation::query()->active()->where('delegate_id', $this->id)->pluck('school_id');

        return School::whereIn('id', $ids)->get();
    }
}
