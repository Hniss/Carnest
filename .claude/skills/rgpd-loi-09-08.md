---
name: rgpd-loi-09-08
description: Conformité loi marocaine 09-08 (CNDP) et RGPD pour CareNest. À lire avant tout code qui touche au stockage, à l'affichage ou à la transmission de données d'enfants.
---

# Conformité RGPD + Loi 09-08 — CareNest

## Contexte juridique

- **Loi 09-08 (Maroc)** : protection des données à caractère personnel. Autorité : CNDP.
- **RGPD (UE)** : applicable si un utilisateur UE, ou si on vise une expansion.
- **Public sensible** : mineurs → exigences renforcées (consentement parental explicite).

## Principes NON NÉGOCIABLES

### 1. Pas de message brut en BDD

**INTERDIT** :
```php
// ❌ JAMAIS FAIRE CECI
$table->text('user_message');
$table->longText('conversation_log');
```

**AUTORISÉ** :
```php
// ✅ Uniquement résumés + métadonnées
$table->text('summary')->nullable();          // résumé IA court
$table->enum('zone', [...]);                  // classification
$table->integer('message_count')->default(0); // juste un compteur
```

### 2. Chiffrement au repos

Tous les champs sensibles (résumés, notes admin, noms d'enfants si possible) :

```php
// Dans le modèle
protected $casts = [
    'summary' => 'encrypted',
    'admin_notes' => 'encrypted',
];
```

### 3. Droit à l'oubli (RGPD Art. 17 / 09-08 Art. 9)

**Toutes les FK** vers `children` doivent cascade :

```php
$table->foreignId('child_id')->constrained()->cascadeOnDelete();
```

Endpoint de suppression à prévoir :
```
DELETE /api/admin/children/{id}
```
→ Déclenche `Child::delete()` qui cascade sur sessions, alerts, notes.

### 4. Minimisation des données

Ne collecter que le strict nécessaire :
- ✅ Prénom (pas le nom de famille)
- ✅ Classe + âge (pas la date de naissance précise)
- ✅ École (pour rattachement)
- ❌ Pas d'adresse, pas de numéro de téléphone enfant
- ❌ Pas de données de santé

### 5. Audit trail obligatoire

Chaque accès admin à une alerte doit être loggé :

```php
// Table audit_logs
Schema::create('audit_logs', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained();  // admin
    $t->string('action');                     // 'viewed_alert', 'resolved_alert'
    $t->string('target_type');                // Alert
    $t->unsignedBigInteger('target_id');
    $t->ipAddress('ip');
    $t->timestamps();
});
```

### 6. Pas de PII dans les logs

**INTERDIT** :
```php
Log::info('User said: ' . $message);  // ❌
Log::info($request->all());           // ❌ peut contenir des messages
```

**AUTORISÉ** :
```php
Log::info('Session closed', ['session_id' => $session->id, 'zone' => $zone->value]);
```

### 7. Consentement parental

- Création de compte enfant **uniquement par l'admin école** (qui a recueilli le consentement parental hors plateforme).
- Mention légale visible dans l'UI admin : "Vous déclarez avoir obtenu le consentement écrit du parent pour cet enfant."

## Policies Laravel obligatoires

```php
// app/Policies/ChildPolicy.php
class ChildPolicy
{
    public function view(User $user, Child $child): bool
    {
        return $user->school_id === $child->school_id;
    }

    public function delete(User $user, Child $child): bool
    {
        return $user->school_id === $child->school_id
            && $user->hasRole('admin');
    }
}
```

Enregistrer dans `AuthServiceProvider`. Appeler dans controllers :
```php
$this->authorize('view', $child);
```

## Mentions à afficher dans l'UI admin

Dans tout modal d'alerte :

> 🔒 Le contenu exact des messages de l'enfant n'est pas accessible — conformité loi 09-08 / RGPD

## Export CNDP (si demandé)

Prévoir une commande artisan :

```php
php artisan carenest:export-child-data {child_id}
```

→ Génère un JSON avec **toutes** les données non chiffrées de l'enfant (Art. 15 RGPD).

## Checklist avant déploiement prod

- [ ] Déclaration CNDP déposée
- [ ] Mentions légales + politique de confidentialité rédigées
- [ ] Formulaire de consentement parental (hors app) validé par juriste
- [ ] DPA (Data Processing Agreement) signé avec Anthropic
- [ ] Backups chiffrés
- [ ] Procédure de notification de fuite (< 72h RGPD)
