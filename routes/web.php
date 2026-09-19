<?php
use App\Http\Controllers\Child\SessionCloseController;
use App\Livewire\Admin\AccessLog as AdminAccessLog;
use App\Livewire\Admin\Accounts as AdminAccounts;
use App\Livewire\Admin\ChildProfile;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Settings as AdminSettings;
use App\Livewire\Admin\Students as AdminStudents;
use App\Livewire\Child\ChatInterface;
use App\Livewire\Child\Login as ChildLogin;
use App\Livewire\ParentSpace\Consent as ParentConsent;
use App\Livewire\ParentSpace\Home as ParentHome;
use App\Livewire\ParentSpace\Journal as ParentJournal;
use App\Livewire\ParentSpace\Messages as ParentMessages;
use App\Livewire\ParentSpace\MyData as ParentMyData;
use App\Livewire\Referent\AlertTreatment;
use App\Livewire\Referent\Delegation;
use App\Livewire\Referent\Messages as ReferentMessages;
use App\Livewire\Referent\Overview;
use App\Livewire\Referent\StudentProfile;
use App\Livewire\Referent\Students as ReferentStudents;
use Illuminate\Support\Facades\Route;

// Page d'accueil — redirige selon le rôle connecté (lot 1 §2)
Route::get('/', function () {
    if (auth('child')->check()) return redirect()->route('child.chat');
    if (auth()->check()) return redirect(auth()->user()->homePath());
    return view('welcome');
});

// ── Administration (guard web / Breeze, rôle admin) ────────────────────
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/settings', AdminSettings::class)->name('admin.settings');
    Route::get('/children/{child}', ChildProfile::class)->name('admin.children.show');
    Route::get('/dashboard/eleves', AdminStudents::class)->name('admin.students');
    Route::get('/dashboard/comptes', AdminAccounts::class)->name('admin.accounts');
    Route::get('/dashboard/journal', AdminAccessLog::class)->name('admin.access-log');
    Route::permanentRedirect('/admin/dashboard', '/dashboard')->name('admin.dashboard');
});

// ── Espace référent (rôle referent ; délégué admin/referent sur les alertes) ──
Route::middleware(['auth', 'role:referent,admin'])->prefix('dashboard-referent')->name('referent.')->group(function () {
    Route::get('/', Overview::class)->name('overview');
    Route::get('/alertes/{alert}', AlertTreatment::class)->name('alerts.show');
});
Route::middleware(['auth', 'role:referent'])->prefix('dashboard-referent')->name('referent.')->group(function () {
    Route::get('/eleves', ReferentStudents::class)->name('students');
    Route::get('/eleves/{child}', StudentProfile::class)->name('students.show');
    Route::get('/messages', ReferentMessages::class)->name('messages');
    Route::get('/delegation', Delegation::class)->name('delegation');
});

// ── Espace parent (rôle parent) ────────────────────────────────────────
Route::middleware(['auth', 'role:parent'])->prefix('parent')->name('parent.')->group(function () {
    Route::get('/', ParentHome::class)->name('home');
    Route::get('/journal', ParentJournal::class)->name('journal');
    Route::get('/messages', ParentMessages::class)->name('messages');
    Route::get('/consentement', ParentConsent::class)->name('consent');
    Route::get('/mes-donnees', ParentMyData::class)->name('my-data');
});

Route::post('/logout', function () {
    auth()->guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/login');
})->middleware('auth')->name('logout');

// ── Child auth ──────────────────────────────────────────
// D10 (v3) — limitation de débit sur la page de connexion enfant (10 req / min / IP).
Route::get('/child/login', ChildLogin::class)->middleware('throttle:10,1,login-child')->name('child.login');
Route::post('/child/logout', function () {
    auth('child')->logout();
    return redirect()->route('child.login');
})->name('child.logout');

// ── Child chat (guard child) ────────────────────────────
Route::middleware('auth:child')->group(function () {
    Route::get('/chat', ChatInterface::class)->name('child.chat');

    // #1 (V5) — Clôture beacon (fermeture/actualisation de fenêtre).
    // Exclue du CSRF (cf. bootstrap/app.php) : sendBeacon ne peut pas envoyer
    // d'en-tête token. Protégée par le guard child + contrôle d'appartenance.
    Route::post('/chat/close', SessionCloseController::class)->name('child.chat.close');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';

