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
- [x] Service IA swappable (`AIService` interface + `GeminiService` dev + `ClaudeAIService` stub prod)
- [ ] Intégration Claude API (prod — ClaudeAIService à implémenter)
- [x] Chat enfant — **post-QA v3** (corrections retours tests Probleme CareNest V3) :
  - SYSTEM_TEMPLATE réécrit (P3, P5, P7, P8, P11, P12, P13, P15, P16, P17, P18, P19, P20) — validation émotion d'abord, vocabulaire 100% marocain (141, enseignant, surveillant, responsable de l'école), confidentialité honnête (jamais "espace secret"), transparence identité (aide virtuelle), interdiction de répéter les mots dévalorisants, gestion conflit physique, orientation rapide vers adulte alternatif quand peur d'un adulte précis, phrase concrète à dire à l'adulte de confiance, pas de fausse excuse "j'ai envoyé trop vite".
  - `GeminiService::chat()` : `max_tokens` 1200 → 2048, retry à 3000 sur `finish_reason=length`, troncature propre à la dernière phrase complète si toujours coupé (P14).
  - `ChatInterface` : retire le welcome du contexte IA (P4, dès le 1er fetch), niveau d'alerte calculé par `AlertLevelResolver` selon contexte (P9, P10), `safeFallback` corrigé (plus de "qu'est-ce que tu as fait de chouette aujourd'hui" après tristesse).
- [x] Dashboard admin (corrections V3) :
  - `endSession` → `dispatchSync(ProcessSessionClosure)` pour que `last_session_at`, `score_enfant`, `status` du Child se mettent à jour **immédiatement** sans worker queue (P1, P6).
  - Niveaux d'alerte 4 paliers : low / moderate / high / critical (`AlertLevelResolver` — P9, P10).
  - Bouton "Paramètres" fonctionnel → page `/settings` (Livewire `Admin\Settings`) éditant seuil d'alerte, notifications email, langue (P2).
