# CareNest MVP — Spec Schéma Base de Données

> **Statut :** Validé par Hamza — 2026-04-17
> **Auteur :** Agent Architect (Claude × Hamza)
> **Référence :** CONTEXT.md · docs/complement-zones.md · docs/brainstorme_claude_hamza.md

---

## 1. Contexte & Contraintes

CareNest est une plateforme de détection du bien-être émotionnel des enfants (5–14 ans) en milieu scolaire au Maroc. Le schéma BDD doit respecter impérativement :

- **Loi 09-08 (CNDP) + RGPD :** aucun message brut d'enfant stocké. Seuls le résumé IA, la zone et les métadonnées de session sont conservés.
- **Multi-école :** une seule base de données héberge plusieurs écoles clientes.
- **Performance MVP :** optimisé pour < 500 enfants/école. Score calculé à la volée avec dénormalisation ciblée.
- **Stack :** Laravel 11 · MySQL 8.0

---

## 2. Architecture Générale

### Domaines

```
┌─────────────────────────────────────────────────────────┐
│  DOMAINE ÉCOLE                                          │
│  schools ──< school_user >── users                      │
│  schools ──< school_settings                            │
├─────────────────────────────────────────────────────────┤
│  DOMAINE ENFANT                                         │
│  schools ──< children                                   │
├─────────────────────────────────────────────────────────┤
│  DOMAINE SESSION & ANALYSE                              │
│  children ──< chat_sessions ──< alerts ──< admin_notes   │
└─────────────────────────────────────────────────────────┘
```

### Séparation des guards d'authentification

| Acteur | Table | Guard Laravel |
|---|---|---|
| Admin (directeur, conseiller…) | `users` | `web` (Breeze standard) |
| Enfant | `children` | `child` (guard personnalisé) |

Les deux tables ont `email` + `password` (bcrypt). Aucun mélange de rôles dans une table unique.

---

## 3. Schéma Détaillé

### 3.1 `schools`

