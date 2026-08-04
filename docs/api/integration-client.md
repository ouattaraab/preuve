# Intégrer un client à l'API PREUVE

> Destiné à qui écrit l'application mobile (Flutter) ou tout autre client.
> Base : `https://preuve.click/api/v1`

Ce document est organisé **par parcours utilisateur**, pas par point d'entrée :
la liste des 102 routes se lit avec `php artisan route:list`, mais elle ne dit
pas dans quel ordre les appeler ni ce que signifie un `402` ici plutôt qu'un
`422`. Ce sont ces contrats-là qui ne se devinent pas.

## Conventions

| Point | Règle |
|---|---|
| En-tête obligatoire | `Accept: application/json`. Sans lui, une erreur d'authentification peut suivre le chemin web et rendre du HTML. |
| Authentification | `Authorization: Bearer <token>` (Sanctum). **Aucun mot de passe n'existe** : le jeton s'obtient par code à usage unique. |
| Version du client | `X-App-Version: 1.4.0`. Facultatif, mais voir le `426` plus bas. |
| Erreurs de validation | Forme Laravel : `{"message": "...", "errors": {"champ": ["..."]}}` en `422`. |

### Codes de statut qui ont un sens particulier ici

| Code | Signification | Ce que le client doit faire |
|---|---|---|
| `401` | Jeton absent, expiré ou révoqué | Rouvrir le parcours de connexion. Une rétrogradation de rôle ou une suspension révoque les jetons. |
| `402` | **Quota d'enregistrement épuisé** ou **frais de dossier dus** | Router vers le paiement, jamais vers le formulaire : la demande est valide et le restera. |
| `409` | Identifiant déjà enregistré, **ou** position d'envoi désynchronisée | Deux cas distincts, voir les parcours 4 et 5. |
| `426` | Version de l'application trop ancienne | Afficher l'écran de mise à jour. **La consultation reste ouverte** — ne pas bloquer l'application entière. |
| `429` | Plafond horaire de consultation atteint | Présenter le défi anti-automate si `captcha` est fourni, sinon inviter à réessayer. |
| `503` | Plateforme en lecture seule (maintenance) | Réessayer plus tard. La consultation continue de fonctionner. |

## 1. Démarrage de l'application

```http
GET /config/app
```

Public, sans jeton, **non mis en cache**. Réponse :

```json
{
  "minimum_version": "1.4.0",
  "latest_version": "1.6.0",
  "update_required_for_writes": true,
  "lookup_always_available": true
}
```

À lire **au démarrage**, avant tout parcours : si la version installée est
inférieure à `minimum_version`, afficher l'écran de mise à jour tout de suite
plutôt que de laisser l'utilisateur saisir un bien pendant quatre-vingt-dix
secondes pour se heurter à un `426` à l'envoi.

`lookup_always_available` est toujours vrai et le restera : **ne jamais bloquer
la consultation sur la version**.

```http
GET /config/categories
If-None-Match: "v1754301234567"
```

Le catalogue des types de biens et de leurs champs. **Aucun type de bien ne doit
être codé en dur dans le client** : une nouvelle catégorie doit apparaître sans
passer par les magasins d'applications. Répond `304` si l'`ETag` correspond ;
mettre le corps en cache local et ne le remplacer qu'au changement de version.

## 2. Consulter un bien — le parcours central

```http
GET /lookup/{identifiant}
```

**Sans jeton, sans compte, sans condition.** Le client ne doit jamais exiger de
connexion ici, ni pour la première consultation ni pour la centième : c'est la
promesse du produit.

L'identifiant peut être un numéro de châssis, une plaque, un IMEI ou une
référence publique `PRV-XXXXXXXX`. **Ne pas demander à l'utilisateur de choisir
le type** : la normalisation le reconnaît seule, et faire trancher l'acheteur lui
ferait porter une erreur qui n'est pas la sienne.

| Réponse | Signification |
|---|---|
| `200` avec `verdict: "known"` | Bien enregistré. Voir `asset.life_status.label` (langage courant) et `.warning`. |
| `200` avec `verdict: "unknown"` | **Ni bon ni mauvais signe.** Ne jamais présenter comme rassurant : cela signifie que personne n'a déclaré ce bien, pas qu'il est propre. |
| `422` | Saisie inexploitable. Ne consomme pas le quota. |
| `429` | Plafond atteint. Voir ci-dessous. |

Ajouter `?source=web` depuis un client web ; l'application mobile n'a rien à
passer.

Sur `429`, la réponse porte :

```json
{
  "verdict": "rate_limited",
  "captcha_required": true,
  "captcha": {"provider": "turnstile", "site_key": "..."}
}
```

