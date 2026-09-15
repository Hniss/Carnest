<?php

namespace Tests\Feature\Seeders;

use App\Models\Child;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * D10 (MVP v3) — mots de passe de démonstration hors dépôt.
 */
class DemoPasswordsTest extends TestCase
{
    use RefreshDatabase;

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_seeder_uses_env_passwords_when_provided(): void
    {
        $this->setEnv('DEMO_ADMIN_PASSWORD', 'AdminTestPwd!2026');
        $this->setEnv('DEMO_CHILD_PASSWORD', 'ChildTestPwd!2026');

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->setEnv('DEMO_ADMIN_PASSWORD', null);
            $this->setEnv('DEMO_CHILD_PASSWORD', null);
        }

        $this->assertTrue(Hash::check('AdminTestPwd!2026', User::where('email', 'admin@carenest.ma')->first()->password));
        $this->assertTrue(Hash::check('ChildTestPwd!2026', Child::where('email', 'yassine@carenest.ma')->first()->password));
    }

    public function test_seeder_never_falls_back_to_hardcoded_passwords(): void
    {
        $this->setEnv('DEMO_ADMIN_PASSWORD', null);
        $this->setEnv('DEMO_CHILD_PASSWORD', null);

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(Hash::check('admin123', User::where('email', 'admin@carenest.ma')->first()->password));
        $this->assertFalse(Hash::check('demo123', Child::where('email', 'yassine@carenest.ma')->first()->password));
    }
}
