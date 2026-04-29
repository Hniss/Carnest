# CareNest MVP — Brainstorming BDD (Claude × Hamza)

> Traçabilité des décisions de conception du schéma base de données.
> Session : 2026-04-16

---

## Décisions prises

### 1. Architecture multi-école ✅
**Choix :** Multi-école (option B)
**Raison :** Facilite l'upgrade post-validation MVP. Une seule BDD avec table `schools` centrale. Toutes les entités lui sont rattachées via `school_id`.

---

### 2. Authentification enfant ✅
**Choix :** Email + mot de passe (standard Laravel)
**Raison :** Le parent configure le compte de l'enfant. Tout enfant (même 5 ans) a un email géré par le parent. Table `children` séparée de `users` avec son propre guard Laravel.

---

### 3. Rôles admin par école ✅
**Choix :** Plusieurs admins par école avec rôles distincts (option C)
**Implémentation :** Table pivot `school_user` avec colonne `role` (enum : `director`, `counselor`, `teacher`, `staff`).

---

### 4. Types d'alertes ✅
**Choix :** Enum fixe (option A)
**Valeurs retenues :** `harcelement`, `detresse`, `stress`, `tristesse`, `danger`, `isolement`
**Raison :** Pas de table supplémentaire, géré en code. Plus simple pour le MVP.

---

### 5. Stratégie de calcul des scores ✅
**Choix :** Option A (schéma minimal) + colonne `score_enfant` dénormalisée sur `children`
**Raison :** Score calculé à la volée via Eloquent sur les 7 derniers jours, mais stocké sur `children` pour performance dashboard. Recalculé à chaque fin de session via `ProcessSessionClosure` job.
**Pas de table de cache** pour le MVP.

---

## Tables retenues (à valider)

| Table | Rôle |
|---|---|
| `schools` | Écoles clientes |
| `users` | Admins (via Laravel Breeze) |
| `school_user` | Pivot admin ↔ école + rôle |
| `children` | Enfants (auth séparée) |
| `sessions` | Sessions chat (résumé IA + zone) |
| `alerts` | Alertes générées par session |
| `admin_notes` | Notes optionnelles admin sur alertes |
| `school_settings` | Config par école |

---

## Règles d'or BDD (non négociables)

1. **Aucun message brut** d'enfant stocké — seulement `ai_summary` + `zone`
2. `low_confidence = true` si zone green sans émotion claire
3. `score_enfant` recalculé à chaque `session.ended_at`
4. `status = 'a_suivre'` auto si `score_enfant < 50`
5. Alertes rouge → push/email · Alertes orange → dashboard seulement
6. Score calculé uniquement sur enfants avec ≥ 1 session dans les 7 derniers jours
