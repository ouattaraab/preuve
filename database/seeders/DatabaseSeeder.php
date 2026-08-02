<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Le seeder du squelette Laravel créait un compte avec `name`, `email` et
 * `password` — trois colonnes absentes de la table `users` de PREUVE, qui
 * s'authentifie par téléphone et code OTP, sans mot de passe au MVP. Il
 * échouait donc à la première exécution.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
