<?php

declare(strict_types=1);

namespace App\Providers;

use App\Extensions\PreuveSessionHandler;
use App\Services\Kyc\IdentityReader;
use App\Services\Kyc\MindeeIdentityReader;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Otp\OtpSender;
use App\Services\Settings\SettingsRepository;
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
        // Les réglages sont relus à chaque émission de code : une seule
        // instance, donc un seul cache mémoire par requête.
        $this->app->singleton(SettingsRepository::class);

        // Acheminement des codes OTP : le fournisseur est choisi dans l'espace
        // administrateur, pas dans le code. Sur une installation neuve, aucun
        // fournisseur n'est configuré et les journaux servent de défaut —
        // LogOtpSender refusant de lui-même la production, un déploiement sans
        // fournisseur échoue bruyamment plutôt que d'écrire des codes d'accès
        // en clair dans un fichier de log.
        $this->app->bind(OtpSender::class, ConfigurableOtpSender::class);

        // Extraction des pièces d'identité. Mindee est le fournisseur de la
        // stack, mais sa clé se configure en exploitation : sans clé, le
        // lecteur rend une extraction vide et le dossier part en revue
        // manuelle — la plateforme ne dépend jamais d'un tiers pour continuer
        // à vérifier des identités.
        $this->app->bind(IdentityReader::class, MindeeIdentityReader::class);
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
