<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D10 (MVP v3) — Chiffrement au repos des données sensibles déjà stockées en clair :
 *   chat_sessions.ai_summary, admin_notes.content
 *   (+ chat_sessions.care_memory et alerts.summary si les colonnes existent déjà).
 *
 * Les modèles portent désormais le cast `encrypted` sur ces colonnes ; cette
 * migration rechiffre les lignes historiques. Rejouable : une valeur déjà
 * chiffrée (déchiffrable) est laissée telle quelle ; les valeurs nulles ou vides
 * sont ignorées. Aucune donnée n'est jamais écrite en clair ailleurs.
 */
return new class extends Migration
{
    private const CHUNK = 200;

    public function up(): void
    {
        $this->encryptColumn('chat_sessions', 'ai_summary');
        $this->encryptColumn('admin_notes', 'content');

        // Colonnes ajoutées par le schéma v3 : chiffrées si présentes (idempotence du lot).
        if (Schema::hasColumn('chat_sessions', 'care_memory')) {
            $this->encryptColumn('chat_sessions', 'care_memory');
        }
        if (Schema::hasColumn('alerts', 'summary')) {
            $this->encryptColumn('alerts', 'summary');
        }
    }

    public function down(): void
    {
        // Volontairement sans effet : on ne remet jamais des données sensibles en clair.
    }

    private function encryptColumn(string $table, string $column): void
    {
        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $value = (string) $row->{$column};

                    if ($this->isAlreadyEncrypted($value)) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => Crypt::encryptString($value)]);
                }
            });
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
