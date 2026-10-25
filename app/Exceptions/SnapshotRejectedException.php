<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * E-001 — instantané Livewire refusé : il vient d'une autre session ou a dépassé sa durée de vie.
 * Statut 419 (le même que « page expirée ») : le navigateur recharge le composant, aucune 500.
 */
class SnapshotRejectedException extends HttpException
{
    public const MESSAGE = 'Cette page a expiré ou ne correspond pas à votre session. Elle va se recharger.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(419, self::MESSAGE);
    }
}
