<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1 (audit sécurité du 2026-10-05) — le script d'installation à la racine du dépôt ne
 * s'exécute qu'en ligne de commande, et les données de démonstration ne s'installent
 * jamais en production sans demande explicite.
 */
class ExposedScriptsAndSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_script_stops_with_404_outside_command_line_before_any_action(): void
    {
        $source = file_get_contents(base_path('diagnose.php'));
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $code = implode('', array_map(fn ($t) => is_array($t) ? $t[1] : $t, $tokens));

        $this->assertMatchesRegularExpression(
            '/^declare\(strict_types=1\);if\(PHP_SAPI!==\'cli\'\)\{http_response_code\(404\);exit;\}/',
            preg_replace('/\s+/', '', $code),
            'La garde « ligne de commande uniquement » doit être la toute première instruction après declare().'
        );
    }

    public function test_ui_mockup_only_carries_obviously_fictitious_demo_accounts(): void
    {
        $mockup = file_get_contents(base_path('docs/ui-mockup.jsx'));

        preg_match_all('/email:\s*"([^"]+)",\s*password:/', $mockup, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $email) {
            $this->assertStringEndsWith('@exemple.test', $email);
        }
        $this->assertDoesNotMatchRegularExpression('/[\w.]+@carenest\.ma\s*\/\s*\S+/', $mockup);
    }

    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
            $this->fail('Le seeder de démonstration a tourné en production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('production', $e->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_seeder_runs_in_production_only_with_explicit_option(): void
    {
        $this->app['env'] = 'production';
        putenv('CARENEST_ALLOW_DEMO_SEED=true');
        $_ENV['CARENEST_ALLOW_DEMO_SEED'] = 'true';
        $_SERVER['CARENEST_ALLOW_DEMO_SEED'] = 'true';

        try {
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        } finally {
            putenv('CARENEST_ALLOW_DEMO_SEED');
            unset($_ENV['CARENEST_ALLOW_DEMO_SEED'], $_SERVER['CARENEST_ALLOW_DEMO_SEED']);
        }

        $this->assertGreaterThan(0, User::count());
    }
}
