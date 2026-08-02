# Configurer la passerelle SMS depuis l'espace administrateur

Les codes OTP sont la seule preuve d'identité de la plateforme : sans
passerelle configurée, personne ne peut se connecter ni enregistrer un bien.

Le fournisseur ivoirien n'ayant pas été arbitré au cadrage, le choix est une
**opération d'exploitation, pas une livraison** : il se fait depuis l'espace
administrateur, sans redéploiement, et un changement de contrat ou de tarif se
règle de la même façon.

## Créer le premier administrateur

Aucun chemin HTTP ne permet d'élever un compte — une élévation de privilège
accessible par l'API serait la cible la plus rentable de la plateforme.
L'opération exige un accès au serveur :

```sh
# Le compte doit exister : il se crée en vérifiant un code OTP.
php artisan preuve:role +2250700000000 admin
```

Rôles possibles : `user` (défaut, aucun accès back-office), `agent` (instruction
des dossiers : revue des justificatifs, arbitrage), `admin` (configuration de la
plateforme). Instruire un litige ne donne aucune raison de changer le
fournisseur SMS : les deux pouvoirs restent distincts.

Tout changement de rôle passe par la chaîne d'audit.

## Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| `GET` | `/api/v1/admin/sms-provider` | Fournisseur actif + catalogue servant à construire le formulaire |
| `PUT` | `/api/v1/admin/sms-provider` | Enregistre le fournisseur et sa configuration |
| `POST` | `/api/v1/admin/sms-provider/test` | Envoi d'essai vers un numéro donné |

Tous exigent un jeton Sanctum d'un compte `admin` (403 sinon).

## Fournisseurs disponibles

### `log` — journaux applicatifs

Écrit le code dans les journaux au lieu de l'envoyer. C'est le défaut d'une
installation neuve, et **il refuse de lui-même de s'exécuter en production** :
un déploiement sans passerelle échoue bruyamment plutôt que d'écrire des codes
d'accès en clair dans un fichier de journal d'un hébergement mutualisé.

### `http` — passerelle générique

Convient à tout agrégateur exposant une API HTTP. Aucun fournisseur n'est codé
en dur : le corps de la requête est un gabarit JSON.

| Champ | Obligatoire | Secret | Rôle |
|---|---|---|---|
| `endpoint_url` | oui | non | Adresse de l'API. **HTTPS obligatoire** |
| `http_method` | non | non | `POST` par défaut |
| `auth_header` | non | **oui** | Valeur complète de l'en-tête `Authorization` |
| `payload_template` | oui | non | JSON acceptant `{{destination}}` et `{{message}}` |
| `message_template` | non | non | Accepte `{{code}}` |

Exemple :

```json
{
  "provider": "http",
  "config": {
    "endpoint_url": "https://api.mon-agregateur.ci/v1/messages",
    "payload_template": "{\"to\":\"{{destination}}\",\"text\":\"{{message}}\",\"from\":\"PREUVE\"}",
    "message_template": "Votre code PREUVE est {{code}}. Il expire dans 5 minutes.",
    "auth_header": "Bearer votre-cle-api"
  }
}
```

Les valeurs sont substituées **encodées en JSON** : un message contenant un
guillemet ne casse pas le gabarit, et un destinataire soufflé ne peut pas y
injecter de champs arbitraires.

## Ce que fait le système avec les secrets

- **Chiffrés au repos** (APP_KEY) : une clé d'API permettrait d'envoyer des SMS
  aux frais de la plateforme ; en clair dans une table, un dump de base
  suffirait.
- **Jamais renvoyés au client** : l'interface reçoit `••••••••`. Réafficher une
  clé dans un formulaire l'expose à quiconque voit l'écran et la fait fuir dans
  les journaux du navigateur.
- **Conservés si non resoumis** : puisqu'ils ne sont pas réaffichés, enregistrer
  le formulaire sans les retaper ne les efface pas. Pour effacer, soumettre le
  champ vide explicitement.
- **Absents de la chaîne d'audit** : seuls le fournisseur choisi et les *noms*
  des champs renseignés y sont journalisés.

## Après tout changement : faire un essai

```http
POST /api/v1/admin/sms-provider/test
{"phone": "0700000000"}
```

Réponse `200` avec `delivered: true`, ou `502` avec le motif du refus de la
passerelle. Le code de l'essai est tiré au hasard, n'est enregistré nulle part
et ne vaut pour aucune authentification.

Sans cet essai, une mauvaise configuration ne se découvrirait qu'au moment où un
utilisateur réel n'a pas reçu son code.

## Points de vigilance

- **L'envoi est synchrone**, avec un délai d'attente de 10 secondes : une
  passerelle lente retient la requête de l'utilisateur. Quand Horizon sera en
  place, l'envoi devra passer par la file `notifications`.
- **Un fournisseur inconnu fait échouer l'envoi**, sans repli silencieux sur les
  journaux — un tel repli écrirait les codes en clair en production.
- **Une APP_KEY tournée rend les secrets indéchiffrables** : ils sont alors
  traités comme absents, et la passerelle refuse de partir plutôt que d'envoyer
  des identifiants illisibles à un tiers. Il faut les ressaisir.
