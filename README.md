# CareNest

Plateforme de bien-être émotionnel pour élèves marocains (5-14 ans). Un assistant IA bienveillant écoute l'enfant, classifie son état émotionnel selon les **Zones of Regulation** (Kuypers), et remonte des alertes au psychologue scolaire / direction sans jamais stocker les messages bruts.

> **MVP** — Laravel 11 · Livewire 3 · Tailwind 3 · SQLite · Gemini (gratuit) ou Anthropic Claude

---

## Aperçu

| Espace | Public | Fonction |
|---|---|---|
| `/login` | Admin / direction | Tableau de bord — score climat, élèves à suivre, alertes |
| `/child/login` | Élève | Chat avec **Care**, l'assistant IA |

L'analyse émotionnelle (zone green / yellow / orange / red) est faite **à la clôture de chaque session**. Une zone `orange` ou `red` génère automatiquement une **alerte critique/modérée** côté admin.

**Conformité** — aucun message brut n'est conservé : seule la synthèse IA, la zone, le timestamp et un drapeau `low_confidence` sont persistés (loi 09-08 + RGPD).

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

| Rôle | URL | Email | Mot de passe |
|---|---|---|---|
| Administrateur (direction) | `/login` | `admin@carenest.ma` | `admin123` |
| Élève (10 ans) | `/child/login` | `yassine@carenest.ma` | `demo123` |
| Élève (8 ans) | `/child/login` | `amina@carenest.ma` | `demo123` |
| Élève (11 ans) | `/child/login` | `omar@carenest.ma` | `demo123` |
| Élève (9 ans) | `/child/login` | `sara@carenest.ma` | `demo123` |
| Élève (12 ans) | `/child/login` | `karim@carenest.ma` | `demo123` |

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
- `anthropic` — bascule vers Claude (stub, à compléter pour la prod).

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

---

## Tests

```bash
php artisan test
```

44 tests · ~150 assertions · couvrent le schéma BDD, modèles, observer, auth.

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
