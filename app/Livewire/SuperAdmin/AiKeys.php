<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\AiCredential;
use App\Services\AiKeys as Keys;
use App\Services\Audit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Clés d'IA (Gemini, GPT, Claude). Une clé saisie n'est JAMAIS renvoyée au navigateur :
 * le champ est vidé dès l'enregistrement, l'écran ne montre que ses 4 derniers caractères.
 * Le journal note le fournisseur, l'auteur et l'heure, jamais la valeur.
 */
#[Layout('layouts.superadmin')]
class AiKeys extends Component
{
    use RequiresSuperAdmin;

    /** @var array<string, string> */
    public array $inputs = ['gemini' => '', 'openai' => '', 'anthropic' => ''];

    public ?string $flash = null;

    public function mount(): void
    {
        Audit::log('superadmin.view.ai_keys');
    }

    private function provider(string $provider): string
    {
        abort_unless(in_array($provider, Keys::PROVIDERS, true), 404);

        return $provider;
    }

    public function save(string $provider): void
    {
        $provider = $this->provider($provider);
        $this->inputs[$provider] = trim((string) ($this->inputs[$provider] ?? ''));

        $this->validate(
            ["inputs.$provider" => ['required', 'string', 'min:10', 'max:500', 'regex:/^\S+$/']],
            [
                "inputs.$provider.required" => 'Collez la clé avant d\'enregistrer.',
                "inputs.$provider.min"      => 'Cette clé paraît incomplète.',
                "inputs.$provider.regex"    => 'Une clé ne contient pas d\'espace.',
            ],
        );

        Keys::store($provider, $this->inputs[$provider], Auth::id());
        $this->inputs[$provider] = '';
        Audit::log("superadmin.ai_key.set.$provider", AiCredential::where('provider', $provider)->first());

        $this->flash = 'Clé ' . Keys::LABELS[$provider] . ' enregistrée.';
    }

    public function remove(string $provider): void
    {
        $provider = $this->provider($provider);
        $this->inputs[$provider] = '';

        $credential = AiCredential::where('provider', $provider)->first();
        if ($credential === null) {
            return;
        }
        Audit::log("superadmin.ai_key.remove.$provider", $credential);
        Keys::remove($provider, Auth::id());

        $this->flash = 'Clé ' . Keys::LABELS[$provider] . ' retirée de la base.';
    }

    public function render()
    {
        $credentials = AiCredential::with('updatedBy:id,name')->get()->keyBy('provider');
        $primary     = Keys::primary();

        $rows = collect(Keys::PROVIDERS)->map(fn (string $p) => [
            'provider'   => $p,
            'label'      => Keys::LABELS[$p],
            'source'     => Keys::source($p),
            'credential' => $credentials->get($p),
            'used'       => Keys::has($p) && $p === $primary,
        ]);

        return view('livewire.superadmin.ai-keys', [
            'rows'       => $rows,
            'keyCount'   => collect(Keys::PROVIDERS)->filter(fn ($p) => Keys::has($p))->count(),
        ])->title("Clés d'IA");
    }
}
