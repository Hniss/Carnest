# CareNest

Plateforme de bien-être émotionnel pour élèves marocains (5-18 ans, pilote 8-14). Un assistant IA bienveillant, **Care**, écoute l'enfant, repère des signaux selon les **Zones of Regulation** (Kuypers) et remonte des alertes à un référent formé de l'école, sans jamais stocker les messages bruts. L'IA signale, l'humain qualifie.

> **MVP v3 (septembre 2026)** — Laravel 13 · Livewire 3 · Tailwind 3 · SQLite · IA interchangeable (OpenAI endpoint UE, Gemini, mode démonstration sans clé)

---

## Aperçu

| Espace | Rôle | URL | Fonction |
|---|---|---|---|
| Élève | — | `/child/login` → `/chat` | Chat avec Care (avatar, mémoire des sujets neutres), utilisable à l'école et hors école |
| Référent | `referent` | `/login` → `/dashboard-referent` | Vue d'ensemble (files à qualifier, à confirmer), élèves, fiche élève, traitement d'alerte en 5 étapes, messagerie parents, délégation |
| Administration | `admin` | `/login` → `/dashboard` | Score climat, zones par classe, charge d'alertes (sans nom d'élève), urgences vitales sans accusé, élèves (`/dashboard/eleves`), comptes école (`/dashboard/comptes`), journal d'accès (`/dashboard/journal`), paramètres (`/settings`) |
| Parent | `parent` | `/login` → `/parent` | Synthèse de l'école, journal, messagerie avec le référent, consentement, export de ses données |

Le contrôle de rôle et le cloisonnement par école sont faits côté serveur (middleware `role:` + vérification dans chaque composant). Un délégué temporaire (`referent_delegations`) n'accède qu'aux alertes actives.

**Détection** : chaque message passe par le modèle (zone + type) et par un lexique de crise déterministe qui ne peut que durcir la zone. Dès qu'un signal orange / rouge apparaît, un second modèle relit toute la conversation et confirme ou infirme (double vérification). La pire zone de la session est retenue. Une zone `orange` ou `red` crée une **alerte** pour le référent.

**Types d'alerte (nomenclature unique, `App\Enums\AlertType`)** :

| Type | Libellé | Vital |
|---|---|---|
| `harcelement` | Harcèlement | non |
| `detresse` | Détresse | non |
| `pensees_negatives` | Pensées négatives | **oui** |
| `danger` | Danger | **oui** |
| `isolement` | Isolement | non |
| `stress` | Stress chronique | non |
| `humiliation_adulte` | Humiliation par un adulte | non |

Les types vitaux (`danger`, `pensees_negatives`) déclenchent une alerte immédiate quel que soit l'horaire, avec notification simultanée du référent et de l'administration. Dans la conversation, Care oriente d'abord l'enfant vers un adulte de confiance proche, puis vers le **2511** (Allô enfance en danger) ; le **141** est réservé au danger physique immédiat.

**Conformité** — aucun message brut n'est conservé : seuls la synthèse (chiffrée au repos), la mémoire de sujets neutres (chiffrée), la zone, le type, l'horodatage et un drapeau `low_confidence` sont persistés (loi 09-08). Aucune donnée d'identité (prénom, école, classe) n'est envoyée au fournisseur d'IA. Toute lecture d'une donnée nominative est journalisée (`audit_logs`, écriture seule). Le compte parent n'est actif qu'après consentement exprès, horodaté, au nom de l'école responsable de traitement.

---

## Pré-requis

- **PHP 8.3+** avec extensions : `mbstring`, `openssl`, `pdo`, `sqlite3`, `tokenizer`, `xml`, `curl`, `fileinfo`
- **Composer 2.x**
- **Node 20+** & **npm**
- Facultatif : une clé API **OpenAI** ou **Gemini**. Sans clé, le mode démonstration (`AI_FAKE=1`) fait tourner toute l'application avec des réponses simulées.

> SQLite est utilisé par défaut, aucun serveur MySQL/Postgres requis pour faire tourner.

---

## Installation rapide (5 minutes)

