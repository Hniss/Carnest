# CLAUDE.md — Instructions Claude Code (projet CareNest)

## 🎯 Règle #1 — Toujours lire CONTEXT.md

Avant **toute action** (même une question), lis `CONTEXT.md` à la racine. Il contient la vision, la stack, les règles d'or, et la liste des agents/skills disponibles.

## 🧠 Règle #2 — Approche multi-agents

Ce projet utilise une architecture à 3 agents spécialisés :
- `architect` → décisions structurelles
- `backend-dev` → Laravel / API Claude / logique métier
- `frontend-dev` → Livewire / UI / UX enfant

Pour toute tâche non triviale :
1. Identifier l'agent compétent (ou plusieurs).
2. Utiliser le tool `Agent` avec le bon `subagent_type` OU lire `.claude/agents/<nom>.md` pour appliquer ses consignes.
3. Si plusieurs agents sont nécessaires, les coordonner en parallèle quand possible.

## 📚 Règle #3 — Skills avant action

Avant d'écrire du code touchant :
- une classification émotionnelle → lire `skills/zones-of-regulation.md`
- un calcul de score → lire `skills/climate-score-calc.md`
- un appel à l'API Claude → lire `skills/claude-api-integration.md`
- du stockage de données enfants → lire `skills/rgpd-loi-09-08.md`
- un composant Livewire → lire `skills/livewire-components.md`
- une UI enfant → lire `skills/child-ui-ux.md`

## 🔒 Règle #4 — Conformité avant tout

**Jamais** :
- stocker un message brut d'enfant en BDD
- exposer un endpoint qui retourne le contenu d'une conversation
- logger les messages enfants dans les fichiers de log
- envoyer les messages enfants à un service tiers autre que l'API Anthropic (et encore, en transit uniquement)

**Toujours** :
- stocker uniquement : `summary` (résumé IA court), `zone`, `low_confidence`, `alert_type`, timestamps
- chiffrer les données sensibles au repos
- logger les accès admin aux alertes (audit trail)

## 🗣️ Règle #5 — Communication

- Réponses concises, en français.
- Pas de commentaires inutiles dans le code.
- Pour chaque feature implémentée : expliquer quel agent a été utilisé et pourquoi.

## 🛠️ Règle #6 — Stack imposée pour le MVP

- **Laravel 11** + **Livewire 3** + **MySQL 8** + **Tailwind 3**.
- **Ne pas** proposer Vue/React/Next pour le MVP.
- **Ne pas** ajouter de dépendances sans validation.

## ✅ Règle #7 — Definition of Done

Une feature est terminée quand :
- [ ] Code écrit + tests de base passent (`php artisan test`)
- [ ] Migrations + seeders prêts
- [ ] Agent `qa-reviewer` a relu
- [ ] CONTEXT.md section "État actuel" mise à jour
