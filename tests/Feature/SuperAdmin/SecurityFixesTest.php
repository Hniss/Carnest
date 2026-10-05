<?php

namespace Tests\Feature\SuperAdmin;

use App\Livewire\SuperAdmin\MailSettings as MailPage;
use App\Livewire\SuperAdmin\SchoolShow;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Correctifs de l'audit hy-securite du 2026-10-05 sur l'espace super-admin.
 *  - CWE-269 : le super-admin ne peut pas changer l'e-mail d'un admin d'école (sinon il
 *    prendrait le compte par « mot de passe oublié » et verrait les données des enfants).
 *  - CWE-918 : la boîte d'envoi refuse les IP littérales, les noms locaux et les ports hors
 *    25 / 465 / 587 / 2525.
 *  - Fichier des mots de passe super-admin créé en 600 AVANT écriture ; échec = aucun compte.
 */
class SecurityFixesTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    public function test_superadmin_cannot_change_the_email_of_an_existing_school_admin(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school, ['email' => 'directrice@ecole.test']);

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->call('editAdmin', $admin->id)
            ->set('adminName', 'Nom Corrigé')
            ->set('adminEmail', 'attaquant@exemple.test')
            ->call('updateAdmin')
            ->assertHasErrors('adminEmail');

        $this->assertSame('directrice@ecole.test', $admin->fresh()->email);
        $this->assertNotNull($admin->fresh()->email_verified_at);
    }

    public function test_superadmin_can_still_edit_name_and_phone_of_a_school_admin(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school, ['email' => 'directrice@ecole.test']);

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->call('editAdmin', $admin->id)
            ->set('adminName', 'Nom Corrigé')->set('adminPhone', '0611111111')
            ->call('updateAdmin')->assertHasNoErrors();

        $this->assertSame('Nom Corrigé', $admin->fresh()->name);
        $this->assertSame('0611111111', $admin->fresh()->phone);
        $this->assertSame('directrice@ecole.test', $admin->fresh()->email);
    }

    public function test_edit_form_shows_the_email_read_only(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school);

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->call('editAdmin', $admin->id)
            ->assertSeeHtml('readonly')
            ->assertSee('créer un nouveau compte');
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenHosts(): array
    {
        return [
            'ipv4'          => ['127.0.0.1'],
            'ipv4 privée'   => ['10.0.0.5'],
            'ipv6'          => ['::1'],
            'ipv6 crochets' => ['[::1]'],
            'localhost'     => ['localhost'],
            '.local'        => ['mail.local'],
            '.internal'     => ['smtp.internal'],
            'sans point'    => ['smtpserver'],
            'localhost.'    => ['LOCALHOST'],
        ];
    }

    #[DataProvider('forbiddenHosts')]
    public function test_mail_host_refuses_literal_ips_and_local_names(string $host): void
    {
        Livewire::actingAs($this->superAdmin())->test(MailPage::class)
            ->set('host', $host)->set('port', 465)->set('encryption', 'ssl')
            ->set('username', 'u@exemple.test')->set('password', 'mot-de-passe-test-1234')
            ->set('fromAddress', 'alertes@exemple.test')->set('fromName', 'CareNest')
            ->call('save')->assertHasErrors('host');

        $this->assertDatabaseCount('mail_settings', 0);
    }

    public function test_mail_port_is_limited_to_standard_smtp_ports(): void
    {
        $page = Livewire::actingAs($this->superAdmin())->test(MailPage::class)
            ->set('host', 'smtp.hostinger.com')->set('encryption', 'ssl')
            ->set('username', 'u@exemple.test')->set('password', 'mot-de-passe-test-1234')
            ->set('fromAddress', 'alertes@exemple.test')->set('fromName', 'CareNest');

        foreach ([22, 80, 3306, 6379, 8080] as $port) {
            $page->set('port', $port)->call('save')->assertHasErrors('port');
        }
        $this->assertDatabaseCount('mail_settings', 0);

        foreach ([25, 465, 587, 2525] as $port) {
            $page->set('port', $port)->set('password', 'mot-de-passe-test-1234')->call('save')->assertHasNoErrors();
        }
        $this->assertDatabaseCount('mail_settings', 1);
    }

    public function test_password_file_creation_failure_creates_no_account(): void
    {
        $dir = storage_path('app/private/test-dossier-pas-un-fichier');
        File::ensureDirectoryExists($dir);

        $this->artisan('carenest:create-superadmin', ['email' => 'f@example.test', 'name' => 'F', '--file' => $dir])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'f@example.test']);
        File::deleteDirectory($dir);
    }

    public function test_password_file_is_private_before_anything_is_written(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Droits Unix non mesurables sous Windows : vérifié sur le serveur.');
        }
        $file = storage_path('app/private/test-droits-superadmin.txt');
        File::delete($file);

        $this->artisan('carenest:create-superadmin', ['email' => 'g@example.test', 'name' => 'G', '--file' => $file])->assertSuccessful();

        clearstatcache();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        File::delete($file);
    }
}
