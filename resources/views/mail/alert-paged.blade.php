Bonjour,

Une alerte attend votre accusé dans CareNest.
@if ($step > 0)
Il s'agit d'un rappel : cette alerte n'a pas encore été prise en connaissance.
@endif

Connectez-vous à votre espace référent pour la consulter : {{ url('/dashboard-referent/alertes/' . $alertId) }}

Ce message ne contient volontairement aucune information sur l'élève concerné.

CareNest
