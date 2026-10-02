<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Pas d'inscription publique (correction du 2026-10-02) : un visiteur qui s'inscrivait
// devenait administrateur. Les comptes adultes naissent de l'écran Comptes de
// l'administration (référent, administration) et du rattachement d'un parent à un élève.
Route::middleware('guest')->group(function () {
    // D10 (v3) — limitation de débit sur la page de connexion admin (10 req / min / IP).
    Volt::route('login', 'pages.auth.login')
        ->middleware('throttle:10,1,login-admin')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
