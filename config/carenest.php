<?php

return [

    /*
     * Phase pilote (hp-v2nf, décision du 2026-10-07) : le parent qui a donné un
     * consentement actif est prévenu de toute alerte qui prévient le référent, et
     * consulte son résumé dans son espace. « Après le pilote on va décider » : mettre
     * CARENEST_PARENT_ALERTS=false coupe toute notification ET l'écran d'alerte parent.
     */
    'parent_alerts' => (bool) env('CARENEST_PARENT_ALERTS', true),

    /*
     * E-002 (test d'intrusion du 2026-10-22) : mode de la politique de sécurité du contenu.
     * « report-only » observe et journalise (storage/logs/csp-violations.log), « enforce »
     * bloque, « off » n'envoie rien. Bascule en « enforce » une fois le parcours de toutes les
     * pages fait sans violation.
     */
    'csp_mode' => env('CSP_MODE', 'report-only'),

];
