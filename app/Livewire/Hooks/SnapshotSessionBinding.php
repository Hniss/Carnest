<?php

namespace App\Livewire\Hooks;

use App\Exceptions\SnapshotRejectedException;
use Livewire\ComponentHook;
use Livewire\Mechanisms\HandleComponents\ComponentContext;

/**
 * E-001 (test d'intrusion Nergal du 2026-10-22) — lie chaque instantané Livewire à la session
 * qui l'a produit et au temps.
 *
 * À la déshydratation, le memo reçoit l'empreinte HMAC de l'identifiant de session (jamais
 * l'identifiant lui-même : le memo part dans le navigateur) et l'heure d'émission. Le memo entre
 * dans la somme de contrôle de Livewire (HMAC avec APP_KEY) : il ne peut pas être réécrit côté
 * client. À l'hydratation d'une requête suivante, un instantané émis par une autre session, ou
 * plus vieux que la durée de vie, est refusé en 419 avant tout traitement du composant.
 *
 * La durée de vie est renouvelée à chaque échange accepté : une page utilisée ne expire pas,
 * une page laissée sans interaction se recharge au clic suivant.
 */
class SnapshotSessionBinding extends ComponentHook
{
    /** Durée de vie maximale d'un instantané (minutes), bornée par la durée de vie de la session. */
    public const MAX_AGE_MINUTES = 30;

    public static function fingerprint(): string
    {
        // Préfixe de séparation de domaine : cette HMAC ne peut être confondue avec aucune autre
        // HMAC calculée avec APP_KEY (dont la somme de contrôle Livewire).
        return hash_hmac('sha256', 'livewire-sid|'.session()->getId(), app('encrypter')->getKey());
    }

    public static function maxAgeSeconds(): int
    {
        return 60 * min(self::MAX_AGE_MINUTES, max(1, (int) config('session.lifetime', self::MAX_AGE_MINUTES)));
    }

    public function dehydrate(ComponentContext $context): void
    {
        $context->addMemo('sid', self::fingerprint());
        $context->addMemo('iat', now()->getTimestamp());
    }

    public function hydrate($memo): void
    {
        $sid = $memo['sid'] ?? null;
        $iat = $memo['iat'] ?? null;

        if (! is_string($sid) || ! is_int($iat)) {
            throw new SnapshotRejectedException('memo-absent');
        }

        if (! hash_equals(self::fingerprint(), $sid)) {
            throw new SnapshotRejectedException('session-differente');
        }

        if (now()->getTimestamp() - $iat > self::maxAgeSeconds()) {
            throw new SnapshotRejectedException('expire');
        }
    }
}
