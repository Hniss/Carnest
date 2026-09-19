<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ParentChild;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Lot 1 §6 — création (ou rattachement) du compte parent à la création d'un élève :
 * users.role = parent, mot de passe aléatoire, lien de réinitialisation Laravel
 * (notification standard ResetPassword), ligne parent_child avec consentement
 * horodaté + IP, texte au nom de l'école. Sans consentement, l'enfant est créé désactivé.
 *
 * Spec §5.1 — « Aucun compte parent actif sans ce consentement — bloquant, non
 * optionnel. » : le compte parent lui-même est désactivé (`users.deactivated_at`)
 * tant qu'aucun enfant à consentement actif ne lui est rattaché, et réactivé au
 * moment où le consentement est donné (`syncActivation()`). La désactivation est
 * bloquante à la connexion (`LoginForm` filtre sur `deactivated_at = null`).
 */
class ParentAccountProvisioner
{
    /** Trouve ou crée le compte parent ; envoie le lien de réinitialisation à la création. */
    public function findOrCreateParent(string $email, ?string $name = null): User
    {
        $email = Str::lower(trim($email));
        $parent = User::where('email', $email)->first();

        if ($parent) {
            return $parent;
        }

        // Spec §5.1 — créé désactivé : aucun enfant consenti ne lui est encore rattaché.
        $parent = User::create([
            'name'              => $name ?: Str::before($email, '@'),
            'email'             => $email,
            'password'          => Hash::make(Str::password(24)),
            'role'              => 'parent',
            'email_verified_at' => now(),
            'deactivated_at'    => now(),
        ]);

        Password::broker()->sendResetLink(['email' => $email]);

        return $parent;
    }

    /** Rattache le parent à l'enfant avec l'état de consentement fourni. */
    public function link(User $parent, Child $child, string $relation, bool $consent, School $school, ?string $ip): ParentChild
    {
        $attrs = [
            'relation'          => in_array($relation, ParentChild::RELATIONS, true) ? $relation : 'tuteur',
            'consent_given'     => $consent,
            'consent_text'      => $consent ? ParentChild::consentText($school->name) : null,
            'consent_timestamp' => $consent ? now() : null,
            'consent_ip'        => $consent ? $ip : null,
            'consent_withdrawn_at' => null,
        ];

        $parent->children()->syncWithoutDetaching([$child->id => $attrs]);

        if (! $consent && ! $child->hasActiveConsent()) {
            $child->forceFill(['deactivated_at' => $child->deactivated_at ?? now()])->save();
        }

        $this->syncActivation($parent);

        return $parent->children()->where('children.id', $child->id)->first()->pivot;
    }

    /**
     * Spec §5.1 — aligne l'état du compte parent sur le consentement : désactivé tant
     * qu'aucun enfant à consentement actif ne lui est rattaché, réactivé dès qu'il y en
     * a un. Appelé à la création / au rattachement et au retrait de consentement.
     */
    public function syncActivation(User $parent): void
    {
        $hasConsent = $parent->consentedChildren()->exists();

        if (! $hasConsent && $parent->deactivated_at === null) {
            $parent->forceFill(['deactivated_at' => now()])->save();

            return;
        }

        if ($hasConsent && $parent->deactivated_at !== null) {
            $parent->forceFill(['deactivated_at' => null])->save();
        }
    }
}
