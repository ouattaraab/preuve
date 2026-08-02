<?php

declare(strict_types=1);

namespace App\Services\Otp;

use RuntimeException;

/**
 * Catalogue des fournisseurs d'envoi de SMS et de leurs champs de
 * configuration.
 *
 * L'espace administrateur construit son formulaire à partir de ce catalogue :
 * ajouter un fournisseur ne demande donc aucune livraison de l'interface.
 *
 * Le fournisseur `http` est délibérément générique. Le marché ivoirien compte
 * plusieurs agrégateurs, tous exposant une API HTTP à paramètres proches, et
 * aucun n'a été arbitré : un connecteur écrit en dur pour l'un d'eux serait à
 * refaire au premier changement de contrat. Un gabarit de requête paramétrable
 * couvre les trois à la fois.
 */
final class SmsProviderRegistry
{
    /**
     * @var array<string, array{label: string, description: string, class: class-string<OtpSender>, fields: array<string, array{label: string, required: bool, secret: bool, help?: string}>}>
     */
    private const PROVIDERS = [
        'log' => [
            'label' => 'Journaux applicatifs (développement)',
            'description' => "Écrit le code dans les journaux au lieu de l'envoyer. ".
                "Refuse de s'exécuter en production.",
            'class' => LogOtpSender::class,
            'fields' => [],
        ],
        'http' => [
            'label' => 'Passerelle HTTP (générique)',
            'description' => 'Convient à tout agrégateur exposant une API HTTP. Le gabarit décrit le corps '.
                'de la requête attendu par le fournisseur.',
            'class' => HttpOtpSender::class,
            'fields' => [
                'endpoint_url' => [
                    'label' => "Adresse de l'API",
                    'required' => true,
                    'secret' => false,
                    'help' => 'HTTPS obligatoire hors développement : le code transiterait sinon en clair.',
                ],
                'http_method' => [
                    'label' => 'Méthode HTTP',
                    'required' => false,
                    'secret' => false,
                    'help' => 'POST par défaut.',
                ],
                'auth_header' => [
                    'label' => "En-tête d'authentification",
                    'required' => false,
                    'secret' => true,
                    'help' => 'Valeur complète de l\'en-tête Authorization, par exemple « Bearer … ».',
                ],
                'payload_template' => [
                    'label' => 'Gabarit du corps de la requête',
                    'required' => true,
                    'secret' => false,
                    'help' => 'JSON acceptant {{destination}} et {{message}}.',
                ],
                'message_template' => [
                    'label' => 'Gabarit du message',
                    'required' => false,
                    'secret' => false,
                    'help' => 'Accepte {{code}}. Un texte par défaut est utilisé s\'il est vide.',
                ],
            ],
        ],
    ];

    /**
     * Catalogue destiné à l'interface d'administration.
     *
     * @return list<array{key: string, label: string, description: string, fields: array<string, array{label: string, required: bool, secret: bool, help?: string}>}>
     */
    public function catalog(): array
    {
        $catalogue = [];

        foreach (self::PROVIDERS as $key => $provider) {
            $catalogue[] = [
                'key' => $key,
                'label' => $provider['label'],
                'description' => $provider['description'],
                'fields' => $provider['fields'],
            ];
        }

        return $catalogue;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, self::PROVIDERS);
    }

    /** @return array<string, array{label: string, required: bool, secret: bool, help?: string}> */
    public function fieldsFor(string $key): array
    {
        return $this->provider($key)['fields'];
    }

    /** @return class-string<OtpSender> */
    public function classFor(string $key): string
    {
        return $this->provider($key)['class'];
    }

    /**
     * Clés des champs à chiffrer au repos pour ce fournisseur.
     *
     * @return list<string>
     */
    public function secretFieldsFor(string $key): array
    {
        $secrets = [];

        foreach ($this->fieldsFor($key) as $champ => $definition) {
            if ($definition['secret']) {
                $secrets[] = $champ;
            }
        }

        return $secrets;
    }

    /**
     * Champs obligatoires manquants dans une configuration.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function missingRequiredFields(string $key, array $config): array
    {
        $manquants = [];

        foreach ($this->fieldsFor($key) as $champ => $definition) {
            $valeur = $config[$champ] ?? null;

            if ($definition['required'] && (! is_string($valeur) || trim($valeur) === '')) {
                $manquants[] = $champ;
            }
        }

        return $manquants;
    }

    /**
     * @return array{label: string, description: string, class: class-string<OtpSender>, fields: array<string, array{label: string, required: bool, secret: bool, help?: string}>}
     */
    private function provider(string $key): array
    {
        if (! $this->exists($key)) {
            throw new RuntimeException(
                "Fournisseur SMS inconnu : « {$key} ». Fournisseurs disponibles : ".
                implode(', ', array_keys(self::PROVIDERS)).'.'
            );
        }

        return self::PROVIDERS[$key];
    }
}
