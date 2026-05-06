<?php
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Settings as AdminSettings;
use App\Livewire\Child\ChatInterface;
use App\Livewire\Child\Login as ChildLogin;
use Illuminate\Support\Facades\Route;

// Page d'accueil — redirige selon le rôle connecté
Route::get('/', function () {
    if (auth('child')->check()) return redirect()->route('child.chat');
    if (auth()->check()) return redirect()->route('admin.dashboard');
    return view('welcome');
});

// ── Admin (guard web / Breeze) ──────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/settings', AdminSettings::class)->name('admin.settings');
    Route::permanentRedirect('/admin/dashboard', '/dashboard')->name('admin.dashboard');
});

Route::post('/logout', function () {
    auth()->guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/login');
})->middleware('auth')->name('logout');

// ── Child auth ──────────────────────────────────────────
Route::get('/child/login', ChildLogin::class)->name('child.login');
Route::post('/child/logout', function () {
    auth('child')->logout();
    return redirect()->route('child.login');
})->name('child.logout');

// ── Child chat (guard child) ────────────────────────────
Route::middleware('auth:child')->group(function () {
    Route::get('/chat', ChatInterface::class)->name('child.chat');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