- [x] Tests (119 passed, 326 assertions — +21 tests V5 : WellbeingTrendResolver ×10, ChildProfile ×6, ChatInterface fallback anti-boucle ×4 ; +28 V4)
- [x] **Corrections V4** (Probleme CareNest V4 — 12 problèmes en 1 PR) :
  - **P1** Bouton « J'ai fini ma session » : `type="button"`, hit-area large (px-5 py-2.5 rounded-full), `wire:loading` + `wire:target="endSession"` avec spinner « Je clôture… », `z-10` au-dessus de la barre input, `active:scale-95`, focus ring accessible.
  - **P2** Sauvegarde session abandonnée : `last_activity_at` sur `chat_sessions` + job `CloseIdleSessions` exécuté toutes les 2 min par `Schedule::call` (synchrone, pas de queue). Ferme les sessions idle ≥ 5 min, crée alerte fallback si zone orange/red, dispatchSync `ProcessSessionClosure`.
  - **P3** Sidebar : lien « Établissement » → `route('admin.settings')` avec état actif `request()->routeIs('admin.settings')` + `wire:navigate`.
  - **P4** : `parseTurn` deux phases (extraction stricte + strip total). Strip TOUTES les lignes `ALERT_TYPE / ZONE / RISK_LEVEL / SCORE / CATEGORY / CONFIDENCE` même avec valeurs inattendues (pipes, scores numériques, valeurs inconnues). ALERT_TYPE multi-valeurs `tristesse|isolement` → premier alert_type valide extrait.
  - **P5** : statut enfant à 4 paliers (`ok`/`a_surveiller`/`a_suivre`/`critique`) via `ChildStatusResolver` avec seuils 70/50/30 + override par alertes critical/high non résolues 7j. Migration MySQL+SQLite. Dashboard reflète les 4 statuts ; carte stat « À suivre » agrège `a_suivre + critique`.
  - **P6** Couleurs pastilles dashboard : 5 niveaux (critical=rouge+pulse, high=orange, moderate=amber, low=sky, resolved=stone) avec classes badges dédiées `.badge-orange` / `.badge-blue`.
  - **P7** : suppression de toute mention « infirmière » dans `SYSTEM_TEMPLATE` et `safetyMessage()`. Vocabulaire 100% Maroc.
  - **P8** : champ `gender` (`m`/`f`/`x`/null) ajouté à `children`, propagé dans `AIService::chat()` et `analyzeSession()`. `GeminiService::buildSystemPrompt()` injecte une directive d'accord de genre stricte (interdit « obligé(e) », « fatigué(e) »). Seeder fixe : Yassine/Omar/Karim=m, Amina/Sara=f.
  - **P9** : 141 strictement encadré dans le prompt (urgence médicale/danger immédiat uniquement) et conditionnel dans `safetyMessage()` (8-11 et 12-14 seulement, formulation « si tu ne peux parler à personne tout de suite »). 5-7 ans : aucune mention de numéro.
  - **P10** : nouveau type `humiliation_adulte` (migration enum + `CrisisDetector` patterns prioritaires + `AlertLevelResolver` factor +2 → high d'office). Prompt système classe les insultes par enseignant en orange minimum. Pronom `m'/me` obligatoire dans les patterns (anti-faux-positif « ma maîtresse insulte les autres »).
  - **UI-1** Logo CareNest : composant `<x-carenest-logo>` (4 variantes PNG) + favicon, déployé dans tous les layouts (`app`, `guest`, `child`) + login enfant + chat header. Plus aucun usage de l'icône `leaf` comme logo (elle reste comme avatar IA dans les bulles de chat).
  - **UI-2** Welcome enfant : login `livewire/child/login.blade.php` refait en 2 colonnes — panneau gauche `bg-brand-700` avec logo blanc + message bienveillant « Tu n'as pas besoin d'avoir les bons mots. Dis juste ce que tu ressens, comme tu peux. Care est là pour t'écouter avec douceur. 🌿 ». Le panneau loi 09-08 reste sur le login admin (`guest.blade.php`).

- [x] **Corrections V5** (mai 2026 — 2 chantiers en 1 PR) :
  - **Fix boucle bot** (signalé : 2 fallbacks « Je t'écoute… » / « D'accord, je suis là… » après 2 messages enfant positifs). Cause : Gemini API en 503, `safeFallback('green')` ne servait que 2 phrases génériques en alternance. Correctif (`app/Livewire/Child/ChatInterface.php`) :
    - Nouveaux props `consecutiveFailures` + `lastFallback` (sérialisation Livewire).
    - `buildFallback()` remplace `safeFallback()` : 3 candidats par zone, détection positive (`isPositive()`) sur dernier message en mémoire (jamais persisté), anti-répétition via `pickDistinct()`.
    - 2e échec consécutif zone green/yellow → bascule sur 1 des 3 messages dégradés honnêtes (« Pardon, j'ai un peu de mal à te répondre… »).
    - Succès IA → reset `consecutiveFailures` à 0 + `lastFallback` à null.
  - **Suivi psychique longitudinal sur profil enfant** (nouvelle page `/children/{child}` route `admin.children.show`) :
    - Enum `App\Enums\ZoneScore` : source de vérité unique du mapping zone→score (100/70/35/0), réutilisé par `ProcessSessionClosure` et `WellbeingTrendResolver`.
    - DTOs read-only `App\DataTransferObjects\{WellbeingTrendReport, WindowStats, TrendBadge}`.
    - Service `App\Services\WellbeingTrendResolver` (calcul à la volée, **pas** de migration) : tendance court terme 7j + long terme 30j, seuils delta ±10, sous-représentation `< 2` sessions (7j) / `< 3` (30j) → forçage `stable`, `worseningStreak` (max 8 sem) → `worseningSignal` si streak ≥ 2, sparkline 8 semaines (ASC, ?float pour les trous).
    - Page Livewire `App\Livewire\Admin\ChildProfile` : scope école vérifié en `mount()` ET dans `resolveAlert()` via `assertSameSchool()` (defense in depth). Actions `resolveAlert()` (recalcule `child->status` via `ChildStatusResolver`), `addNote()` (validation 5-500 chars).
    - Channel logging `admin_audit` (`config/logging.php`, driver `daily`, rétention 90j) — log à `storage/logs/admin-audit.log`. Actions tracées : `view_profile`, `resolve_alert`, `add_note`. **Aucun contenu de message ou de note loggé** (uniquement IDs + IP).
    - Vue `resources/views/livewire/admin/child-profile.blade.php` : bannière conditionnelle d'aggravation (variante douce streak < 3, forte ≥ 3), 3 KPI cards (score / sessions 7j / alertes 30j), 2 badges tendance (▲▬▼), sparkline SVG inline 8 semaines avec interruptions sur les `null`, dernière session, alertes récentes (resolvables), historique 20 dernières sessions, notes admin.
    - Lien depuis dashboard : nom enfant cliquable → profil (`wire:navigate`).

### Réserves QA ouvertes (non bloquantes)
- Tester en prod réelle que le scheduler tourne (`php artisan schedule:work` ou cron système) — sans cela les sessions abandonnées ne se ferment pas.
- Ajouter au moins un test Livewire intégré couvrant `sendMessage → Alert créée → 2e message neutre → pas de 2e Alert` (idempotence run-to-run).
- Vérifier que `ProcessSessionClosure` (ou un futur Notifier) respecte la règle d'or §7 : push/email **uniquement** sur `level=critical`. Aujourd'hui le job ne fait que recalculer le `score_enfant` + status — pas de canal push/email implémenté.
- Tests E2E manuels : reprendre les 10 cas du document `Probleme CareNest V4` après déploiement pour valider la régression.
- **V5** : `chat_sessions.ai_summary` n'est pas chiffré au repos (`encrypted` cast). Décision PO différée — à confirmer ; impact : rechiffrement one-shot des données existantes si activation tardive.
- **V5** : pas de FormRequest dédié pour `ChildProfile::addNote` (validation inline). Acceptable pour 1 champ, à externaliser si la note gagne en complexité.
- **V5** : pas de rate-limiting sur `resolveAlert` / `addNote` (route admin authentifiée, mais à ajouter pour audit anti-abus).
- **V5** : Tester en prod réelle le passage à un provider de fallback (Anthropic) si Gemini reste indisponible plus de N minutes — décision PO : différer (filet anti-boucle suffit pour MVP).

---

## 9. Références

- **Spec technique :** `docs/spec-technique-v2.md`
- **Zones of Regulation (Leah Kuypers) :** `docs/complement-zones.md`
- **Mockup UI (référence visuelle) :** `docs/ui-mockup.jsx`
- **Loi 09-08 Maroc :** https://www.cndp.ma/
