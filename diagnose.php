<?php
/**
 * CareNest — setup automatique + diagnostic.
 *
 * Usage (testeur) :
 *   git pull
 *   php diagnose.php
 *
 * Le script DÉTECTE et CORRIGE tout seul :
 *   - vendor/      manquant → composer install
 *   - node_modules manquant → npm install
 *   - public/build manquant → npm run build
 *   - .env         manquant → copie depuis .env.example
 *   - APP_KEY      vide    → php artisan key:generate
 *   - database/database.sqlite manquant → créé
 *   - DB sans tables → php artisan migrate:fresh --seed
 *   - cache Laravel figé → config:clear + cache:clear
 *   - AI_PROVIDER → mis à 'openai' pour la session de test
 *
 * Seule action manuelle : coller la clé OpenAI quand le script la demande.
 * (Demande la clé à Hamza par WhatsApp, elle commence par "sk-proj-".)
 *
 * Flags :
 *   --no-interactive   ne demande pas la clé OpenAI au prompt
 *   --no-npm           saute l'install/build JS (utile si Node n'est pas dispo)
 *   --no-composer      saute composer install (utile si vendor déjà OK)
 */

declare(strict_types=1);

$root = __DIR__;
chdir($root);

$args = array_slice($argv, 1);
$noInteractive = in_array('--no-interactive', $args, true);
$skipNpm       = in_array('--no-npm', $args, true);
$skipComposer  = in_array('--no-composer', $args, true);

$fixed  = [];
$warned = [];
$failed = [];

function out(string $kind, string $msg): void {
    $prefix = match ($kind) {
        'OK'   => '[OK]   ',
        'FIX'  => '[FIX]  ',
        'WARN' => '[WARN] ',
        'FAIL' => '[FAIL] ',
        'STEP' => "\n--- ",
        default => '       ',
    };
    echo $prefix . $msg . PHP_EOL;
}

function run(string $cmd): int {
    out('INFO', "$ $cmd");
    passthru($cmd, $code);
    return (int) $code;
}

function envGet(string $envPath, string $key): ?string {
    if (! file_exists($envPath)) return null;
    foreach (file($envPath) as $l) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)$/', $l, $m)) {
            return trim($m[1], "\"' \t\r\n");
        }
    }
    return null;
}

function envSet(string $envPath, string $key, string $value): void {
    $lines = file($envPath);
    $found = false;
    foreach ($lines as $i => $l) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $l)) {
            $lines[$i] = "$key=$value" . PHP_EOL;
            $found = true;
            break;
        }
    }
    if (! $found) $lines[] = "$key=$value" . PHP_EOL;
    file_put_contents($envPath, implode('', $lines));
}

echo "=== CareNest — setup automatique ===" . PHP_EOL;
echo "    " . date('Y-m-d H:i:s') . PHP_EOL;

// =============================================================================
// 1/6 — PHP version + extensions
// =============================================================================
out('STEP', '1/6 — PHP');
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    out('FAIL', 'PHP ' . PHP_VERSION . ' < 8.3. Installe PHP 8.3+ puis relance.');
    out('INFO', 'Windows : https://windows.php.net/download/');
    out('INFO', 'Mac     : brew install php@8.3');
    exit(1);
}
out('OK', 'PHP ' . PHP_VERSION);

$required = ['mbstring','openssl','pdo','pdo_sqlite','tokenizer','xml','curl','fileinfo','sqlite3'];
$missing = array_filter($required, fn($ext) => ! extension_loaded($ext));
if ($missing) {
    out('FAIL', 'Extensions PHP manquantes : ' . implode(', ', $missing));
    out('INFO', 'Active-les dans php.ini (retire le ";" devant la ligne extension=xxx) puis relance.');
    exit(1);
}
out('OK', 'Toutes extensions requises présentes.');

