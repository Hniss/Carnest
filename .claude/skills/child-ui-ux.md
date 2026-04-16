---
name: child-ui-ux
description: Règles d'UX pour les interfaces destinées aux enfants CareNest — couleurs, langage, typo, accessibilité, animations. À lire avant tout composant visible par un enfant.
---

# UX Enfant — CareNest

## Philosophie

Une UI pour enfant doit être **douce, prévisible, non anxiogène**. Zéro flash, zéro son par défaut, zéro pub, zéro gamification agressive.

## Adaptation par groupe d'âge

| Groupe | Âge | Langage | Émojis | Taille texte | Exemple message |
|---|---|---|---|---|---|
| Petits | 5-7 ans | Très simple, phrases courtes | Beaucoup (😊🌿✨) | 18px+ | "Salut ! 😊 Moi c'est Care !" |
| Moyens | 8-11 ans | Amical, naturel | Modéré | 16px | "Hey ! Contente de te voir 😊" |
| Grands | 12-14 ans | Respectueux, mature | Peu | 15px | "Bonjour ! Je suis là pour toi." |

Implémenter via helper PHP :

```php
// app/Support/AgeGroup.php
function welcome_message(int $age): string
{
    return match(true) {
        $age <= 7  => "Salut ! 😊 Moi c'est Care ! Comment tu vas aujourd'hui ?",
        $age <= 11 => "Hey ! Contente de te voir 😊 Tu peux me dire comment s'est passée ta journée ?",
        default    => "Bonjour ! Je suis là pour toi. Comment tu te sens en ce moment ?",
    };
}
```

## Palette (Tailwind)

Cf `frontend-dev.md`. Tons **teal** apaisants. Jamais de rouge vif côté enfant (réservé admin).

## Typographie

- **Titres / bulles chat** : `Nunito` (rond, amical)
- **Textes admin** : `DM Sans` (neutre, pro)
- **Jamais** de police décorative/"comic" — évoque l'infantilisation

```html
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
```

## Animations

- `fadeUp` : 300-400ms, ease-out → apparition des bulles
- `bounce` : 1.2s loop → indicateur de frappe
- **Jamais** : shake, pulse violent, zoom brutal
- Respecter `prefers-reduced-motion` :
  ```css
  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
  }
  ```

## Composants douceur

### Bulles de chat
- Radius 18px
- Bot : fond `#E8F5F2`, coin bas-gauche 4px
- Enfant : fond teal, coin bas-droite 4px, texte blanc

### Input
- Radius **50px** (full rounded) — aspect bulle ronde
- Border `#E2EDE9`, focus `teal`
- Placeholder amical : "Écris ce que tu ressens, {prénom}..."

### Bouton d'envoi
- Rond 46px
- Teal, hover `scale(1.05)`
- Icône avion ou cœur (pas de flèche agressive)

## Accessibilité (non négociable)

1. **Contraste AA** minimum partout (vérifier avec outil type Stark).
2. **Tap targets** ≥ 44×44px (mobile first).
3. **Labels** sur tous les inputs (`<label for>` ou `aria-label`).
4. **Keyboard nav** : Tab/Enter fonctionnels partout.
5. **Focus visible** : ne jamais supprimer l'outline sans le remplacer.

## Textes interdits côté enfant

- ❌ "Erreur", "Échec", "Problème détecté"
- ❌ "Analyse en cours", "Évaluation"
- ❌ Toute mention d'IA, d'algorithme, de données

- ✅ "Oups, je n'arrive pas à répondre là. Tu peux réessayer ? 🌿"
- ✅ "Je réfléchis..." (avec l'indicateur de frappe)

## Émojis autorisés côté enfant

Toujours doux : 😊 🌿 ✨ 💚 🌸 ☀️ 🦋 🌈

**Interdits** : ☠️ ⚠️ 😭 🚨 🔥 ⛔ (anxiogènes)

## Sortie de session

- Bouton discret "J'ai fini 👋"
- Ou fermeture auto après 10 min d'inactivité
- Message de fin doux : "Bonne journée ! À bientôt 🌿"

## Anti-patterns à éviter

❌ Pop-ups modaux bloquants côté enfant
❌ Compte à rebours, timer visible
❌ Badges / points / "niveaux" (gamification anxiogène)
❌ Notifications sonores
❌ Demander rating / feedback à l'enfant
❌ Publicité, tracking analytics visible
