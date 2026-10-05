<?php

namespace Tests\Feature\SuperAdmin;

use App\Contracts\SmsSender;
use App\Livewire\Admin\Settings as SchoolSettings;
use App\Livewire\SuperAdmin\MailSettings as MailPage;
use App\Livewire\SuperAdmin\SchoolShow;
use App\Mail\AlertPagedMail;
use App\Models\AlertNotification;
use App\Models\Child;
use App\Models\MailSetting;
use App\Models\School;
use App\Models\SchoolAlertRecipient;
use App\Models\User;
use App\Services\AlertPager;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * Boîte d'envoi des e-mails (réglée par le super-admin, mot de passe chiffré, sert aussi aux
 * e-mails de mot de passe) et destinataires d'alerte par école (mêmes e-mails que le référent).
 * Corrections déclarées : D2 (case e-mail de l'école sans effet retirée), D3 (un envoi en
 * échec est tracé comme un échec).
 */
class MailAndRecipientsTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    private const PASSWORD = 'mot-de-passe-smtp-de-test-9Zq7';

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function fillMailForm($page, string $password = self::PASSWORD)
    {
        return $page->set('host', 'smtp.example.test')->set('port', 465)->set('encryption', 'ssl')
            ->set('username', 'alertes@example.test')->set('password', $password)
            ->set('fromAddress', 'alertes@example.test')->set('fromName', 'CareNest');
    }

    public function test_mail_settings_are_saved_with_encrypted_password_never_redisplayed(): void
    {
        $sa = $this->superAdmin();

        $this->fillMailForm(Livewire::actingAs($sa)->test(MailPage::class))
            ->call('save')->assertHasNoErrors()->assertSet('password', '')
            ->assertSee('9Zq7')->assertDontSee(self::PASSWORD);

        $raw = DB::table('mail_settings')->value('password');
        $this->assertStringNotContainsString(self::PASSWORD, $raw);
        $this->assertSame(self::PASSWORD, MailSetting::first()->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.mail.update']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.mail.password.set']);
        $this->assertFalse(DB::table('audit_logs')->get()->contains(fn ($r) => str_contains(json_encode($r), self::PASSWORD)));

        $this->actingAs($sa)->get('/superadmin/boite-envoi')->assertOk()->assertDontSee(self::PASSWORD);
    }

    public function test_empty_password_on_update_keeps_the_stored_one(): void
    {
        $sa = $this->superAdmin();
        $this->fillMailForm(Livewire::actingAs($sa)->test(MailPage::class))->call('save');

        $this->fillMailForm(Livewire::actingAs($sa)->test(MailPage::class), '')->set('fromName', 'CareNest Alertes')->call('save')->assertHasNoErrors();

        $this->assertSame(self::PASSWORD, MailSetting::first()->password);
        $this->assertSame('CareNest Alertes', MailSetting::first()->from_name);
    }

    public function test_stored_settings_are_applied_to_the_mailer_for_every_email(): void
    {
        $sa = $this->superAdmin();
        $this->fillMailForm(Livewire::actingAs($sa)->test(MailPage::class))->call('save');

        app()->forgetInstance('mail.manager');
        app('mail.manager');

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(self::PASSWORD, config('mail.mailers.smtp.password'));
        $this->assertSame('alertes@example.test', config('mail.from.address'));
    }

    public function test_reset_returns_to_server_file(): void
    {
        $sa = $this->superAdmin();
        $page = $this->fillMailForm(Livewire::actingAs($sa)->test(MailPage::class))->call('save');
        $page->call('resetToServer');

        $this->assertDatabaseCount('mail_settings', 0);
        $this->assertNull(MailSettings::current());
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.mail.reset']);
    }

    public function test_school_admin_cannot_use_mail_or_school_components(): void
    {
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school);

        Livewire::actingAs($admin)->test(MailPage::class)->assertForbidden();
        Livewire::actingAs($admin)->test(SchoolShow::class, ['school' => $school])->assertForbidden();
    }

    public function test_recipients_are_added_validated_and_deleted_with_journal(): void
    {
        $sa = $this->superAdmin();
        $school = School::factory()->create();

        $page = Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $school])
            ->set('recipientEmail', 'Direction@Ecole.test')->call('addRecipient')->assertHasNoErrors();
        $this->assertDatabaseHas('school_alert_recipients', ['school_id' => $school->id, 'email' => 'direction@ecole.test']);

        $page->set('recipientEmail', 'direction@ecole.test')->call('addRecipient')->assertHasErrors('recipientEmail');
        $page->set('recipientEmail', 'pas-une-adresse')->call('addRecipient')->assertHasErrors('recipientEmail');

        $id = SchoolAlertRecipient::first()->id;
        $page->call('deleteRecipient', $id);
        $this->assertDatabaseCount('school_alert_recipients', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.recipient.create', 'school_id' => $school->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.recipient.delete', 'school_id' => $school->id]);
    }

    public function test_recipient_of_another_school_cannot_be_deleted_from_this_page(): void
    {
        $sa = $this->superAdmin();
        $a = School::factory()->create();
        $b = School::factory()->create();
        $other = SchoolAlertRecipient::create(['school_id' => $b->id, 'email' => 'x@b.test']);

        Livewire::actingAs($sa)->test(SchoolShow::class, ['school' => $a])->call('deleteRecipient', $other->id)->assertStatus(404);
        $this->assertDatabaseCount('school_alert_recipients', 1);
    }

    public function test_school_recipients_receive_the_same_alert_emails_as_the_referent(): void
    {
        Mail::fake();
        $this->app->instance(SmsSender::class, new RecordsSms());
        $school = School::factory()->create();
        $this->makeReferent($school, ['email' => 'ref@ecole.test']);
        SchoolAlertRecipient::create(['school_id' => $school->id, 'email' => 'direction@ecole.test']);
        SchoolAlertRecipient::create(['school_id' => School::factory()->create()->id, 'email' => 'autre@ecole.test']);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        Mail::assertSent(AlertPagedMail::class, fn (AlertPagedMail $m) => $m->hasTo('ref@ecole.test'));
        Mail::assertSent(AlertPagedMail::class, fn (AlertPagedMail $m) => $m->hasTo('direction@ecole.test'));
        Mail::assertNotSent(AlertPagedMail::class, fn (AlertPagedMail $m) => $m->hasTo('autre@ecole.test'));

        $emails = AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->get();
        $this->assertCount(2, $emails);
        $this->assertTrue($emails->every(fn ($n) => $n->sent_at !== null));
    }

    public function test_failed_email_is_journaled_as_a_failure_never_as_sent(): void
    {
        $this->app->instance(SmsSender::class, new RecordsSms());
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connexion refusée par le serveur'));
        $school = School::factory()->create();
        $this->makeReferent($school, ['email' => 'ref@ecole.test']);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        $email = AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->first();
        $this->assertNotNull($email);
        $this->assertNull($email->sent_at);
        $this->assertSame('echec', $email->payload['statut'] ?? null);
        $this->assertStringNotContainsString('Connexion refusée', json_encode($email->payload));
    }

    public function test_school_settings_no_longer_offer_the_ineffective_email_checkbox(): void
    {
        $school = School::factory()->create();
        $admin = $this->makeAdmin($school);

        $this->actingAs($admin)->get('/settings')->assertOk()->assertDontSee('Notifications e-mail');
        $this->assertFalse(property_exists(SchoolSettings::class, 'emailNotifications'));
    }
}
