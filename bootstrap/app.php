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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
