# Compléments — Zones of Regulation & règles produit

> Document de référence validé avec le chef de projet. À lire en complément de la spec technique.

## 1. Score Climat — Pondération

- Vert = **100**, Jaune = **75** (ou 70 selon skill), Orange = **35**, Rouge = **0**
- Calculé sur **7 derniers jours glissants** (pas uniquement la journée — trop volatile)
- Sessions sans signal émotionnel clair → **Vert par défaut** avec `low_confidence = true`

## 2. Fin de session

**Les deux mécanismes ensemble** :
- Bouton discret "J'ai fini" (texte doux : "Bonne journée 👋")
- Fermeture automatique après **10 minutes d'inactivité**

Le résumé admin est généré **à la fermeture**, quelle que soit la cause.

## 3. Alertes & notifications

- **Push / email** : uniquement signaux **critiques (rouge)**
- Signaux modérés (stress, examens) : dashboard seulement, pas de notif intrusive
- Raison : éviter la saturation admin → ils finiraient par tout ignorer

## 4. Note interne admin

- **Optionnelle, pas obligatoire**
- Obliger une note = friction = admins évitent de traiter
- Placeholder encourageant : "Optionnel : notez vos actions..."

---

## Les Zones of Regulation — Framework

Créé par **Leah Kuypers** (chercheuse américaine). 4 couleurs comme un thermomètre émotionnel.
**Outil pédagogique, pas médical.**

### 🟢 Vert — Tout va bien
Enfant calme, heureux, concentré. Aucune intervention nécessaire.
*Signaux chat* : parle positivement, rigole, "tout va bien".

### 🟡 Jaune — Attention légère
Émotion modérée, gère encore. Stress examen, dispute, fatigue, excitation.
*Exemples* : "j'ai peur du contrôle demain", "je suis fatigué".
*Action IA* : rassure, propose respiration, continue la conversation.

### 🟠 Orange — Préoccupant
Tristesse répétée, anxiété persistante, isolement, mal-être qui dure.
*Exemples* : "je me sens toujours seul", "personne ne m'aime".
*Action IA* : empathie forte, approfondir doucement.

### 🔴 Rouge — Critique, alerte immédiate
Harcèlement, pensées négatives, danger, détresse grave.
*Exemples* : "ils me tapent tous les jours", "je voudrais disparaître".
*Action IA* : parler doux MAIS **déclencher alerte silencieuse serveur immédiatement**.

---

## Implémentation — Récupération de zone

Le prompt système doit finir par :

```
À la fin de ta conversation, retourne uniquement sur la dernière ligne :
ZONE: green | yellow | orange | red
```

Le backend parse cette ligne, la retire du message affiché, et stocke en BDD dans `sessions.zone`.

### Flag `low_confidence`

Si aucune émotion identifiable (réponses type "oui", "ok", "je sais pas") :

```
zone = green
low_confidence = true
```

- Non visible côté enfant
- Permet de ne pas surévaluer le bien-être global
- Ouverture future : pondération, relance

---

## Calcul score climat — Formule

### Valeurs par zone

| Zone | Points |
|---|---|
| 🟢 Vert | 100 |
| 🟡 Jaune | 70 |
| 🟠 Orange | 35 |
| 🔴 Rouge | 0 |

### Formule 2 étapes (évite biais volume)

**Étape 1** — Score par enfant sur 7 jours glissants :
```
score_enfant = moyenne des points de toutes ses sessions
```

**Étape 2** — Score école :
```
Score Climat = moyenne de tous les score_enfant actifs
```

### Règles

- Enfant sans session sur 7 jours → **ignoré** du calcul (le silence n'est pas fiable)
- Session avec alerte rouge → `zone = red` automatiquement (priorité absolue)
- Session sans signal clair → `zone = green + low_confidence = true`
- Recalcul **à chaque fin de session**, pas en temps réel

### Statut "enfant à suivre"

```
if (score_enfant < 50) {
    status = "à suivre"
}
```

- Visible dans dashboard admin
- Permet de prioriser les actions
- Recalculé à chaque update du score

### Exemple chiffré

- **Yassine** : Orange(35) + Jaune(70) + Orange(35) → moyenne **47** → *à suivre*
- **Sara** : Vert(100) → **100** → OK
- **Karim** : Jaune(70) + Vert(100) → **85** → OK

**Score école** = (47 + 100 + 85) / 3 = **77/100**
