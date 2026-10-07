<?php

return [

    /*
     * Phase pilote (hp-v2nf, décision du 2026-10-07) : le parent qui a donné un
     * consentement actif est prévenu de toute alerte qui prévient le référent, et
     * consulte son résumé dans son espace. « Après le pilote on va décider » : mettre
     * CARENEST_PARENT_ALERTS=false coupe toute notification ET l'écran d'alerte parent.
     */
    'parent_alerts' => (bool) env('CARENEST_PARENT_ALERTS', true),

];
