<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\OpsReportMail;
use App\Services\OpsReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Vérification de la passerelle de messagerie (ST-0904).
 *
 * UNE PASSERELLE CONFIGURÉE MAIS JAMAIS ÉPROUVÉE EST UNE HYPOTHÈSE. C'est le
 * même piège que la sauvegarde jamais restaurée : tout paraît en place, et
 * l'on ne découvre le contraire que le jour où le message devait partir.
 *
 * ELLE REFUSE DE CONCLURE SUR LE TRANSPORT `log`. Avec lui, l'envoi réussit
 * toujours — Laravel écrit le message dans un fichier et rend la main sans
 * erreur. Un contrôle qui s'en satisferait délivrerait un satisfecit à une
 * installation d'où AUCUN courriel ne part, ce qui est exactement l'inverse de
 * ce qu'il doit dire. C'est le mode par défaut de Laravel, donc celui d'une
 * installation qu'on a oublié de configurer.
 *
 * ELLE ENVOIE POUR DE BON. Interroger la configuration ne prouve rien : un mot
 * de passe faux, un port fermé par l'hébergeur ou une adresse d'expédition qui
 * ne correspond à aucune boîte du domaine ne se voient qu'à l'envoi.
 */
final class CheckMail extends Command
{
    protected $signature = 'preuve:check-mail
                            {--to= : Adresse de destination (par défaut, le destinataire d\'exploitation)}';

    protected $description = 'Éprouve la passerelle de messagerie par un envoi réel';

    public function __construct(private readonly OpsReporter $rapports)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $transport = config('mail.default');
        $transport = is_string($transport) ? $transport : 'log';

        $expediteur = config('mail.from.address');
        $expediteur = is_string($expediteur) ? $expediteur : '';

        $this->components->twoColumnDetail('Transport', $transport);
        $this->components->twoColumnDetail('Expéditeur', $expediteur === '' ? '—' : $expediteur);

        if ($transport === 'smtp') {
            $this->components->twoColumnDetail('Serveur', sprintf(
                '%s:%s (%s)',
                is_scalar(config('mail.mailers.smtp.host')) ? (string) config('mail.mailers.smtp.host') : '—',
                is_scalar(config('mail.mailers.smtp.port')) ? (string) config('mail.mailers.smtp.port') : '—',
                is_scalar(config('mail.mailers.smtp.scheme')) ? (string) config('mail.mailers.smtp.scheme') : 'auto',
            ));
        }

        if ($transport === 'log' || $transport === 'array') {
            $this->components->error(
                "Transport « {$transport} » : aucun courriel ne quitte cette machine. C'est le défaut de ".
                "Laravel, donc l'état d'une installation qu'on a oublié de configurer — et un envoi y ".
                'réussit toujours, ce qui rend le contrôle trompeur. Renseignez MAIL_MAILER=smtp.'
            );

            return self::FAILURE;
        }

        if ($expediteur === '' || str_contains($expediteur, 'example.com')) {
            // Une adresse d'expédition hors du domaine fait échouer SPF et DKIM :
            // le message part, et atterrit en indésirable ou nulle part.
            $this->components->error(
                "L'adresse d'expédition (MAIL_FROM_ADDRESS) n'est pas renseignée, ou reste celle du ".
                'squelette. Elle doit correspondre à une boîte réelle du domaine, faute de quoi SPF et '.
                'DKIM échouent et les messages finissent en indésirables.'
            );

            return self::FAILURE;
        }

        $destinataire = $this->destinataire();

        if ($destinataire === null) {
            $this->components->error(
                "Aucune destination : réglez le destinataire d'exploitation depuis l'espace administrateur, ".
                'ou passez --to=adresse@exemple.ci.'
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Destination', $destinataire);

        try {
            Mail::to($destinataire)->send(new OpsReportMail(
                titre: 'Vérification de la passerelle de messagerie',
                anomalie: false,
                corps: "Ce message est un test d'acheminement.".PHP_EOL.PHP_EOL.
                    'Le recevoir prouve trois choses : la passerelle accepte les connexions de cette '.
                    'machine, les identifiants sont bons, et l\'adresse d\'expédition est acceptée par '.
                    'les serveurs destinataires.'.PHP_EOL.PHP_EOL.
                    'Ne pas le recevoir alors que la commande a réussi désigne le dernier maillon : '.
                    'SPF, DKIM, ou un filtre chez le destinataire.',
            ));
        } catch (Throwable $e) {
            $this->components->error('Envoi refusé : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Message remis à la passerelle, à destination de {$destinataire}.");

        // La nuance compte : la passerelle a accepté, ce qui ne dit rien de la
        // remise finale. La seule preuve est la réception.
        $this->components->warn(
            'La passerelle a ACCEPTÉ le message ; elle ne garantit pas sa remise. Vérifiez la boîte du '.
            'destinataire, et le dossier des indésirables.'
        );

        return self::SUCCESS;
    }

    private function destinataire(): ?string
    {
        $demande = $this->option('to');

        if (is_string($demande) && $demande !== '') {
            return $demande;
        }

        return $this->rapports->recipient();
    }
}
