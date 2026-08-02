<?php

declare(strict_types=1);

namespace App\Providers;

use App\Extensions\PreuveSessionHandler;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\OtpSender;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Acheminement des codes OTP. Tant qu'aucun fournisseur SMS ivoirien
        // n'est arbitré (question ouverte du cadrage), le développement passe
        // par les journaux — LogOtpSender refuse de s'exécuter en production,
        // pour qu'un déploiement sans fournisseur échoue bruyamment plutôt
        // que d'écrire des codes d'accès en clair dans un fichier de log.
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Substitue le gestionnaire de session « database » de Laravel par
        // PreuveSessionHandler, qui n'écrit jamais l'adresse IP en clair
        // dans la table `sessions` (règle absolue de PREUVE). Reproduit la
        // construction faite par Illuminate\Session\SessionManager::createDatabaseDriver().
        Session::extend('database', function (Container $app): PreuveSessionHandler {
            // Les valeurs par défaut ci-dessous reprennent celles du squelette
            // Laravel (config/session.php) : elles ne servent que si la
            // configuration a été altérée avec un type inattendu.
            $table = config('session.table');
            $table = is_string($table) ? $table : 'sessions';

            $lifetime = config('session.lifetime');
            $lifetime = is_int($lifetime) ? $lifetime : 120;

            $sessionConnection = config('session.connection');
            $sessionConnection = is_string($sessionConnection) ? $sessionConnection : null;

            $connection = $app->make('db')->connection($sessionConnection);

            return new PreuveSessionHandler($connection, $table, $lifetime, $app);
        });
    }
}
