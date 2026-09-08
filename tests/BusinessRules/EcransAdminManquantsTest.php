<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Les écrans qui manquaient à la console (EP-07, ST-0904).
 *
 * HUIT GROUPES D'API N'AVAIENT AUCUNE PORTE. Validation des sociétés,
 * passerelle SMS, défi anti-automate, lecteur de pièces, push, ancrage
 * d'audit, mode lecture seule : tous réglables par appel d'API, aucun par
 * l'interface. Un réglage qui ne se pose qu'en base ne se pose pas.
 *
 * LES DEUX CONSÉQUENCES CONCRÈTES, et ce sont elles que ces tests gardent :
 *
 * — un loueur qui s'inscrivait restait « en attente » pour toujours, faute
 *   d'écran pour le voir. La verticale de lancement était bloquée par une
 *   porte manquante, pas par une fonctionnalité manquante ;
 * — le mode lecture seule ne s'atteignait qu'avec un terminal, c'est-à-dire
 *   jamais au moment où l'on en a besoin : pendant un incident.
 *
 * CE FICHIER N'ÉPROUVE QUE LES PORTES, pas les décisions derrière. La
 * validation d'une société est déjà couverte par `ObservabiliteTest`, qui ne
 * s'exécute PAS sous `RefreshDatabase` — la chaîne d'audit refuse d'être
 * imbriquée dans une transaction englobante. La rejouer ici l'aurait fait
 * échouer pour une raison sans rapport avec ce qu'on cherche à garder.
 */
function agentConsole(bool $admin = false): User
{
    $compte = User::create(['phone' => '+2250701'.random_int(100000, 999999)]);

    // `forceFill` ET NON `create` : `role` n'est pas assignable en masse, et
    // pour de bonnes raisons — une inscription ne doit jamais pouvoir se
    // nommer administrateur. Le passer à `create()` l'aurait silencieusement
    // ignoré, et le harnais aurait éprouvé un simple utilisateur.
    $compte->forceFill(['role' => $admin ? 'admin' : 'agent'])->save();

    return $compte;
}

it('OUVRE L\'ÉCRAN DES SOCIÉTÉS AUX AGENTS', function (): void {
    // Valider une société est un travail de guichet, pas une décision
    // d'administrateur : le réserver aux admins recréerait le goulot.
    test()->actingAs(agentConsole())
        ->get('/admin/societes')
        ->assertOk()
        ->assertSee('Sociétés à valider');
});

it('MONTRE LES SOCIÉTÉS À VALIDER DANS LA NAVIGATION', function (): void {
    // Un écran qu'on ne peut atteindre depuis nulle part n'existe pas.
    test()->actingAs(agentConsole())
        ->get('/admin/vue-ensemble')
        ->assertOk()
        ->assertSee('/admin/societes');
});

it('RÉSERVE LES RÉGLAGES TECHNIQUES AUX ADMINISTRATEURS', function (): void {
    // Le garde est sur la ROUTE, pas dans le gabarit : une coquille vide
    // servie à un agent lui apprendrait quand même que l'écran existe.
    test()->actingAs(agentConsole())->get('/admin/reglages')->assertForbidden();
    test()->actingAs(agentConsole(admin: true))->get('/admin/reglages')->assertOk();
});

it('DONNE ENFIN UNE PORTE AU MODE LECTURE SEULE', function (): void {
    // On en a besoin PENDANT un incident, c'est-à-dire quand personne n'a de
    // terminal sous la main.
    test()->actingAs(agentConsole(admin: true))
        ->get('/admin/reglages')
        ->assertOk()
        ->assertSee('Mode lecture seule')
        // ET LA PROMESSE QUI L'ACCOMPAGNE : la consultation ne se coupe jamais.
        ->assertSee('La consultation reste ouverte', false);
});

it('DONNE UNE PORTE AUX SIX CONFIGURATIONS ORPHELINES', function (): void {
    $ecran = test()->actingAs(agentConsole(admin: true))->get('/admin/reglages')->assertOk();

    foreach ([
        'Passerelle SMS',
        'Défi anti-automate',
        'Lecture automatique des pièces',
        'Notifications push',
        'Ancrage de la chaîne d\'audit',
    ] as $section) {
        $ecran->assertSee($section, false);
    }
});

it('N\'ANNONCE PAS LES RÉGLAGES À UN AGENT DANS LA NAVIGATION', function (): void {
    // La navigation cache ce qu'un rôle n'atteint pas : lui montrer une entrée
    // qui rend 403 est une promesse non tenue à chaque clic.
    test()->actingAs(agentConsole())
        ->get('/admin/vue-ensemble')
        ->assertOk()
        ->assertDontSee('/admin/reglages');
});
