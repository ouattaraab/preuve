<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le jeton d'invitation d'un transfert.
 *
 * POURQUOI UN JETON, ET PAS L'IDENTIFIANT DU TRANSFERT. Une adresse en
 * `/cession/42` s'énumère : on lirait la file des cessions en cours, c'est-à-
 * dire quels biens changent de mains cette semaine et lesquels sont donc
 * disponibles à l'arrachée. Le jeton est tiré au hasard sur 32 octets et ne
 * désigne rien d'autre que lui-même.
 *
 * IL N'AUTORISE RIEN À LUI SEUL. Il ouvre une page qui décrit le bien ; accepter
 * demande le code envoyé séparément. Un lien transféré, un courriel lu par un
 * tiers, une capture d'écran : aucun de ces incidents ne fait changer un bien
 * de propriétaire.
 *
 * NULLABLE : les transferts déjà ouverts n'en ont pas, et leur en fabriquer un
 * ici enverrait des invitations pour des cessions dont personne n'attend plus
 * rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfers') || Schema::hasColumn('transfers', 'invite_token')) {
            return;
        }

        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('invite_token', 64)->nullable()->after('to_email');
            $table->unique('invite_token', 'uq_transfers_invite_token');
        });
    }

    /**
     * VIDE, ET C'EST VOULU. Retirer cette colonne casserait tous les liens
     * d'invitation déjà envoyés : les destinataires tomberaient sur une page
     * introuvable, sans jamais comprendre pourquoi, et les cessions
     * expireraient d'elles-mêmes.
     */
    public function down(): void {}
};