```bash
# 1. Cloner et entrer dans le dossier
git clone https://github.com/Hniss/Carnest.git
cd Carnest

# 2. Dépendances
composer install
npm install

# 3. Configurer l'environnement
cp .env.example .env
php artisan key:generate

# 4. Dans .env, choisir UNE des deux options :
#    a) sans clé IA (recommandé pour tester) : AI_FAKE=1
#    b) avec clé : AI_PROVIDER=openai + OPENAI_API_KEY=... (ou AI_PROVIDER=gemini + GEMINI_API_KEY=...)
#    Puis fixer les mots de passe de démonstration :
#    DEMO_ADMIN_PASSWORD=... (comptes adultes) et DEMO_CHILD_PASSWORD=... (élèves)

# 5. Initialiser la base SQLite + données de démonstration
#    Linux/Mac :
touch database/database.sqlite
#    Windows PowerShell :
#    New-Item -Path database/database.sqlite -ItemType File
php artisan migrate:fresh --seed --force

# 6. Compiler les assets
npm run build

# 7. Lancer le serveur
php artisan serve
```

L'app est disponible sur **http://127.0.0.1:8000**. Sous Windows, si les pages perdent parfois leur style, lancer `PHP_CLI_SERVER_WORKERS=6 php artisan serve` (serveur de développement mono-thread).

Pour que l'escalade des alertes tourne (relances, notification de l'administration), ouvrir un second terminal : `php artisan schedule:work`.

---

## Comptes de démonstration

Les mots de passe ne sont **jamais** dans le dépôt. Le seeder lit les variables d'environnement :

| Variable (`.env`) | Compte(s) concerné(s) |
|---|---|
| `DEMO_ADMIN_PASSWORD` | `admin@carenest.ma` (administration), `referent@carenest.ma` (référent), `parent@carenest.ma` (parent, 2 enfants avec consentement) — `/login` |
| `DEMO_STAFF_PASSWORD` (facultatif) | remplace `DEMO_ADMIN_PASSWORD` pour le référent et le parent |
| `DEMO_CHILD_PASSWORD` | Élèves `yassine@`, `amina@`, `omar@`, `sara@`, `karim@carenest.ma` — `/child/login` (8-11 ans : Amina, Sara ; 12-18 ans : Karim) |

Si une variable est absente, le seeder génère un mot de passe aléatoire et l'affiche **une seule fois** dans la console (il n'est écrit nulle part) : relancez `php artisan migrate:fresh --seed` après avoir renseigné le `.env` si vous préférez le fixer.

Les données de démonstration sont fictives (école « Agdal (Démo) », élèves « Démo »).

---

## Tester la nouvelle version, rôle par rôle

**1. Élève → alerte.** Connectez-vous avec `amina@carenest.ma` sur `/child/login`. Discutez avec Care, puis écrivez « mais des fois je veux disparaître ». Care répond avec un soutien renforcé et propose le 2511 ; **rien n'indique à l'enfant qu'une alerte est partie**. Cliquez sur « J'ai fini ma session ».

**2. Référent.** Connectez-vous avec `referent@carenest.ma`. La vue d'ensemble montre l'alerte (type « Pensées négatives », niveau critique, double vérification renseignée). Ouvrez-la : « J'ai pris connaissance », puis les 5 étapes : signal → qualification (obligatoire) → action → suivi → « Informer le parent » (synthèse préremplie, modifiable). Testez aussi la fiche élève, la messagerie et la délégation.

