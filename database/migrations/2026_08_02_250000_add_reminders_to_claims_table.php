<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur de relances du contradictoire (ST-0504).
 *
 * Un compteur plutôt que deux colonnes datées : les échéances de relance
 * peuvent bouger — passer de deux à trois rappels, ou déplacer J+13 — sans
 * migration. Ce qui doit être su, c'est combien de rappels ont déjà été
 * envoyés, pas lesquels.
 *
 * Sans cette trace, le passage horaire du job renverrait la même relance
 * toutes les heures pendant huit jours : le mis en cause finirait par ignorer
 * des messages qui l'avertissent qu'il est en train de perdre son bien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reminders_sent')->default(0)->after('respondent_deadline');
            $table->dateTime('last_reminder_at')->nullable()->after('reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table): void {
            $table->dropColumn(['reminders_sent', 'last_reminder_at']);
        });
    }
};
