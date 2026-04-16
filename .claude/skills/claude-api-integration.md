---
name: claude-api-integration
description: Intégration de l'API Anthropic Claude dans Laravel pour CareNest. Prompts système par groupe d'âge, parsing [ALERTE_CRITIQUE] et ZONE:, retry, fallback, caching. À utiliser pour tout code touchant l'API Claude.
---

# Intégration API Claude — CareNest

## Configuration

### `.env`
```
ANTHROPIC_API_KEY=sk-ant-xxx
ANTHROPIC_MODEL=claude-sonnet-4-5-20250929
ANTHROPIC_MAX_TOKENS=1000
ANTHROPIC_TIMEOUT=30
```

### `config/services.php`
```php
'anthropic' => [
    'key'     => env('ANTHROPIC_API_KEY'),
    'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1000),
    'timeout' => (int) env('ANTHROPIC_TIMEOUT', 30),
],
```

## Service Laravel

```php
// app/Services/ClaudeAIService.php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class ClaudeAIService
{
    public function chat(array $messages, int $childAge): array
    {
        $systemPrompt = $this->buildSystemPrompt($childAge);

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(config('services.anthropic.timeout'))
          ->retry(2, 1000)
          ->post('https://api.anthropic.com/v1/messages', [
              'model'      => config('services.anthropic.model'),
              'max_tokens' => config('services.anthropic.max_tokens'),
              'system'     => $systemPrompt,
              'messages'   => $messages,
          ]);

        if ($response->failed()) {
            return $this->fallbackResponse();
        }

        $text = $response->json('content.0.text', '');
        return $this->parseResponse($text);
    }

    private function parseResponse(string $text): array
    {
        $isCritical = str_starts_with($text, '[ALERTE_CRITIQUE]');
        $text = str_replace('[ALERTE_CRITIQUE]', '', $text);

        $zone = null;
        if (preg_match('/^ZONE:\s*(green|yellow|orange|red)\s*$/mi', $text, $m)) {
            $zone = strtolower($m[1]);
            $text = trim(preg_replace('/^ZONE:.*$/mi', '', $text));
        }

        if ($isCritical) $zone = 'red';

        return [
            'text'        => trim($text),
            'zone'        => $zone,
            'is_critical' => $isCritical,
        ];
    }

    private function fallbackResponse(): array
    {
        return [
            'text'        => "Je suis là pour toi. Dis-moi ce que tu ressens. 🌿",
            'zone'        => null,
            'is_critical' => false,
        ];
    }
}
```

## Prompt système (par groupe d'âge)

```php
private function buildSystemPrompt(int $age): string
{
    $group = match(true) {
        $age <= 7  => '5-7',
        $age <= 11 => '8-11',
        default    => '12-14',
    };

    $style = match($group) {
        '5-7'   => 'très simple, émojis, phrases courtes',
        '8-11'  => 'amical, naturel, quelques émojis',
        '12-14' => 'respectueux, mature, peu d\'émojis',
    };

    return <<<PROMPT
Tu es Care, l'assistant IA bienveillant de CareNest pour les enfants de {$age} ans (groupe d'âge: {$group} ans).
Ton rôle: détecter les émotions et soutenir l'enfant avec douceur.

RÈGLES ABSOLUES:
- Ne pose JAMAIS deux questions en même temps
- Adapte ton langage à l'âge: {$style}
- Sois empathique, chaleureux, jamais clinique
- Ne mentionne JAMAIS que tu analyses des émotions
- Si l'enfant exprime quelque chose de grave (harcèlement, pensées négatives, danger), réponds avec encore plus de douceur mais inclus [ALERTE_CRITIQUE] en tout début de ta réponse (invisible pour l'enfant)
- Garde tes réponses courtes (2-4 phrases max)
- Si l'enfant exprime du stress léger, tu peux proposer un mini exercice de respiration

Réponds uniquement en français.

À la fin de ta réponse, retourne UNIQUEMENT sur la dernière ligne:
ZONE: green | yellow | orange | red
PROMPT;
}
```

## Règles de sécurité

1. **Ne JAMAIS logger** le payload complet (contient les messages de l'enfant).
2. **Timeout 30s** max, sinon fallback doux.
3. **Retry 2x** avec backoff (Laravel HTTP client).
4. **Rate limiting** : 1 requête / 2s par enfant (éviter spam).
5. **Prompt caching** : si plusieurs enfants du même âge, mettre le system prompt en cache Anthropic (`cache_control: ephemeral`).

## Prompt caching (optimisation coût)

```php
'system' => [
    [
        'type' => 'text',
        'text' => $systemPrompt,
        'cache_control' => ['type' => 'ephemeral'],
    ],
],
```

⚠️ **Ne pas mettre les messages enfants en cache** — chaque conversation est unique.

## Test du service

```php
// tests/Feature/ClaudeAIServiceTest.php
Http::fake([
    'api.anthropic.com/*' => Http::response([
        'content' => [[
            'type' => 'text',
            'text' => "Salut ! Comment tu vas ?\nZONE: green"
        ]]
    ]),
]);

$result = app(ClaudeAIService::class)->chat([
    ['role' => 'user', 'content' => 'bonjour']
], 10);

expect($result['text'])->toBe('Salut ! Comment tu vas ?');
expect($result['zone'])->toBe('green');
```