```sql
CREATE TABLE schools (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(150)    NOT NULL,
    address  VARCHAR(255)    NULL,
    city     VARCHAR(100)    NOT NULL,
    phone    VARCHAR(20)     NULL,
    email    VARCHAR(150)    NOT NULL UNIQUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

**Notes :**
- `email` = contact de l'établissement, pas d'un admin spécifique.
- La langue de l'interface est gérée exclusivement dans `school_settings.language`.

---

### 3.2 `users` *(admins)*

Table standard Laravel Breeze — **aucune modification de structure**.

```sql
CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150)    NOT NULL,
    email             VARCHAR(150)    NOT NULL UNIQUE,
    password          VARCHAR(255)    NOT NULL,
    email_verified_at TIMESTAMP       NULL,
    remember_token    VARCHAR(100)    NULL,
    created_at        TIMESTAMP       NULL,
    updated_at        TIMESTAMP       NULL
);
```

**Notes :**
- Un user peut appartenir à **plusieurs écoles** (consultant externe, groupe scolaire).
- Le rôle est porté par le pivot `school_user`, pas par `users`.

---

### 3.3 `school_user` *(pivot admin ↔ école)*

```sql
CREATE TABLE school_user (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id   BIGINT UNSIGNED NOT NULL,
    role      ENUM('director','counselor','teacher','staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP NULL,

    UNIQUE KEY uq_school_user (school_id, user_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
);
```

**Rôles :**
| Valeur | Description |
|---|---|
| `director` | Directeur — accès complet (alertes, paramètres, comptes enfants) |
| `counselor` | Conseiller psychologique — alertes + notes uniquement |
| `teacher` | Enseignant — dashboard lecture seule |
| `staff` | Personnel — accès restreint |

---

### 3.4 `school_settings`

```sql
CREATE TABLE school_settings (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id            BIGINT UNSIGNED NOT NULL UNIQUE,
    alert_threshold      TINYINT UNSIGNED NOT NULL DEFAULT 30,
    email_notifications  TINYINT(1)       NOT NULL DEFAULT 1,
    language             ENUM('fr','ar','en') NOT NULL DEFAULT 'fr',
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,

    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
);
```

**Notes :**
- `alert_threshold` : pourcentage de sessions négatives déclenchant une alerte globale (réglable via UI admin).
- Créé automatiquement avec les valeurs par défaut à la création d'une école (observer ou seeder).

---

### 3.5 `children`

```sql
CREATE TABLE children (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id       BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(150)    NOT NULL,
    email           VARCHAR(150)    NOT NULL UNIQUE,
    password        VARCHAR(255)    NOT NULL,
    age             TINYINT UNSIGNED NOT NULL,
    age_group       ENUM('5-7','8-11','12-14') NOT NULL,
    classe          VARCHAR(50)     NOT NULL,
    score_enfant    FLOAT           NULL DEFAULT NULL,
    status          ENUM('ok','a_suivre') NOT NULL DEFAULT 'ok',
    last_session_at TIMESTAMP       NULL,
    remember_token  VARCHAR(100)    NULL,
    created_at      TIMESTAMP       NULL,
    updated_at      TIMESTAMP       NULL,

    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    INDEX idx_children_school_status  (school_id, status),
    INDEX idx_children_school_score   (school_id, score_enfant)
);
```

**Notes :**
- `age_group` : calculé automatiquement via **observer Eloquent** (`ChildObserver`) à l'insertion et à la modification de `age`. Ne jamais le renseigner manuellement.
  - 5–7 → `'5-7'` · 8–11 → `'8-11'` · 12–14 → `'12-14'`
- `score_enfant` : `NULL` = aucune session dans les 7 derniers jours (ignoré du calcul du score école).
- `status` : mis à jour par `ProcessSessionClosure` après chaque session. Règle : `score_enfant < 50` → `'a_suivre'`.
- `last_session_at` : dénormalisé pour affichage rapide dans le tableau de gestion enfants.

---

### 3.6 `chat_sessions`

```sql
CREATE TABLE chat_sessions (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    child_id       BIGINT UNSIGNED NOT NULL,
    school_id      BIGINT UNSIGNED NOT NULL,
    zone           ENUM('green','yellow','orange','red') NULL DEFAULT NULL,
    ai_summary     TEXT            NULL,
    low_confidence TINYINT(1)      NOT NULL DEFAULT 0,
    started_at     TIMESTAMP       NOT NULL,
    ended_at       TIMESTAMP       NULL DEFAULT NULL,
    created_at     TIMESTAMP       NULL,
    updated_at     TIMESTAMP       NULL,

    FOREIGN KEY (child_id)  REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id)  ON DELETE CASCADE,
    INDEX idx_chat_sessions_child_ended   (child_id, ended_at),
    INDEX idx_chat_sessions_school_ended  (school_id, ended_at)
);
```

**Notes :**
- `zone = NULL` : session encore active (pas de zone classifiée avant clôture).
- `ai_summary` : généré par l'API Claude à la fermeture de session. **Jamais les messages bruts.**
- `low_confidence = 1` : zone green attribuée par défaut car aucune émotion identifiable (réponses courtes type "oui", "ok").
- `ended_at = NULL` : session active. Deux mécanismes de clôture :
  1. Bouton "J'ai fini" cliqué par l'enfant.
  2. Fermeture automatique après **10 minutes d'inactivité** (job ou timeout Livewire).
- `school_id` dénormalisé pour éviter la jointure sur `children` dans les requêtes de score école.
- L'index `(child_id, ended_at)` est critique pour le calcul du `score_enfant` sur 7 jours glissants.

---

### 3.7 `alerts`

```sql
CREATE TABLE alerts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id  BIGINT UNSIGNED NOT NULL,
    child_id    BIGINT UNSIGNED NOT NULL,
    school_id   BIGINT UNSIGNED NOT NULL,
    type        ENUM('harcelement','detresse','stress','tristesse','danger','isolement') NOT NULL,
    level       ENUM('critical','moderate') NOT NULL,
    status      ENUM('unread','read','resolved') NOT NULL DEFAULT 'unread',
    notified_at TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,

    FOREIGN KEY (session_id) REFERENCES chat_sessions(id)  ON DELETE CASCADE,
    FOREIGN KEY (child_id)   REFERENCES children(id)  ON DELETE CASCADE,
    FOREIGN KEY (school_id)  REFERENCES schools(id)   ON DELETE CASCADE,
    INDEX idx_alerts_school_status (school_id, status),
    INDEX idx_alerts_child_date    (child_id, created_at)
);
```

**Notes :**
- `level = 'critical'` (zone rouge) → email/push envoyé, `notified_at` renseigné.
- `level = 'moderate'` (zone orange) → dashboard uniquement, `notified_at` reste NULL.
- Pas de colonne `ai_summary` ici : on lit `sessions.ai_summary` via la relation `alert → session`.
- `child_id` et `school_id` dénormalisés pour les requêtes directes du dashboard sans jointure chaîne.
- Une session peut générer **au plus une alerte** (relation `hasOne` depuis `Session`).

**Correspondance zone → level :**
| Zone | Level |
|---|---|
| `red` | `critical` |
| `orange` | `moderate` |
| `yellow` / `green` | Pas d'alerte créée |

---

### 3.8 `admin_notes`

```sql
CREATE TABLE admin_notes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    alert_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NULL,        -- nullable : note conservée si admin supprimé
    content    TEXT            NOT NULL,
    created_at TIMESTAMP       NULL,
    updated_at TIMESTAMP       NULL,

    FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE SET NULL
);
```

**Notes :**
- **Optionnelles** — ne jamais les rendre obligatoires (règle d'or #10 du CONTEXT.md).
- Plusieurs notes possibles par alerte (suivi progressif).
- `ON DELETE SET NULL` sur `user_id` : si l'admin est supprimé, la note est conservée avec `user_id = NULL`.

---

## 4. Relations Eloquent (résumé)

| Modèle | Relations |
|---|---|
| `School` | `hasMany(Child)` · `hasMany(ChatSession)` · `hasMany(Alert)` · `belongsToMany(User)` via `school_user` · `hasOne(SchoolSetting)` |
| `User` | `belongsToMany(School)` avec pivot `role` · `hasMany(AdminNote)` |
| `Child` | `belongsTo(School)` · `hasMany(Session)` · `hasMany(Alert)` |
| `ChatSession` | `belongsTo(Child)` · `belongsTo(School)` · `hasOne(Alert)` |
| `Alert` | `belongsTo(ChatSession)` · `belongsTo(Child)` · `belongsTo(School)` · `hasMany(AdminNote)` |
| `AdminNote` | `belongsTo(Alert)` · `belongsTo(User)` |

---

## 5. Logique Métier Liée au Schéma

### 5.1 Calcul `score_enfant`

Déclenché par `ProcessSessionClosure` job à chaque `session.ended_at` :

```
score_enfant = AVG(zone_score) sur les sessions des 7 derniers jours
  où zone_score : green=100, yellow=70, orange=35, red=0
  où sessions.ended_at IS NOT NULL (sessions clôturées uniquement)
