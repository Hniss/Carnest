---
name: climate-score-calc
description: Calcul du Score Climat Scolaire de CareNest (formule 2 étapes sur 7 jours glissants) + statut "à suivre" des enfants. À utiliser pour tout calcul ou affichage de score.
---

# Score Climat Scolaire — Formule

## Principe

Score global de l'école basé sur l'état émotionnel de ses enfants, calculé à partir des zones de leurs sessions récentes.

## Formule en 2 étapes

### Étape 1 — Score par enfant (sur 7 jours glissants)

```
score_enfant = moyenne des points de toutes ses sessions des 7 derniers jours
```

Avec points par zone :
- Vert = 100, Jaune = 70, Orange = 35, Rouge = 0

### Étape 2 — Score école

```
Score Climat = moyenne de tous les score_enfant (des enfants actifs)
```

## Règles importantes (ne pas ignorer)

1. **Enfant sans session sur 7 jours → ignoré du calcul.** Le silence n'est pas un signal fiable.
2. **Session avec alerte Rouge → zone = red automatiquement.**
3. **Session sans signal clair → zone = green + `low_confidence = true`.**
4. **Recalcul à chaque fin de session**, pas en temps réel pendant la conversation.

## Statut "à suivre"

Pour chaque enfant :

```
if (score_enfant < 50) {
    status = "à suivre"
} else {
    status = "OK"
}
```

Affiché dans le dashboard admin pour prioriser les actions.

## Implémentation service Laravel

```php
// app/Services/ClimateScoreCalculator.php
class ClimateScoreCalculator
{
    public function childScore(Child $child): ?float
    {
        $sessions = $child->sessions()
            ->where('ended_at', '>=', now()->subDays(7))
            ->get();

        if ($sessions->isEmpty()) {
            return null; // ignoré du calcul
        }

        return $sessions->avg(fn($s) => $s->zone->points());
    }

    public function schoolScore(School $school): float
    {
        $scores = $school->children()
            ->get()
            ->map(fn($c) => $this->childScore($c))
            ->filter(); // retire les null

        return $scores->avg() ?? 100;
    }

    public function isChildToFollow(Child $child): bool
    {
        $score = $this->childScore($child);
        return $score !== null && $score < 50;
    }
}
```

## Exemple chiffré

| Enfant | Sessions 7j | Calcul | Score | Statut |
|---|---|---|---|---|
| Yassine | Orange + Jaune + Orange | (35+70+35)/3 | **47** | à suivre |
| Sara | Vert | 100/1 | **100** | OK |
| Karim | Jaune + Vert | (70+100)/2 | **85** | OK |

**Score école** = (47 + 100 + 85) / 3 = **77/100**

## Jobs recommandés

- **À la fin de chaque session** → Job `ProcessSessionClosure` qui :
  1. Parse la zone
  2. Met à jour la session
  3. Recalcule `score_enfant`
  4. Met à jour `status` de l'enfant
  5. (Rate-limited) recalcule le score école

- **Cron nightly** → Job `RefreshClimateScore` qui recalcule tout pour toutes les écoles (sécurité).

## Affichage

- Barre de progression teal (gradient `#1A7F6B → #2ECC9A`)
- Score sur 100 en gros (`font-Nunito, size 48px, weight 800`)
- Delta vs semaine précédente : "↑ +3 cette semaine" en vert

## Seuils visuels suggérés

| Score | Label | Couleur |
|---|---|---|
| 85-100 | Excellent | green |
| 70-84 | Bon | teal |
| 50-69 | À surveiller | yellow |
| < 50 | Préoccupant | red |
