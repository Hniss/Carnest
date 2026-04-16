---
name: zones-of-regulation
description: Logique de classification émotionnelle selon les 4 Zones of Regulation de Leah Kuypers. À utiliser pour tout code qui classe, stocke ou affiche une zone (green/yellow/orange/red).
---

# Zones of Regulation — CareNest

Framework pédagogique créé par **Leah Kuypers** (chercheuse US). Classe l'état émotionnel de l'enfant en 4 couleurs. **Outil pédagogique, pas médical.**

## Les 4 zones

| Zone | Code | Points | Signaux | Action IA |
|---|---|---|---|---|
| 🟢 Vert | `green` | **100** | Calme, heureux, concentré | Aucune intervention |
| 🟡 Jaune | `yellow` | **70** | Stress modéré, fatigue, petite dispute | Rassure + propose respiration |
| 🟠 Orange | `orange` | **35** | Tristesse répétée, isolement | Empathie forte, approfondir doucement |
| 🔴 Rouge | `red` | **0** | Harcèlement, détresse, danger | Parler doux à l'enfant + **alerte silencieuse immédiate** |

## Enum PHP à créer

```php
// app/Enums/Zone.php
enum Zone: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Orange = 'orange';
    case Red = 'red';

    public function points(): int
    {
        return match($this) {
            self::Green  => 100,
            self::Yellow => 70,
            self::Orange => 35,
            self::Red    => 0,
        };
    }

    public function isCritical(): bool
    {
        return $this === self::Red;
    }
}
```

## Comment l'IA retourne la zone

Le prompt système de Claude inclut en fin :

```
À la fin de ta conversation, retourne UNIQUEMENT sur la dernière ligne :
ZONE: green | yellow | orange | red
```

Le backend (`ZoneClassifier` service) **parse cette dernière ligne** et la **retire** du message affiché à l'enfant.

### Exemple d'implémentation

```php
class ZoneClassifier
{
    public function extractZone(string $aiResponse): array
    {
        if (preg_match('/^ZONE:\s*(green|yellow|orange|red)\s*$/mi', $aiResponse, $m)) {
            $zone = Zone::from(strtolower($m[1]));
            $clean = trim(preg_replace('/^ZONE:.*$/mi', '', $aiResponse));
            return ['zone' => $zone, 'text' => $clean];
        }
        // Défaut : green + low_confidence
        return ['zone' => Zone::Green, 'text' => $aiResponse, 'low_confidence' => true];
    }
}
```

## Flag `low_confidence`

Si l'IA ne retourne **pas** de ligne `ZONE:` OU si la session contient majoritairement des réponses courtes ("oui", "ok", "je sais pas"), la session est :

- `zone = green`
- `low_confidence = true`

👉 Permet de ne pas surévaluer le bien-être global.

## Priorité absolue

Si `[ALERTE_CRITIQUE]` apparaît en début de réponse IA → **zone = red automatiquement**, même si `ZONE:` dit autre chose.

## Migration BDD

Dans la table `sessions` :

```php
$table->enum('zone', ['green', 'yellow', 'orange', 'red'])->default('green');
$table->boolean('low_confidence')->default(false);
```
