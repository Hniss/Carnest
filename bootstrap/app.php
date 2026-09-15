<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // #1 (V5) — Le beacon de clôture de session est envoyé par
        // navigator.sendBeacon() qui ne peut pas joindre d'en-tête CSRF.
        // La route reste protégée par le guard 'child' + contrôle d'appartenance.
        $middleware->validateCsrfTokens(except: [
            'chat/close',
        ]);

        // Lot 1 (MVP v3) — cloisonnement par rôle : role:admin / role:referent / role:parent.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        // Lot 1 — un utilisateur déjà connecté qui ouvre /login est renvoyé vers SON espace (pas /dashboard).
        $middleware->redirectUsersTo(fn () => auth()->user()?->homePath() ?? '/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