// =============================================================================
// 2/6 — composer / vendor
// =============================================================================
out('STEP', '2/6 — Dépendances PHP');
if (! file_exists($root . '/vendor/autoload.php')) {
    if ($skipComposer) {
        out('FAIL', 'vendor/ manquant et --no-composer passé. Lance : composer install');
        exit(1);
    }
    out('FIX', 'vendor/ manquant → composer install (1-2 minutes)');
    $r = run('composer install --no-interaction --prefer-dist');
    if ($r !== 0) {
        out('FAIL', "composer install a échoué (exit $r).");
        out('INFO', 'Composer pas installé ? → https://getcomposer.org/download/');
        exit(1);
    }
    $fixed[] = 'composer install';
}
out('OK', 'vendor/ présent.');

// =============================================================================
// 3/6 — npm + assets
// =============================================================================
out('STEP', '3/6 — Dépendances JS + build assets');
if ($skipNpm) {
    out('WARN', '--no-npm passé : on saute. Le CSS/JS peuvent manquer.');
    $warned[] = 'assets JS pas construits (--no-npm)';
} else {
    if (! is_dir($root . '/node_modules')) {
        out('FIX', 'node_modules/ manquant → npm install');
        $r = run('npm install');
        if ($r !== 0) {
            out('FAIL', "npm install a échoué (exit $r).");
            out('INFO', 'Node.js pas installé ? → https://nodejs.org (LTS)');
            exit(1);
        }
        $fixed[] = 'npm install';
    }
    if (! is_dir($root . '/public/build')) {
        out('FIX', 'public/build/ manquant → npm run build');
        $r = run('npm run build');
        if ($r !== 0) {
            out('FAIL', "npm run build a échoué (exit $r).");
            exit(1);
        }
        $fixed[] = 'npm run build';
    }
    out('OK', 'Assets construits.');
}

// =============================================================================
// 4/6 — .env + APP_KEY + AI_PROVIDER
// =============================================================================
out('STEP', '4/6 — Configuration .env');
$envPath = $root . '/.env';
if (! file_exists($envPath)) {
    if (! file_exists($root . '/.env.example')) {
        out('FAIL', '.env.example absent. Repo cassé ?');
        exit(1);
    }
    copy($root . '/.env.example', $envPath);
    out('FIX', '.env créé depuis .env.example');
    $fixed[] = '.env créé';
}

if (empty(envGet($envPath, 'APP_KEY'))) {
    run('php artisan key:generate --force --ansi');
    $fixed[] = 'APP_KEY généré';
}

if (envGet($envPath, 'AI_PROVIDER') !== 'openai') {
    envSet($envPath, 'AI_PROVIDER', 'openai');
    out('FIX', "AI_PROVIDER mis à 'openai' (session de test).");
    $fixed[] = 'AI_PROVIDER=openai';
}
out('OK', '.env prêt.');

// =============================================================================
// 5/6 — Base de données SQLite
// =============================================================================
out('STEP', '5/6 — Base de données');
$dbPath = $root . '/database/database.sqlite';
if (! file_exists($dbPath)) {
    @mkdir(dirname($dbPath), 0775, true);
    touch($dbPath);
    out('FIX', 'database/database.sqlite créé');
    $fixed[] = 'fichier SQLite créé';
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $stmt = $pdo->query('SELECT name FROM sqlite_master WHERE type="table"');
    $tables = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
} catch (\Throwable $e) {
    $tables = [];
}

$needsMigrate = ! in_array('children', $tables, true);
$needsSeed    = false;
if (! $needsMigrate) {
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn();
    if ($cnt === 0) $needsSeed = true;
}

if ($needsMigrate) {
    out('FIX', 'DB vide → migrate:fresh --seed');
    $r = run('php artisan migrate:fresh --seed --force --ansi');
    if ($r !== 0) {
        out('FAIL', "migrate:fresh --seed a échoué (exit $r).");
        $failed[] = 'migrations';
    } else {
        $fixed[] = 'migrations + seeders';
    }
} elseif ($needsSeed) {
    out('FIX', 'Tables présentes mais vides → db:seed');
    $r = run('php artisan db:seed --force --ansi');
    if ($r !== 0) {
        out('FAIL', "db:seed a échoué (exit $r).");
        $failed[] = 'seeders';
    } else {
        $fixed[] = 'seeders';
    }
} else {
    out('OK', 'DB déjà peuplée (' . count($tables) . ' tables, enfants présents).');
}

