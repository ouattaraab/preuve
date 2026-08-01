<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

it("n'écrit jamais l'adresse IP en clair dans la table sessions", function (): void {
    // La suite de tests force SESSION_DRIVER=array pour s'isoler de la
    // configuration de production (voir ConfigurationMutualiseeTest). Ce
    // test vérifie précisément le comportement du driver `database` en
    // conditions réelles : on le réactive localement, pour cette requête.
    Config::set('session.driver', 'database');

    $adresseIpDeTest = '203.0.113.77';

    $identifiantsAvant = DB::table('sessions')->pluck('id')->all();

    $reponse = $this->call('GET', '/', [], [], [], ['REMOTE_ADDR' => $adresseIpDeTest]);

    $reponse->assertOk();

    $identifiantsApres = DB::table('sessions')->pluck('id')->all();
    $nouveauxIdentifiants = array_values(array_diff($identifiantsApres, $identifiantsAvant));

    expect($nouveauxIdentifiants)->toHaveCount(1);

    $ligne = (array) DB::table('sessions')->where('id', $nouveauxIdentifiants[0])->first();

    expect($ligne['ip_address'])->toBeNull();

    // Aucune colonne de la ligne ne doit contenir l'adresse IP du test,
    // sous quelque forme que ce soit (ceinture et bretelles par rapport à
    // l'assertion précédente, ciblée sur la colonne `ip_address`).
    foreach ($ligne as $valeur) {
        expect($valeur)->not->toBe($adresseIpDeTest);
    }

    DB::table('sessions')->where('id', $nouveauxIdentifiants[0])->delete();
});