`captcha` peut être `null` : le défi n'est pas configuré, le refus tient, et il
ne faut pas promettre une échappatoire qui n'existe pas. Sinon, résoudre le défi
et rejouer l'appel avec `X-Captcha-Token: <jeton>`. Le jeton est à usage unique
et **ne doit pas être présenté sur le chemin nominal** : il coûterait un
aller-retour vers Cloudflare à chaque consultation, ce que CT-01 ne permet pas.

Ce que la réponse ne contient **jamais** : le détenteur, son nom, son numéro, ni
l'identifiant complet du bien. Inutile de les chercher, ils n'y seront pas.

## 3. Se connecter

```http
POST /auth/otp/request
{"phone": "+2250101181686", "purpose": "login", "email": "..."}
```

`purpose` ∈ `login` · `register` · `sensitive_action` · `transfer` ·
`guest_payment`. `email` est requis à l'inscription tant que le canal de
livraison est le courriel — c'est elle qui recevra le code.

**La réponse est invariable**, que le numéro soit connu ou non : même message,
même `expires_in`, même code. Le client ne peut donc pas déduire l'existence d'un
compte de cette étape, et ne doit pas essayer.

```http
POST /auth/otp/verify
{"phone": "...", "purpose": "login", "code": "123456", "revoke_other_devices": false}
```

Rend `{"token": "...", "user": {...}}`. Conserver le jeton dans le stockage
sécurisé de la plateforme (Keychain / Keystore), jamais en clair.

`revoke_other_devices: true` ferme les autres sessions — à proposer sur un
parcours « je pense qu'on a accédé à mon compte », pas par défaut.

## 4. Enregistrer un bien (CT-02 : moins de 90 s au médian)

```http
POST /assets
{
  "category": "voiture",
  "attributes": {"vin": "1M8GDM9AXKP042788", "marque": "..."},
  "client_elapsed_ms": 61200,
  "scan_id": 42
}
```

`attributes` suit les champs déclarés par `/config/categories` pour cette
catégorie — d'où l'interdiction de coder les types en dur.

`client_elapsed_ms` est le **chronomètre du parcours**, mesuré côté client depuis
l'ouverture du formulaire. Il alimente la mesure de CT-02 ; sans lui, la
promesse produit n'est pas mesurée et n'est qu'une intention. Facultatif :
l'omettre quand on rejoue une file d'envois différés, où la durée ne voudrait
rien dire.

| Réponse | À faire |
|---|---|
| `201` | Le corps porte `asset` et `quota` — afficher ce qu'il reste avant d'être arrêté, plutôt que de le laisser découvrir au refus. |
| `409` | **Cet identifiant est déjà enregistré par quelqu'un d'autre.** Le corps porte la fiche publique existante et `claim_url`. Ne jamais réessayer : la seule issue est le parcours de réclamation. |
| `402` | Quota épuisé. Le corps porte l'état du quota et le prix de la place suivante. Router vers le paiement. |
| `422` | Champ manquant ou identifiant invalide (chiffre de contrôle VIN ou IMEI). |

### Pré-remplissage par photo (facultatif)

```http
POST /assets/scan          (20 appels / 10 min)
```

Rend des **candidats**, jamais un enregistrement. La valeur proposée doit être
présentée en champ modifiable, jamais validée silencieusement : un identifiant
mal lu est pire qu'un identifiant non lu, parce que personne ne relit dix-sept
caractères. Passer le `scan_id` rendu à `POST /assets` permet de mesurer combien
de propositions survivent intactes — c'est ce taux qui décidera du maintien de la
fonction.

## 5. Envoyer une pièce en 3G, avec reprise (CT-05)

Trois appels, et le second se répète.

```http
POST /uploads
{
  "uuid": "<uuid v4 tiré par le CLIENT>",
  "asset_id": 12,
  "doc_type": "registration_card",
  "filename": "carte-grise.jpg",
  "byte_size": 2400000,
  "checksum": "<sha256 hex du fichier complet>"
}
```

**L'`uuid` est tiré par le client**, et c'est ce qui rend le réessai idempotent :
un `POST` rejoué après une coupure retrouve la session au lieu d'en ouvrir une
seconde. L'empreinte est annoncée **avant le premier octet** — c'est elle qui
permettra de constater à la fin que rien n'a été altéré.

`doc_type` ∈ `invoice` · `registration_card` · `acd` · `police_report` ·
`photo` · `other`.

```http
PATCH /uploads/{uuid}
X-Upload-Offset: 1048576
<corps binaire du morceau>
```

Sur `409`, **la réponse porte la position réelle** :