// =============================================================================
// 6/6 — Cache + clé OpenAI + ping
// =============================================================================
out('STEP', '6/6 — Cache Laravel + clé OpenAI');
run('php artisan config:clear --ansi');
run('php artisan cache:clear --ansi');
out('OK', 'Caches purgés.');

$openaiKey = envGet($envPath, 'OPENAI_API_KEY');
if (empty($openaiKey) || ! str_starts_with($openaiKey, 'sk-')) {
    echo PHP_EOL;
    out('WARN', 'OPENAI_API_KEY est vide ou invalide dans .env.');
    if ($noInteractive) {
        out('INFO', 'Ouvre .env, ligne OPENAI_API_KEY=, et colle une clé qui commence par "sk-proj-".');
        $warned[] = 'OPENAI_API_KEY à coller manuellement';
    } else {
        echo PHP_EOL;
        echo "  Demande la clé OpenAI à Hamza par WhatsApp." . PHP_EOL;
        echo "  Elle commence par \"sk-proj-...\"." . PHP_EOL;
        echo "  Colle-la ci-dessous puis Entrée (Entrée vide pour ignorer) :" . PHP_EOL;
        echo "  > ";
        $input = trim((string) fgets(STDIN));
        if (str_starts_with($input, 'sk-')) {
            envSet($envPath, 'OPENAI_API_KEY', $input);
            run('php artisan config:clear --ansi');
            out('FIX', 'Clé OpenAI enregistrée.');
            $fixed[] = 'OPENAI_API_KEY';
            $openaiKey = $input;
        } else {
            $warned[] = 'OPENAI_API_KEY pas saisie';
        }
    }
}

// Ping OpenAI via l'AIService réel
$aiOK = false;
if (! empty($openaiKey) && str_starts_with($openaiKey, 'sk-')) {
    out('INFO', 'Test live de l\'API OpenAI via AIService...');
    try {
        require $root . '/vendor/autoload.php';
        $app = require_once $root . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $ai = app(\App\Services\AIService::class);
        $r = $ai->chat([['role' => 'user', 'content' => 'ping']], 10, 'm');
        $aiOK = true;
        out('OK', 'AIService = ' . get_class($ai) . ' / réponse zone=' . $r['zone']);
    } catch (\Throwable $e) {
        out('FAIL', 'API IA injoignable : ' . $e->getMessage());
        $failed[] = 'API IA injoignable (clé invalide ou réseau bloqué ?)';
    }
}

// =============================================================================
// Résumé final
// =============================================================================
echo PHP_EOL . "===================================" . PHP_EOL;
echo "  Résumé" . PHP_EOL;
echo "===================================" . PHP_EOL;

if ($fixed) {
    echo PHP_EOL . "Corrections appliquées (" . count($fixed) . ") :" . PHP_EOL;
    foreach ($fixed as $f) echo "  + $f" . PHP_EOL;
}
if ($warned) {
    echo PHP_EOL . "Action manuelle requise (" . count($warned) . ") :" . PHP_EOL;
    foreach ($warned as $w) echo "  ! $w" . PHP_EOL;
}
if ($failed) {
    echo PHP_EOL . "Echecs (" . count($failed) . ") :" . PHP_EOL;
    foreach ($failed as $f) echo "  X $f" . PHP_EOL;
}

if (empty($warned) && empty($failed) && $aiOK) {
    echo PHP_EOL . "*** TOUT EST PRET. ***" . PHP_EOL . PHP_EOL;
    echo "Etape suivante :" . PHP_EOL . PHP_EOL;
    echo "  1) Lance le serveur (laisse cette fenetre OUVERTE) :" . PHP_EOL;
    echo "     php artisan serve" . PHP_EOL . PHP_EOL;
    echo "  2) Ouvre dans Chrome ou Edge :" . PHP_EOL;
    echo "     http://127.0.0.1:8000/child/login" . PHP_EOL . PHP_EOL;
    echo "  Comptes de test :" . PHP_EOL;
    echo "    Enfant (10 ans) : yassine@carenest.ma / demo123" . PHP_EOL;
    echo "    Admin (ecole)   : admin@carenest.ma   / admin123" . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "Resous les points ci-dessus, puis relance : php diagnose.php" . PHP_EOL;
exit(1);
