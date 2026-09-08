<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ouvre le scan de carte grise au visiteur SANS COMPTE (ST-0202, ST-0301).
 *
 * POURQUOI. Le scan servait déjà à l'enregistrement, où l'on est forcément
 * connecté. Mais l'acheteur qui se voit proposer une moto sur un parking, lui,
 * n'a pas de compte — et c'est précisément lui à qui recopier dix-sept
 * caractères de châssis à la main coûte le plus. Le bouton « Je scanne » de
 * l'accueil était donc grisé depuis le début, sur le parcours phare.
 *
 * La dépense reste bornée par `AnonymousScanAllowance`, plafond distinct de
 * celui des consultations : un scan appelle un fournisseur qui facture à
 * l'appel, une consultation lit la base.
 *
 * `down()` NE SUPPRIME RIEN et ne remet pas la colonne en NOT NULL : des scans
 * anonymes existeront, et les rendre non conformes casserait la table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volontairement vide. Voir l'en-tête : restaurer NOT NULL sur une
        // colonne où des lignes anonymes ont été écrites échouerait, et forcer
        // la migration en supprimant ces lignes effacerait la mesure du
        // pré-remplissage sur tout le parcours de consultation.
    }
};
