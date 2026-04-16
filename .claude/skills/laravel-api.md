---
name: laravel-api
description: Conventions Laravel 11 pour CareNest — structure, routes, controllers, services, FormRequests, Resources, tests. À lire avant tout code backend.
---

# Conventions Laravel — CareNest

## Structure imposée

```
app/
├── Enums/              # Zone, AlertLevel, UserRole
├── Models/             # Eloquent models
├── Services/           # Logique métier (jamais dans controllers)
├── Jobs/               # Traitements async
├── Events/             # Ex: SessionClosed, CriticalAlertDetected
├── Listeners/
├── Http/
│   ├── Livewire/       # Composants Livewire
│   ├── Controllers/    # Minimal, délègue aux services
│   ├── Requests/       # FormRequests obligatoires
│   ├── Resources/      # Pour toute sérialisation JSON
│   └── Middleware/
└── Policies/           # Autorisation
```

## Règle d'or : Controllers = aiguillage, pas logique

❌ **Mauvais** :
```php
public function store(Request $request)
{
    $validated = $request->validate([...]);
    $session = Session::create([...]);
    $response = Http::post('https://api.anthropic.com/...');
    // 50 lignes de logique métier
}
```

✅ **Bon** :
```php
public function store(StoreSessionRequest $request, ChatService $chat)
{
    $result = $chat->sendMessage(
        child: $request->user()->child,
        message: $request->validated('message'),
    );
    return SessionResource::make($result);
}
```

## FormRequests systématiques

```php
// app/Http/Requests/StoreSessionMessageRequest.php
class StoreSessionMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
```

## Resources pour sérialisation

```php
// app/Http/Resources/AlertResource.php
class AlertResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->id,
            'child'     => $this->child->first_name,
            'zone'      => $this->session->zone->value,
            'level'     => $this->level->value,
            'summary'   => $this->summary,   // déjà déchiffré par cast
            'type'      => $this->type,
            'status'    => $this->status,
            'created_at'=> $this->created_at,
        ];
    }
}
```

## Services : un fichier = une responsabilité

```php
// app/Services/ChatService.php
class ChatService
{
    public function __construct(
        private ClaudeAIService $ai,
        private ZoneClassifier $classifier,
    ) {}

    public function sendMessage(Child $child, string $message): array
    {
        // ... logique ici, jamais dans le controller
    }
}
```

## Jobs asynchrones

À utiliser pour :
- ✅ Calcul score climat après fin de session
- ✅ Envoi email / push sur alerte rouge
- ✅ Tout traitement > 500ms

```php
// app/Jobs/ProcessSessionClosure.php
class ProcessSessionClosure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Session $session) {}

    public function handle(ClimateScoreCalculator $calc): void
    {
        // ...
    }
}
```

## Tests minimums par feature

```php
// tests/Feature/ChildChatTest.php
it('enfant peut envoyer un message et recevoir une réponse IA', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => "Salut !\nZONE: green"]]
        ]),
    ]);

    $child = Child::factory()->create(['age' => 10]);

    $this->actingAs($child->user)
         ->post('/chat', ['message' => 'bonjour'])
         ->assertOk()
         ->assertJsonPath('data.text', 'Salut !');

    expect($child->sessions()->latest()->first()->zone->value)->toBe('green');
});
```

## Routing

```php
// routes/web.php
Route::middleware('auth')->group(function () {
    Route::get('/chat', Chat\Messages::class)->name('chat');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/', Admin\Dashboard::class)->name('admin.dashboard');
    Route::get('/alerts', Admin\AlertsTable::class)->name('admin.alerts');
    Route::get('/settings', Admin\Settings::class)->name('admin.settings');
});
```

## Migrations

- **Toujours** `->cascadeOnDelete()` sur FK vers `children`
- **Toujours** `->index()` sur les colonnes filtrées (ex: `status`, `zone`, `child_id`)
- **Toujours** timestamps (`$table->timestamps()`)

## Seeders pour démo

```php
// database/seeders/DemoSeeder.php
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::factory()->create(['name' => 'École Agdal']);
        User::factory()->admin()->for($school)->create([
            'email' => 'admin@carenest.ma',
        ]);
        Child::factory(5)->for($school)->create();
    }
}
```
