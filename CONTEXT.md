# CareNest — CONTEXT.md (Orchestrateur Projet)

> Ce fichier est le **cerveau central** du projet. Claude Code le lit en priorité à chaque tâche pour comprendre le contexte, les règles et décider quel agent / skill invoquer.

---

## 1. Vision Produit

**CareNest** est une plateforme de détection du bien-être émotionnel des enfants (5–14 ans) en milieu scolaire au Maroc.

- **Côté enfant :** un chat conversationnel avec un assistant IA bienveillant ("Care") adapté à l'âge.
- **Côté admin (école) :** dashboard d'alertes, score climat scolaire, suivi des enfants à risque.
- **Conformité :** loi marocaine **09-08** (CNDP) + **RGPD**. Le contenu exact des messages enfants n'est **JAMAIS** stocké ni affiché. Seuls des résumés IA + classification par zone.

---

## 2. Stack Technique MVP

| Couche | Technologie | Version |
|---|---|---|
| Backend | **Laravel** | 11.x |
| Frontend | **Livewire** (v3) + Alpine.js | 3.x |
| Base de données | **MySQL** | 8.0 |
| IA | **API Anthropic Claude** | claude-sonnet-4 |
| Auth | Laravel Breeze (Livewire stack) | latest |
| CSS | Tailwind CSS | 3.x |

> **Post-MVP (après premières ventes) :** possibilité de migration vers Next.js / Ionic Mobile / microservices.

---

## 3. Architecture Multi-Agents

Ce projet utilise **3 agents spécialisés** + 1 reviewer. Chaque agent a son périmètre, ses skills, ses règles.

### 🏛️ `architect` — Décisions structurelles
- Schémas BDD, migrations, contrats API
- Choix techniques, sécurité, conformité RGPD/09-08
- Revue avant chaque gros chantier

### ⚙️ `backend-dev` — Laravel + API Claude
- Modèles Eloquent, migrations, seeders
- Controllers, FormRequests, Resources
- Services : intégration Claude API, calcul score climat, parsing zones
- Jobs/Queues pour traitement asynchrone

### 🎨 `frontend-dev` — Livewire + Tailwind
- Composants Livewire (chat enfant, dashboard admin)
- Modals, tables, charts (donut, barres)
- Responsive mobile-first
- Animations douces (adaptées enfants)

### 🔍 `qa-reviewer` — Revue qualité
- Revue code après chaque feature
- Vérifie conformité RGPD, tests, conventions

---

## 4. Règles d'Or (NON NÉGOCIABLES)

1. **Aucun message brut d'enfant n'est stocké en BDD.** On stocke uniquement : résumé IA, zone (green/yellow/orange/red), type d'alerte.
2. **L'IA ne dit JAMAIS à l'enfant qu'elle analyse ses émotions.**
3. **Une seule question à la fois** dans le chat enfant.
4. **Adaptation au groupe d'âge** obligatoire (5-7 / 8-11 / 12-14).
5. **Zones de Regulation (Kuypers)** : green=100, yellow=70, orange=35, red=0.
6. **Score climat** = moyenne des `score_enfant` sur **7 jours glissants** (pas la journée).
7. **Alertes push/email** uniquement pour zone **rouge**. Orange = dashboard seulement.
8. **`low_confidence = true`** si zone green sans émotion claire détectée.
9. **Statut "à suivre"** auto si `score_enfant < 50`.
10. **Notes admin optionnelles** — ne jamais les rendre obligatoires (friction).

---

## 5. Skills disponibles

Les skills sont dans `.claude/skills/`. À invoquer selon la tâche :

| Skill | Quand l'utiliser |
|---|---|
| `zones-of-regulation` | Toute logique de classification émotionnelle |
| `climate-score-calc` | Calcul du score climat école + statut enfant |
| `claude-api-integration` | Appels à l'API Anthropic, prompts système |
| `rgpd-loi-09-08` | Stockage données, endpoints exposant des infos enfants |
| `laravel-api` | Routes, controllers, resources Laravel |
| `livewire-components` | Tout composant Livewire |
| `child-ui-ux` | UI destinée aux enfants (couleurs, langage, accessibilité) |

---

## 6. Workflow de travail

### Pour une nouvelle feature :
1. **Toujours** commencer par invoquer l'agent `architect` pour valider l'approche.
2. Écrire un plan (utiliser le skill `superpowers:writing-plans` si disponible).
3. Déléguer au(x) agent(s) concerné(s).
4. Passer par `qa-reviewer` avant de marquer terminé.

### Commandes slash utiles :
- `/new-feature <nom>` — scaffold une nouvelle feature (voir `.claude/commands/`)
- `/daily-sync` — résumé de l'état du projet

---

## 7. Structure du code

```
backend/                         # Laravel app
├── app/
│   ├── Models/                  # User, Child, Session, Alert, Note
│   ├── Http/Livewire/           # Composants Livewire
│   ├── Services/
│   │   ├── ClaudeAIService.php  # Intégration API
│   │   ├── ZoneClassifier.php   # Parse "ZONE: xxx"
│   │   └── ClimateScoreCalculator.php
│   └── Jobs/
│       └── ProcessSessionClosure.php
├── database/migrations/
└── resources/views/livewire/
```

---

## 8. État actuel du projet

- [x] Spec technique v2 rédigée (voir `docs/spec-technique-v2.md`)
- [x] Compléments Zones of Regulation validés (voir `docs/complement-zones.md`)
- [x] Mockup UI React réalisé (voir `docs/ui-mockup.jsx` — **référence visuelle uniquement**, à porter en Livewire)
- [x] Setup Laravel + Livewire
- [x] Schéma BDD
- [x] Service IA swappable (`AIService` interface + `LlmApiService` dev + `ClaudeAIService` stub prod)
- [ ] Intégration Claude API (prod — ClaudeAIService à implémenter)
- [ ] Chat enfant
- [ ] Dashboard admin
- [x] Tests (48 passed)

---

## 9. Références

- **Spec technique :** `docs/spec-technique-v2.md`
- **Zones of Regulation (Leah Kuypers) :** `docs/complement-zones.md`
- **Mockup UI (référence visuelle) :** `docs/ui-mockup.jsx`
- **Loi 09-08 Maroc :** https://www.cndp.ma/
