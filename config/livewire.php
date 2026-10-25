<?php

/*
 * Seules les clés qui diffèrent des valeurs par défaut de Livewire figurent ici : le reste
 * est fourni par vendor/livewire/livewire/config/livewire.php (fusion au démarrage).
 */
return [

    /*
     * E-001 (test d'intrusion du 2026-10-22) — jeton de version des instantanés. Livewire le fige
     * à « a » : un instantané restait valable d'un déploiement à l'autre. Il suit désormais le
     * déploiement : LIVEWIRE_RELEASE (posé par le script de mise en ligne, hash de commit), sinon
     * l'empreinte du manifeste des ressources compilées (change à chaque compilation), sinon « dev ».
     */
    'release_token' => env('LIVEWIRE_RELEASE') ?: (
        is_file(__DIR__.'/../public/build/manifest.json')
            ? substr(hash_file('sha256', __DIR__.'/../public/build/manifest.json'), 0, 12)
            : 'dev'
    ),

];
