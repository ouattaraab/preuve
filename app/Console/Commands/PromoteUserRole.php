<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActorType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditChain;
use Illuminate\Console\Command;

/**
 * Attribue un rôle de back-office à un compte existant.
 *
 * Il n'existe volontairement aucun chemin HTTP pour cela : une élévation de
 * privilège accessible par l'API serait la cible la plus rentable de toute la
 * plateforme, et le premier administrateur doit bien naître quelque part.
 * L'opération exige un accès au serveur, et passe par la chaîne d'audit.
 */
final class PromoteUserRole extends Command
{
    protected $signature = 'preuve:role {phone : Numéro du compte, au format E.164} {role : user, agent ou admin}';

    protected $description = 'Attribue un rôle de back-office à un compte existant';

    public function handle(AuditChain $chain): int
    {
        $telephone = (string) $this->argument('phone');
        $roleDemande = (string) $this->argument('role');

        $role = UserRole::tryFrom($roleDemande);

        if ($role === null) {
            $this->components->error(
                "Rôle inconnu : « {$roleDemande} ». Rôles possibles : ".
                implode(', ', array_column(UserRole::cases(), 'value')).'.'
            );

            return self::FAILURE;
        }

        $utilisateur = User::where('phone', $telephone)->first();

        if ($utilisateur === null) {
            $this->components->error(
                "Aucun compte pour le numéro « {$telephone} ». Le compte doit exister — il se crée en ".
                'vérifiant un code OTP.'
            );

            return self::FAILURE;
        }

        $ancien = $utilisateur->role;

        $chain->transaction(function () use ($utilisateur, $role, $ancien): array {
            $utilisateur->forceFill(['role' => $role])->save();

            return [
                'result' => null,
                'actorType' => ActorType::System,
                'actorId' => null,
                'action' => 'admin.role_changed',
                'entityType' => 'user',
                'entityId' => $utilisateur->id,
                // Jamais le numéro : audit_log est inaltérable et survivrait à
                // tout exercice du droit à l'effacement (Loi 2013-450).
                'payload' => ['from' => $ancien->value, 'to' => $role->value],
            ];
        });

        $this->components->info(
            "Compte #{$utilisateur->id} : rôle « {$ancien->label()} » → « {$role->label()} »."
        );

        return self::SUCCESS;
    }
}