```

Si aucune session sur 7 jours → `score_enfant = NULL` (ignoré du calcul école).

### 5.2 Mise à jour `children.status`

Dans le même job, après calcul `score_enfant` :
```
if score_enfant < 50 → status = 'a_suivre'
else                 → status = 'ok'
if score_enfant IS NULL → status inchangé
```

### 5.3 Calcul Score Climat École

Calculé à la demande (pas de cache) :
```
score_climat = AVG(score_enfant) 
  pour tous les children WHERE school_id = ? AND score_enfant IS NOT NULL
```

### 5.4 Clôture de Session

Les deux mécanismes déclenchent le même flow :
1. `session.ended_at = NOW()`
2. Appel API Claude → génère `ai_summary` + parse `ZONE: xxx`
3. `session.zone` + `session.low_confidence` mis à jour
4. Si zone `orange` ou `red` → créer enregistrement `alerts`
5. Si zone `red` → envoyer email/push, renseigner `alerts.notified_at`
6. Dispatch `ProcessSessionClosure` job → recalcule `score_enfant` + `status`

---

## 6. Index de Performance

```sql
-- Calcul score enfant (7 jours glissants)
INDEX (child_id, ended_at)   -- sur chat_sessions

-- Dashboard école : alertes non traitées
INDEX (school_id, status)    -- sur alerts
INDEX (school_id, ended_at)  -- sur chat_sessions

-- Tri children par score / statut
INDEX (school_id, status)    -- sur children
INDEX (school_id, score_enfant) -- sur children

-- Historique alertes par enfant
INDEX (child_id, created_at) -- sur alerts
```

---

## 7. Conformité RGPD / Loi 09-08

| Exigence | Implémentation |
|---|---|
| Pas de messages bruts | Aucune table `messages`. Seul `sessions.ai_summary` (résumé IA) |
| Minimisation des données | `children` : nom, email, âge, classe uniquement. Pas de données biométriques |
| Droit à l'effacement | `ON DELETE CASCADE` sur `children` → supprime sessions, alertes, notes associées |
| Accès restreint | Guards séparés (`web` / `child`). Rôles admin via `school_user.role` |
| Traçabilité des accès | `admin_notes.user_id` identifie l'admin auteur de chaque action |

---

## 8. Ordre des Migrations Laravel

```
1. create_schools_table
2. create_users_table              (Breeze — déjà généré)
3. create_school_user_table
4. create_school_settings_table
5. create_children_table
6. create_chat_sessions_table
7. create_alerts_table
8. create_admin_notes_table
```

> ⚠️ La table est nommée `chat_sessions` (pas `sessions`) pour éviter tout conflit avec la table native Laravel de stockage des sessions HTTP. Le modèle Eloquent correspondant s'appelle `ChatSession`.

---

## 9. Décisions Différées (Post-MVP)

| Sujet | Décision prise | Évolution possible |
|---|---|---|
| Cache scores | Pas de cache pour MVP | Table `child_daily_scores` si > 500 enfants/école |
| Historique climat | Pas de snapshot | Table `school_climate_snapshots` pour courbes historiques |
| Multi-langue enfant | Langue école uniquement | Colonne `language` sur `children` |
| Soft deletes | Pas de soft delete MVP | `deleted_at` sur `children` et `alerts` |
