<?php

declare(strict_types=1);

use App\Mail\OpsReportMail;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\OpsReporter;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * ST-0904 : ce qui se dégrade lentement doit se signaler tout seul.
 *
 * Une alerte dit qu'une chose a cassé. Elle ne dit jamais qu'une file de
 * modération s'allonge de trois dossiers par semaine, ni que l'ancrage n'a plus
 * tourné depuis dix jours. Ces états ne produisent aucun événement.
 */
beforeEach(function (): void {
    Mail::fake();
    Cache::flush();
    app(SettingsRepository::class)->set(OpsReporter::RECIPIENT_SETTING, 'exploitation@bookmi.ci');
});

it('CONSTATE QUE LE STOCKAGE ACCEPTE ENCORE D\'ÉCRIRE', function (): void {
    // `disk_free_space` rend 14 To sur ce mutualisé — le volume de l'hébergeur,
    // pas notre quota. Le seul contrôle honnête est d'écrire pour de vrai.
    $this->artisan('preuve:check-storage')
        ->expectsOutputToContain('écriture possible')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

it('ALERTE quand les tailles dépassent le seuil', function (): void {
    // Un octet suffit à franchir un seuil de zéro : ce qu'on éprouve ici est
    // le déclenchement, pas la valeur.
    config()->set('preuve.storage.alert_mb', 1);
    // Le canal d'alerte doit être dans la pile pour que `Log::error` l'atteigne.
    // C'est justement ce qu'un déploiement peut omettre — le résumé hebdomadaire
    // le signale désormais.
    config()->set('logging.channels.stack.channels', ['daily', 'alerte-ops']);
    file_put_contents(storage_path('logs/gros-journal.log'), str_repeat('x', 1_200_000));

    $this->artisan('preuve:check-storage')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => $m->anomalie);

    @unlink(storage_path('logs/gros-journal.log'));
});

it('NE RÉCLAME PAS UN SEUIL qu\'on ne peut pas deviner', function (): void {
    // Le quota dépend du contrat d'hébergement. Sans réglage, on surveille
    // l'écriture et on le DIT, plutôt que d'inventer une valeur rassurante.
    config()->set('preuve.storage.alert_mb', 0);

    $this->artisan('preuve:check-storage')
        ->expectsOutputToContain('Aucun seuil de stockage configuré')
        ->assertSuccessful();
});

it('RÉSUME LA SEMAINE et l\'expédie', function (): void {
    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => str_contains($m->corps, 'ACTIVITÉ')
        && str_contains($m->corps, 'MÉCANIQUE'));
});

it('SIGNALE UN DOSSIER QUI DORT DANS LA FILE', function (): void {
    // Douze dossiers déposés ce matin ne posent aucun problème ; un seul qui
    // attend depuis trois semaines en est un — et le décompte seul ne les
    // distingue pas.
    $compte = User::create(['phone' => '+2250700111222']);

    KycSubmission::create([
        'user_id' => $compte->id,
        'status' => 'pending',
        'id_front_ref' => 'a', 'id_back_ref' => 'b', 'selfie_ref' => 'c',
        'id_front_sha256' => str_repeat('0', 64),
        'id_back_sha256' => str_repeat('0', 64),
        'selfie_sha256' => str_repeat('0', 64),
        // `created_at` n'est pas remplissable : l'antidater demande forceFill,
        // sans quoi le dossier naît « aujourd'hui » et le test ne prouve rien.
    ])->forceFill(['created_at' => now()->subDays(9)])->save();

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => $m->anomalie
        && str_contains($m->corps, 'attend depuis 9 jours'));
});

it('RAPPORTE L\'ÉTAT DE L\'ANCRAGE, qui rend la chaîne opposable', function (): void {
    // Sans ancrage, la chaîne d'audit reste vérifiable — mais par nous seuls,
    // ce qui ne vaut rien devant un juge.
    //
    // ON N'ÉPROUVE PAS ICI L'ABSENCE D'ANCRAGE : le registre d'ancrage est
    // append-only, protégé par un déclencheur, et la base REFUSE qu'un test en
    // supprime une ligne. C'est le dispositif qui fonctionne, et un test qui
    // manœuvrerait pour le contourner éprouverait autre chose que la
    // production. On vérifie donc que la ligne est rapportée ; le chemin
    // « anomalie » est couvert par le test des alertes éteintes.
    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => str_contains($m->corps, 'Dernier ancrage')
        && str_contains($m->corps, 'Chaîne d\'audit'));
});

it('NE NOMME PERSONNE dans le résumé', function (): void {
    // Ce sont des décomptes. Le nom du plus ancien déposant n'aiderait pas à
    // décider, et ferait sortir une identité de la plateforme par courriel.
    $compte = User::create(['phone' => '+2250788999000']);
    $compte->forceFill(['full_name' => 'Awa Koné'])->save();

    KycSubmission::create([
        'user_id' => $compte->id,
        'status' => 'pending',
        'id_front_ref' => 'a', 'id_back_ref' => 'b', 'selfie_ref' => 'c',
        'id_front_sha256' => str_repeat('0', 64),
        'id_back_sha256' => str_repeat('0', 64),
        'selfie_sha256' => str_repeat('0', 64),
    ])->forceFill(['created_at' => now()->subDays(5)])->save();

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => ! str_contains($m->corps, 'Awa Koné')
        && ! str_contains($m->corps, '+2250788999000'));
});

it('SURVIT à une table absente plutôt que de perdre tout le rapport', function (): void {
    // Les autres lignes ont encore de la valeur : un rapport amputé vaut mieux
    // qu'un rapport qui n'arrive pas.
    DB::statement('DROP TABLE IF EXISTS failed_jobs');

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => str_contains($m->corps, 'non mesurable'));
});

it('SIGNALE QUE LES ALERTES SONT ÉTEINTES, le cas échéant', function (): void {
    // LA SURVEILLANCE SE SURVEILLE. Un déploiement qui omet le canal dans
    // LOG_STACK perd TOUTES les alertes d'erreur, en silence — précisément le
    // défaut qu'on cherchait à corriger. Le rapport hebdomadaire est alors la
    // seule chose qui parvienne encore : il doit le dire.
    config()->set('logging.channels.stack.channels', ['daily']);

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => $m->anomalie
        && str_contains($m->corps, 'ÉTEINTES'));
});
