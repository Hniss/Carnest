# CareNest

Plateforme de bien-être émotionnel pour élèves marocains (5-18 ans, pilote 8-14). Un assistant IA bienveillant écoute l'enfant, classifie son état émotionnel selon les **Zones of Regulation** (Kuypers), et remonte des alertes au référent / à la direction sans jamais stocker les messages bruts.

> **MVP** — Laravel 11 · Livewire 3 · Tailwind 3 · SQLite · Gemini (gratuit) ou Anthropic Claude

---

## Aperçu

| Espace | Public | Fonction |
|---|---|---|
| `/login` | Admin / direction | Tableau de bord — score climat, élèves à suivre, alertes |
| `/child/login` | Élève | Chat avec **Care**, l'assistant IA |

L'analyse émotionnelle (zone green / yellow / orange / red) est faite **à la clôture de chaque session** (et en temps réel dès qu'un signal orange/rouge apparaît). Une zone `orange` ou `red` génère automatiquement une **alerte** côté admin.

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

Les types vitaux (`danger`, `pensees_negatives`) déclenchent une alerte immédiate quel que soit l'horaire.

**Conformité** — aucun message brut n'est conservé : seule la synthèse IA (chiffrée au repos), la zone, le timestamp et un drapeau `low_confidence` sont persistés (loi 09-08 + RGPD). Aucune donnée d'identité (prénom, école, classe) n'est envoyée au fournisseur d'IA.

---

## Pré-requis

- **PHP 8.3+** avec extensions : `mbstring`, `openssl`, `pdo`, `sqlite3`, `tokenizer`, `xml`, `curl`, `fileinfo`
- **Composer 2.x**
- **Node 20+** & **npm**
- Une **clé API Gemini** gratuite : https://aistudio.google.com/app/apikey

> SQLite est utilisé par défaut, aucun serveur MySQL/Postgres requis pour faire tourner.

---

## Installation rapide (5 minutes)

```bash
# 1. Cloner et entrer dans le dossier
git clone <url-du-repo>
cd MVP

# 2. Dépendances
composer install
npm install

# 3. Configurer l'environnement
cp .env.example .env
php artisan key:generate

# 4. Coller la clé Gemini dans .env (ligne GEMINI_API_KEY=...)
#    et définir les mots de passe de démonstration (voir « Comptes de démonstration »)

# 5. Initialiser la base SQLite + données de démo
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

L'app est disponible sur **http://127.0.0.1:8000**.

---

## Comptes de démonstration

Les mots de passe ne sont **jamais** dans le dépôt. Le seeder lit deux variables d'environnement :

| Variable (`.env`) | Compte(s) concerné(s) |
|---|---|
| `DEMO_ADMIN_PASSWORD` | Administrateur `admin@carenest.ma` (`/login`) |
| `DEMO_CHILD_PASSWORD` | Élèves `yassine@`, `amina@`, `omar@`, `sara@`, `karim@carenest.ma` (`/child/login`) |

Si une variable est absente, le seeder génère un mot de passe aléatoire et l'affiche **une seule fois** dans la console (il n'est écrit nulle part) : relancez `php artisan migrate:fresh --seed` après avoir renseigné le `.env` si vous préférez le fixer.

---

## Tester le flow complet

1. Connecte-toi côté **enfant** avec un compte ci-dessus.
2. Discute avec Care — partage une émotion, une difficulté, etc.
3. Clique sur **« J'ai fini ma session »** en bas.
   → l'IA analyse la conversation, classe la zone émotionnelle, et crée une alerte si `orange` ou `red`.
4. Déconnecte-toi, connecte-toi côté **admin**.
5. Le dashboard montre le score climat de l'établissement, la liste des élèves et les alertes générées.

---

## Configuration IA

Le provider est sélectionné via `AI_PROVIDER` dans `.env` :

- `gemini` (par défaut) — endpoint OpenAI-compatible de Google, clé gratuite, modèle `gemini-2.5-flash`. Retry automatique sur 429/5xx.
- `openai` — endpoint régional **UE** par défaut (`https://eu.api.openai.com/v1`).
- `anthropic` — bascule vers Claude (stub, à compléter pour la prod).

Les URL de base sont configurables (résidence des données) : `OPENAI_BASE_URL`, `ANTHROPIC_BASE_URL`, `GEMINI_BASE_URL`. La version du prompt système (`GeminiService::PROMPT_VERSION`) et le modèle utilisé sont tracés sur chaque session et chaque alerte, avec le nombre de tokens consommés.

Pour ajouter un provider, implémenter `App\Services\AIService` et binder dans `AppServiceProvider::register()`.

---

## Architecture en bref

```
app/
├── Livewire/
│   ├── Admin/Dashboard.php
│   └── Child/{Login,ChatInterface}.php
├── Models/
│   ├── School, SchoolSetting           # multi-école
│   ├── User, Child                     # 2 guards distincts (web + child)
│   ├── ChatSession, Alert, AdminNote
├── Services/
│   ├── AIService.php                   # interface
│   ├── GeminiService.php               # impl. Gemini
│   └── ClaudeAIService.php             # stub Anthropic
├── Jobs/ProcessSessionClosure.php      # recalcul score_enfant + status
└── Observers/ChildObserver.php         # auto-set age_group
```

**Stack émotion :** Zones of Regulation (Kuypers)
- `green` = 100 pts · `yellow` = 70 · `orange` = 35 · `red` = 0
- `score_enfant` = moyenne pondérée 7 jours glissants
- `status = 'a_suivre'` automatique si score < 50
- Tranches d'âge : `5-7` / `8-11` / `12-18` (calculées depuis `birth_date` quand elle existe)

---

## Tests

```bash
php artisan test
```

192 tests · ~600 assertions · couvrent le schéma BDD, modèles, observer, auth, services IA, filet de sécurité, chiffrement, limitation de débit, cloisonnement multi-école.

---

## Stack technique

- **Backend** : Laravel 11, PHP 8.3, SQLite (dev) / MySQL (prod)
- **Frontend** : Livewire 3 + Tailwind 3 + Alpine.js
- **Auth** : Laravel Breeze (Volt) — guards `web` (admin) + `child` (élève)
- **IA** : Gemini 2.5 Flash via endpoint OpenAI-compatible
- **Queue** : SQLite-backed (jobs de clôture de session)

---

## Licence

Projet de démonstration. Tous droits réservés.