**3. Parent.** Connectez-vous avec `parent@carenest.ma`. La synthèse reçue apparaît en quatre blocs (jamais le type de signal, jamais « l'IA a détecté »), puis le journal, la messagerie avec le référent, le consentement (avec retrait) et l'export de données.

**4. Administration.** Connectez-vous avec `admin@carenest.ma`. Le tableau de bord ne montre aucun nom d'élève, sauf dans le bloc « Urgences sans accusé » (signal vital sans prise de connaissance du référent après 60 minutes ouvrées). Testez la création d'un élève avec consentement, les comptes école, les paramètres (horaires, téléphone du référent) et le journal d'accès. Le plafond journalier de tokens n'apparaît pas sur cet écran : c'est un réglage interne CareNest.

**5. Plafond de tokens.** Ce seuil n'est pas exposé à l'établissement : il se règle côté CareNest, dans `school_settings.daily_token_cap`. Pour l'observer, abaissez-le temporairement à 50 en base : une session en zone verte se termine par un message chaleureux de Care ; une session avec un signal n'est jamais coupée. Personne n'est notifié : ni l'établissement, ni le référent, ni le parent, ni l'enfant ne voient le plafond. Seule trace, interne à CareNest : la date du dépassement dans `children.high_usage_notified_on`. Remettez ensuite le défaut avec `php artisan carenest:reset-usage --force` : la commande rétablit 10 000, remet à zéro les compteurs hérités de l'ancien comptage et vide les compteurs de la journée en cours, sans une ligne de SQL. C'est ce dernier point qui débloque une installation restée sur l'ancien code : la migration seule ne suffit pas, il faut lancer la commande. Ne sautez pas cette étape : un plafond laissé à 50 fait clôturer Care dès le premier échange, et il n'est plus modifiable depuis l'écran de l'établissement. En usage normal ce seuil n'est jamais atteint : le compteur ne mesure que le contenu échangé avec l'enfant (réponse produite + message envoyé), jamais le prompt système réémis à chaque appel.

---

## Configuration IA

Le fournisseur est sélectionné via `AI_PROVIDER` dans `.env` :

- `openai` — endpoint régional **UE** par défaut (`https://eu.api.openai.com/v1`), modèle `gpt-4o-mini`.
- `gemini` — endpoint OpenAI-compatible de Google, modèle `gemini-2.5-flash`. Retry automatique sur 429/5xx.
- `anthropic` — bascule vers Claude (stub, à compléter).

**Tester sans clé d'API (mode démonstration)** — mettez `AI_FAKE=1` dans `.env` (avec `APP_ENV=local`) : un faux fournisseur répond de façon déterministe, la détection par mots-clés, les alertes, la double vérification et tous les écrans fonctionnent sans aucun appel externe. Jamais actif en production.

Les URL de base sont configurables (résidence des données) : `OPENAI_BASE_URL`, `ANTHROPIC_BASE_URL`, `GEMINI_BASE_URL`. La version du prompt système (`GeminiService::PROMPT_VERSION`) et le modèle utilisé sont tracés sur chaque session et chaque alerte, avec le nombre de tokens consommés.

**Double vérification** — chaque signal orange / rouge est relu par un second fournisseur sans persona (`AI_ADJUDICATOR_PROVIDER`, `AI_ADJUDICATOR_MODEL`), **toujours différent de celui du premier passage** — par défaut Claude Sonnet, repli journalisé si la clé manque — après la réponse à l'enfant. Désaccord sur un type non vital → alerte « à confirmer » ; type vital → l'alerte part toujours.

**Versions de prompt** — `GeminiService::PROMPT_VERSION` + `PROMPT_HASH` (SHA-256). Toute modification d'un texte de prompt exige d'incrémenter la version et de mettre à jour le hash (le test `PromptHashTest` le rappelle).

Pour ajouter un fournisseur, implémenter `App\Services\AIService` et le lier dans `AppServiceProvider::register()`.

---

## Paging, escalade et planificateur

Les alertes de niveau élevé / critique (ou de type vital) déclenchent une notification interne + un e-mail au référent (texte sans donnée nominative), un SMS pour les signaux vitaux (pilote `log`, interface `App\Contracts\SmsSender` prête pour un fournisseur réel) et, pour les signaux vitaux, une notification simultanée à l'administration.

Sans accusé de réception du référent, l'escalade suit ces paliers, comptés en **heures ouvrées** de l'école (lundi-vendredi, `Paramètres`) pour les alertes non vitales et **en continu** pour les vitales :

| Palier | Action |
|---|---|
| 5 min | relance référent (app + e-mail + SMS) et délégué actif |
| 60 min | `alerts.escalation_exhausted_at` posé ; **signaux vitaux uniquement** : administration prévenue (nom + type, audité). Une alerte non vitale n'est jamais remontée à l'administration. |

La commande `php artisan carenest:escalate-alerts` est planifiée **chaque minute** dans `routes/console.php`. Le planificateur doit tourner :

```bash
# En local
php artisan schedule:work

# En production (cron système)
* * * * * cd /chemin/vers/carenest && php artisan schedule:run >> /dev/null 2>&1
```

Variables d'environnement (voir `.env.example`) : `AI_ADJUDICATOR_PROVIDER`, `AI_ADJUDICATOR_MODEL`, `MAIL_*` (`MAIL_MAILER=log` en local suffit : rien ne plante, les e-mails sont tracés dans `storage/logs/laravel.log`).

Plafond journalier de tokens par élève : réglage interne CareNest (`school_settings.daily_token_cap`, défaut 10 000, `0` = désactivé), jamais visible ni modifiable par l'administration de l'établissement. En zone verte sans alerte, Care clôt chaleureusement la séance ; en zone jaune / orange / rouge ou avec une alerte, aucune limite. Le dépassement n'est notifié à aucun rôle : il pose seulement un marqueur interne CareNest (`children.high_usage_notified_on`), une fois par élève et par jour, destiné au futur tableau de bord de gestion CareNest.

---

## Architecture en bref

```
app/
├── Enums/AlertType.php                 # 7 types, vitaux, libellés
├── Livewire/
│   ├── Child/{Login,ChatInterface}.php
│   ├── Referent/{Overview,Students,StudentProfile,AlertTreatment,Messages,Delegation}.php
│   ├── ParentSpace/{Home,Journal,Messages,Consent,MyData}.php
│   └── Admin/{Dashboard,ChildProfile,Students,Accounts,Settings,AccessLog}.php
├── Models/                             # School, SchoolSetting, User, Child, ChatSession, Alert,
│                                       # AlertLifecycle, AlertAction, FollowUp, ParentChild, ParentThread,
│                                       # ParentMessage, ParentSynthesis, AuditLog, ReferentDelegation,
│                                       # AlertNotification, AppNotification
├── Services/
│   ├── AIService.php                   # interface fournisseur IA
│   ├── GeminiService.php, OpenAIService.php, ClaudeAIService.php (adjudicateur), FakeAIService.php
│   ├── CrisisDetector.php              # lexique de crise (plancher, jamais plafond)
│   ├── Adjudicator.php                 # double vérification par un second modèle
│   ├── SessionCloser.php               # clôture : résumé clinique + mémoire neutre + alerte
│   ├── AlertPager.php, BusinessTime.php, TokenBudget.php, UsageReset.php
│   ├── Notifier.php, Audit.php, SynthesisSender.php, ParentAccountProvisioner.php
│   └── AlertLevelResolver.php, ChildContextBuilder.php, WellbeingTrendResolver.php
├── Jobs/{ProcessSessionClosure,AdjudicateSignal}.php
├── Console/Commands/{EscalateAlerts,ResetUsage}.php
├── Http/Middleware/EnsureRole.php
└── Observers/ChildObserver.php
```

**Stack émotion :** Zones of Regulation (Kuypers)
- `green` = 100 pts · `yellow` = 70 · `orange` = 35 · `red` = 0
- `score_enfant` = moyenne pondérée 7 jours glissants ; score climat = moyenne de l'établissement
- Tranches d'âge : `5-7` / `8-11` / `12-18` (calculées depuis `birth_date` quand elle existe)

---

## Tests

```bash
php artisan test
```

327 tests · 1 318 assertions : schéma et migrations rejouables, rôles et cloisonnement multi-école, espaces référent / parent / administration, chaîne d'alerte (adjudication, paging, escalade en heures ouvrées), plafond de tokens, chiffrement, limitation de débit, pseudonymisation vers l'IA, versions de prompt. Aucun appel réseau réel n'est possible depuis la suite (`Http::preventStrayRequests`).

---

## Limites connues de cette version

- Pas de double authentification sur les comptes école.
- SMS et e-mail en mode journal (`LogSmsSender`, `MAIL_MAILER=log`) : fournisseurs réels à brancher.
- Interface en français uniquement ; check-in 5-7 ans et lexique darija non implémentés.
- Jamais déployé hors poste local ; migrations non exercées sur MySQL.

---

## Stack technique

- **Backend** : Laravel 13, PHP 8.3, SQLite (dev) / MySQL (prod)
- **Frontend** : Livewire 3 + Tailwind 3 + Alpine.js
- **Auth** : Laravel Breeze (Volt) — guard `web` (admin, référent, parent) + guard `child` (élève)
- **IA** : OpenAI gpt-4o-mini (endpoint UE) ou Gemini 2.5 Flash, adjudicateur sur un second fournisseur, mode démonstration sans clé

---

## Licence

Projet de démonstration. Tous droits réservés.
