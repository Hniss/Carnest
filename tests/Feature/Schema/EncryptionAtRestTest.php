<?php

namespace Tests\Feature\Schema;

use App\Models\AdminNote;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D10 (MVP v3) — chiffrement au repos des résumés, notes, mémoire de Care.
 */
class EncryptionAtRestTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(),
            'ai_summary' => 'Résumé sensible.', 'care_memory' => 'Aime le foot.',
        ]);
        return [$school, $child, $session];
    }

    public function test_session_summary_and_memory_are_encrypted_in_storage(): void
    {
        [, , $session] = $this->fixtures();

        $raw = DB::table('chat_sessions')->where('id', $session->id)->first();
        $this->assertNotSame('Résumé sensible.', $raw->ai_summary);
        $this->assertSame('Résumé sensible.', Crypt::decryptString($raw->ai_summary));
        $this->assertSame('Aime le foot.', Crypt::decryptString($raw->care_memory));
        $this->assertSame('Résumé sensible.', $session->fresh()->ai_summary);
    }

    public function test_admin_note_content_and_alert_summary_are_encrypted(): void
    {
        [$school, $child, $session] = $this->fixtures();

        $note = AdminNote::create(['child_id' => $child->id, 'content' => 'Note confidentielle.']);
        $alert = Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'isolement', 'level' => 'moderate', 'summary' => 'Résumé alerte.',
        ]);

        $rawNote = DB::table('admin_notes')->where('id', $note->id)->value('content');
        $rawAlert = DB::table('alerts')->where('id', $alert->id)->value('summary');
        $this->assertSame('Note confidentielle.', Crypt::decryptString($rawNote));
        $this->assertSame('Résumé alerte.', Crypt::decryptString($rawAlert));
        $this->assertSame('Note confidentielle.', $note->fresh()->content);
        $this->assertSame('Résumé alerte.', $alert->fresh()->summary);
    }

    public function test_migration_encrypts_existing_plaintext_and_is_idempotent(): void
    {
        [$school, $child, $session] = $this->fixtures();

        // Lignes en clair (héritées), écrites SANS passer par les casts.
        DB::table('chat_sessions')->where('id', $session->id)->update(['ai_summary' => 'En clair.']);
        $noteId = DB::table('admin_notes')->insertGetId([
            'child_id' => $child->id, 'content' => 'Note en clair.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $nullId = DB::table('admin_notes')->insertGetId([
            'child_id' => $child->id, 'content' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_09_15_000002_encrypt_existing_summaries.php');
        $migration->up();

        $this->assertSame('En clair.', ChatSession::find($session->id)->ai_summary);
        $this->assertSame('Note en clair.', AdminNote::find($noteId)->content);
        $encryptedOnce = DB::table('admin_notes')->where('id', $noteId)->value('content');

        // Seconde exécution : rien ne doit être rechiffré (idempotence).
        $migration->up();
        $this->assertSame($encryptedOnce, DB::table('admin_notes')->where('id', $noteId)->value('content'));
        $this->assertSame('Note en clair.', AdminNote::find($noteId)->content);
        $this->assertSame('', DB::table('admin_notes')->where('id', $nullId)->value('content'));
    }
}