```json
{"message": "...", "received_bytes": 524288}
```

`received_bytes` porte la position **réelle** telle que le serveur la connaît :
reprendre là, au lieu d'abandonner ou de tout renvoyer. C'est le
cœur du dispositif : ce qui doit tenir n'est pas l'envoi — il échouera — mais la
possibilité de le reprendre.

```http
GET /uploads/{uuid}
```

À appeler au redémarrage de l'application pour retrouver où en était chaque
envoi de la file locale. `received_bytes` dit où reprendre ; `status` dit si la
pièce est constituée. L'empreinte et le type MIME sont vérifiés **à l'assemblage**
— un envoi en morceaux contourne toute validation faite sur un fichier unique.

## 6. Déclarer un vol — en un geste

```http
POST /assets/{asset}/stolen
{"code": "<code OTP purpose=sensitive_action>"}
```

Le bien devient invendable immédiatement : la consultation publique le dit dans
la seconde. C'est le parcours le plus urgent du produit, et il ne doit pas être
enterré derrière trois écrans.

`DELETE /assets/{asset}/stolen` lève l'alerte — réservé au déclarant. L'épisode
reste consigné dans l'historique : une levée n'efface pas ce qui a eu lieu.

## 7. Transférer la propriété

```http
POST /assets/{asset}/transfer      → crée le transfert (vendeur)
POST /transfers/{transfer}/confirm → l'acheteur confirme
DELETE /transfers/{transfer}       → annulation par le vendeur
```

Double validation, et le transfert **expire** s'il n'est pas confirmé. Après
transfert, le bien repart au niveau de fiabilité le plus bas : les justificatifs
appuyaient la propriété du vendeur, pas celle de l'acheteur. Le client doit le
dire, sans quoi la baisse de jauge passera pour un défaut.

## 8. Réclamer un bien enregistré par un tiers

```http
POST /assets/{asset}/claims        → ouvre le dossier
POST /claims/{claim}/evidences     → verse des pièces
POST /claims/{claim}/submit        → dépose (gèle le bien)
```

Ouvrir et verser des pièces sont **libres**. C'est `submit` qui peut rendre
`402` : les frais de dossier sont dus au dépôt, parce que c'est là que le bien
est gelé et le détenteur prévenu — le moment où la réclamation commence à coûter
à quelqu'un d'autre. Les frais sont remboursés si la réclamation aboutit, et
l'administrateur peut les mettre à zéro : **ne pas coder le montant en dur**, le
lire dans la réponse `402`.

## 9. Acheter un rapport détaillé sans compte

```http
POST /reports/guest-code {"phone": "..."}
POST /assets/{asset}/reports {"provider": "paystack", "buyer_name": "...",
                              "buyer_email": "...", "buyer_phone": "...", "code": "..."}
GET  /reports/access/{token}
```

L'identité de l'acheteur est vérifiée **avant** le paiement, jamais après. La
lecture ne passe que par le jeton d'accès : un rapport reçu par SMS doit s'ouvrir
sur n'importe quel appareil, sans compte.

Le rapport **ne dit pas qui a enregistré le bien**. Payer n'achète pas l'identité
de quelqu'un.

## 10. Notifications

```http
POST /devices {"token": "<jeton FCM>", "platform": "android"}
DELETE /devices {"token": "..."}
GET  /notifications
POST /notifications/read-all
GET|PUT /notification-preferences
```

Réenregistrer un jeton **remplace** le rattachement précédent : un jeton
appartient à un appareil, pas à un compte, et un téléphone qui change de main ne
doit pas continuer d'alerter l'ancien propriétaire.

Les notifications de consultation sont **agrégées et anonymes** : « votre bien a
été consulté 4 fois aujourd'hui », jamais par qui. Le client ne doit pas laisser
croire qu'un détail est disponible ailleurs.

## Ce que l'API ne rendra jamais

- L'identité de qui a enregistré un bien, à qui que ce soit, y compris à
  l'acheteur d'un rapport.
- L'identité de qui a consulté un bien, y compris à son propriétaire.
- L'identifiant complet d'un bien dans une réponse publique — seule la référence
  opaque `PRV-XXXXXXXX` circule.
- Un numéro de pièce d'identité : il n'est conservé qu'en empreinte SHA-256 et
  n'est pas restituable, y compris sur réquisition.

Un client qui construirait une fonctionnalité sur l'une de ces données
construirait sur du sable : ce n'est pas un manque à combler, c'est une règle
métier absolue.

## Pour explorer

```bash
php artisan route:list --path=api/v1
```

Les contrôleurs portent leur justification en en-tête : quand une réponse
surprend, la raison y est écrite.
