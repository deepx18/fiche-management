<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Création d'administrateurs : `php artisan admin:create`.
 * Identifiants jamais codés en dur, demandés en invite interactive avec
 * masquage du mot de passe ; validation stricte avant écriture.
 */
class AdminCreateCommand extends Command
{
    protected $signature = 'admin:create
        {--name= : Nom de l\'administrateur}
        {--email= : Adresse email}
        {--password= : Mot de passe (saisie interactive recommandée)}';

    protected $description = 'Créer un compte administrateur (premier ou supplémentaire)';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Nom de l\'administrateur');
        $email = $this->option('email') ?? $this->ask('Adresse email');
        $password = $this->option('password');

        if ($password === null) {
            $password = $this->secret('Mot de passe (8 caractères minimum)');
            $confirm = $this->secret('Confirmation du mot de passe');

            if ($password !== $confirm) {
                $this->error('Les deux mots de passe ne correspondent pas.');

                return self::FAILURE;
            }
        }

        $validated = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        )->stopOnFirstFailure();

        if ($validated->fails()) {
            $this->error($validated->errors()->first());

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password' => Hash::make($password),
            'role' => 'admin',
            'active' => true,
        ]);

        $this->info("Administrateur créé : {$user->name} <{$user->email}> (id {$user->id}).");

        return self::SUCCESS;
    }
}
