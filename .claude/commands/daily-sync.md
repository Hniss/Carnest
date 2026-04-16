---
description: Produit un résumé quotidien de l'état du projet CareNest
---

# /daily-sync

Parcours le projet et produis un rapport court :

1. **État de CONTEXT.md** — quelles cases sont cochées, quelles restent
2. **Git** — commits du jour (si repo init), branches ouvertes
3. **Tests** — `php artisan test` : combien passent / échouent
4. **Migrations pending** — `php artisan migrate:status`
5. **TODO du code** — `grep -r "TODO\|FIXME\|HACK" app/`
6. **Prochaine action recommandée** (1 phrase)

Format court, bullet points, 15-20 lignes max.
