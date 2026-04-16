---
description: Scaffold une nouvelle feature dans CareNest avec le workflow multi-agents (architect → backend/frontend → qa-reviewer)
---

# /new-feature <nom de la feature>

Lance le workflow complet pour une nouvelle feature :

## Étape 1 — Planification (architect)
Invoquer l'agent `architect` :
1. Lire `CONTEXT.md`
2. Identifier les tables BDD, endpoints, règles métier impactés
3. Produire une spec dans `docs/architecture/<YYYY-MM-DD>-<slug>.md`
4. Valider la conformité RGPD (invoquer skill `rgpd-loi-09-08`)

## Étape 2 — Attendre validation utilisateur
**Ne pas coder avant approbation.**

## Étape 3 — Développement parallèle
Une fois validé :
- Agent `backend-dev` : migrations, modèles, services, jobs
- Agent `frontend-dev` : composants Livewire, vues

## Étape 4 — Revue (qa-reviewer)
- Checklist RGPD
- Tests passent
- Conventions respectées

## Étape 5 — Mise à jour
- Cocher dans `CONTEXT.md` section "État actuel"
- Commit avec message descriptif

---

**Usage** : tape `/new-feature chat-enfant` ou `/new-feature dashboard-alertes`
