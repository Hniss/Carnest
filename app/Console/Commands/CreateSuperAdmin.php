<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée un compte super-admin (fondateur CareNest), sur le serveur uniquement.
 *
 * Le mot de passe est aléatoire, JAMAIS affiché : il est ajouté à un fichier privé
 * (droits 600) que le fondateur lit lui-même, puis supprime après sa première connexion.
 * Un e-mail déjà utilisé par un autre compte est refusé (on ne change jamais le rôle
 * d'un compte existant).
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'carenest:create-superadmin
        {email : Adresse e-mail du fondateur}
        {name : Nom affiché}
        {--file= : Fichier privé où écrire le mot de passe (défaut : storage/app/private/comptes-superadmin.txt)}';

    protected $description = 'Crée un compte super-admin ; le mot de passe est écrit dans un fichier privé, jamais affiché.';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name  = trim((string) $this->argument('name'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $this->error('Adresse e-mail ou nom invalide.');
            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('Cette adresse est déjà utilisée par un compte : aucun compte créé, aucun rôle modifié.');
            return self::FAILURE;
        }

        $file     = (string) ($this->option('file') ?: storage_path('app/private/comptes-superadmin.txt'));
        $password = Str::password(20, symbols: false);

        File::ensureDirectoryExists(dirname($file));
        File::append($file, $email . ' ' . $password . PHP_EOL);
        @chmod($file, 0600);

        $user = User::create([
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make($password),
            'role'              => 'superadmin',
            'email_verified_at' => now(),
        ]);

        AuditLog::create([
            'actor_id'    => null,
            'actor_role'  => 'system',
            'action'      => 'superadmin.account.create',
            'target_type' => User::class,
            'target_id'   => $user->id,
            'school_id'   => null,
            'ip'          => null,
            'created_at'  => now(),
        ]);

        $this->info("Compte super-admin créé pour {$email}. Mot de passe ajouté au fichier privé : {$file}");

        return self::SUCCESS;
    }
}
