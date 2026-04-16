---
name: livewire-components
description: Conventions Livewire 3 pour CareNest — structure, événements, wire:model, Alpine.js. À lire avant tout composant Livewire.
---

# Livewire 3 — CareNest

## Création

```bash
php artisan make:livewire Chat/Messages
php artisan make:livewire Admin/AlertsTable
```

Génère :
- `app/Livewire/Chat/Messages.php`
- `resources/views/livewire/chat/messages.blade.php`

## Structure type d'un composant

```php
<?php
// app/Livewire/Chat/Messages.php
namespace App\Livewire\Chat;

use App\Services\ChatService;
use Livewire\Component;
use Livewire\Attributes\Computed;

class Messages extends Component
{
    public string $input = '';
    public bool $typing = false;
    public array $messages = [];

    public function mount(): void
    {
        $this->messages = [[
            'role' => 'assistant',
            'content' => 'Salut ! Comment tu vas ?',
        ]];
    }

    public function send(ChatService $chat): void
    {
        if (trim($this->input) === '') return;

        $this->messages[] = ['role' => 'user', 'content' => $this->input];
        $userText = $this->input;
        $this->input = '';
        $this->typing = true;

        $response = $chat->sendMessage(auth()->user()->child, $userText);

        $this->messages[] = ['role' => 'assistant', 'content' => $response['text']];
        $this->typing = false;
    }

    public function render()
    {
        return view('livewire.chat.messages');
    }
}
```

## Vue Blade

```blade
{{-- resources/views/livewire/chat/messages.blade.php --}}
<div class="flex flex-col min-h-screen bg-[#F7FAFA]"
     x-data
     x-init="$watch('$wire.messages', () => $nextTick(() => $refs.bottom.scrollIntoView({behavior:'smooth'})))">

    <div class="flex-1 overflow-y-auto px-5 pt-6 pb-24 max-w-3xl w-full mx-auto">
        @foreach($messages as $m)
            <div class="flex mb-4 gap-2.5 {{ $m['role'] === 'user' ? 'flex-row-reverse' : '' }}">
                @if($m['role'] === 'assistant')
                    <div class="w-9 h-9 rounded-full bg-teal flex items-center justify-center text-white">🌿</div>
                @endif

                <div class="max-w-[70%] px-4 py-3 rounded-2xl font-['Nunito'] leading-relaxed
                    {{ $m['role'] === 'user'
                        ? 'bg-teal text-white rounded-br-sm'
                        : 'bg-teal-light text-[#1A2E2A] rounded-bl-sm' }}">
                    {{ $m['content'] }}
                </div>
            </div>
        @endforeach

        @if($typing)
            <div class="flex mb-4 gap-2.5">
                <div class="w-9 h-9 rounded-full bg-teal flex items-center justify-center text-white">🌿</div>
                <div class="bg-teal-light px-4 py-3.5 rounded-2xl rounded-bl-sm flex gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-teal animate-bounce"></span>
                    <span class="w-2 h-2 rounded-full bg-teal animate-bounce [animation-delay:.2s]"></span>
                    <span class="w-2 h-2 rounded-full bg-teal animate-bounce [animation-delay:.4s]"></span>
                </div>
            </div>
        @endif
        <div x-ref="bottom"></div>
    </div>

    <form wire:submit="send"
          class="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-3xl bg-white border-t border-[#E2EDE9] px-5 py-4 flex gap-2.5">
        <input type="text"
               wire:model="input"
               placeholder="Écris ce que tu ressens..."
               class="flex-1 px-4 py-3 border-2 border-[#E2EDE9] rounded-full focus:border-teal outline-none font-['Nunito']"
               @disabled($typing)>
        <button type="submit"
                class="w-11 h-11 rounded-full bg-teal text-white flex items-center justify-center hover:bg-[#15674f] disabled:opacity-50"
                @disabled($typing)>
            ✈
        </button>
    </form>
</div>
```

## Règles

### 1. Pas de logique métier dans les composants
Déléguer aux services.

### 2. Alpine pour les micro-interactions client
Auto-scroll, toggle, dropdown → Alpine.js. Pas besoin de faire un round-trip serveur.

### 3. `wire:model.live` avec parcimonie
Pour un champ de recherche, utiliser `wire:model.live.debounce.300ms`. Sinon, c'est du gaspillage.

### 4. `@disabled($typing)` pour éviter double-clic
Toujours désactiver le bouton d'envoi pendant le loading.

### 5. Events Livewire pour la communication
```php
$this->dispatch('alert-resolved', alertId: $alert->id);
```
Écouté par d'autres composants :
```php
#[On('alert-resolved')]
public function refreshList() { ... }
```

### 6. Polling pour les alertes temps réel (MVP)
```blade
<div wire:poll.10s="refreshAlerts">
```
**Post-MVP** : passer à Livewire Echo + Reverb pour du vrai temps réel.

### 7. Validation côté composant
```php
protected $rules = [
    'input' => 'required|string|max:2000',
];

public function send(): void
{
    $this->validate();
    // ...
}
```

## Tests Livewire

```php
use Livewire\Livewire;

it('affiche le message de bienvenue adapté à l\'âge', function () {
    $child = Child::factory()->create(['age' => 6]);

    Livewire::actingAs($child->user)
        ->test(\App\Livewire\Chat\Messages::class)
        ->assertSee('Salut !')
        ->assertSee('😊');
});
```
