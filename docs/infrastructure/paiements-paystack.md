# Brancher Paystack

> Éprouvé le 06/08/2026 sur `preuve.click` avec une clé `sk_test_…` :
> transaction ouverte, **devise XOF acceptée**, montant `20000` pour 200 FCFA,
> domaine `test`. Vérifié via `transaction/verify`.

## Le canal actif est le MOBILE MONEY, pas la carte

Relevé en interrogeant l'API avec la clé de ce compte :

| Canal demandé | Réponse de Paystack |
|---|---|
| `mobile_money` | **accepté** |
| `card` seul | **refusé** — « No active channel to process transaction » |
| `card` + `mobile_money` | accepté (grâce au second) |

**Conséquence pratique : une carte de test ne sert à rien ici.** La page de
règlement proposera le mobile money — Wave, Orange, MTN, Moov selon ce que le
compte expose. C'est la bonne nouvelle pour ce marché, où la carte est
minoritaire, et c'est ce qui rend PawaPay inutile.

**On ne passe volontairement AUCUN paramètre `channels`** dans
`PaystackGateway::initialize`. Les canaux suivent la configuration du compte :
les figer dans le code ferait manquer une carte activée plus tard, ou pire,
demanderait un déploiement pour l'accompagner.

## Ce qui se règle, et où

| Élément | Où | Pourquoi pas ailleurs |
|---|---|---|
| **Clé secrète** `sk_test_…` / `sk_live_…` | `/admin/tarifs` | Chiffrée au repos, jamais réaffichée. Une rotation de clé ne doit pas demander un déploiement — donc pas de `.env`. |
| **Adresse de rappel** | tableau de bord Paystack | `https://preuve.click/api/v1/webhooks/payments/paystack` — affichée dans `/admin/tarifs` pour éviter une recopie de travers |
| **Montants** | `/admin/tarifs` | `PREUVE_*_FCFA` du `.env` ne sont que des valeurs par défaut ; un réglage en base l'emporte |

**Clé publique `pk_…` : inutile.** Aucun formulaire de carte n'est servi par
Preuve — on redirige vers la page de Paystack, ce qui met la barre d'adresse au
service de l'utilisateur : il vérifie lui-même qu'il est chez l'opérateur et non
sur une imitation.

**Aucun secret de webhook pour Paystack.** Il signe ses rappels avec la clé
secrète elle-même. Le champ « secret partagé » de `/admin/tarifs` ne concerne
que les opérateurs au format maison (PawaPay).

## Le format des rappels, et pourquoi il a fallu l'écrire

L'endpoint d'origine attendait un format maison : `X-Preuve-Signature`,
HMAC-SHA256 sur un secret partagé, corps `{reference, status}`. Paystack
n'envoie **rien de cela** :

| | Format maison | Paystack |
|---|---|---|
| En-tête | `X-Preuve-Signature` | `x-paystack-signature` |
| Algorithme | HMAC-SHA256 | **HMAC-SHA512** |
| Clé de signature | secret partagé | **la clé secrète** |
| Corps | `{reference, status}` | `{event, data:{reference, status}}` |
| État abouti | `succeeded` | `success` |

Branché tel quel, chaque rappel réel aurait été refusé en 401 — et signé
correctement, rejeté en 422 sur l'état. **L'argent serait entré et rien n'aurait
été crédité**, sans le moindre signal avant une réclamation.

La signature est calculée sur le **corps brut**, jamais sur le tableau décodé :
un JSON ré-encodé diffère de l'original (ordre des clés, échappement des barres
obliques) et la signature ne vaudrait plus rien.

### Correspondance des états

| Paystack | Preuve | Pourquoi |
|---|---|---|
| `success` | `succeeded` | |
| `failed`, `abandoned` | `failed` | « abandonné » = page fermée. Le dire permet de rouvrir une caisse ; « en attente » ferait patienter indéfiniment |
| `reversed` | `refunded` | |
| `ongoing`, `pending`, `processing`, `queued` | `pending` | |
| tout le reste | **rien** | Retomber sur « en attente » ferait reculer une transaction aboutie au premier mot nouveau dans leur API, et `reconcile()` ne rejoue pas un état définitif : la perte serait silencieuse et durable |

Un événement qui ne nous concerne pas — Paystack poste aussi transferts,
abonnements et litiges sur la même adresse — reçoit **200 et rien d'autre**.
Une erreur le ferait retenter pendant des jours, puis désactiver le point de
réception, donc nous priver des rappels utiles.

## Mise en service, dans l'ordre

1. `/admin/tarifs` → clé `sk_test_…`. Le bandeau passe au vert.
2. Tableau de bord Paystack → **Settings → API Keys & Webhooks** → *Test
   Webhook URL* = `https://preuve.click/api/v1/webhooks/payments/paystack`
3. Ouvrir un tarif : mettre `theft_listing` à 200 FCFA, par exemple.
4. Depuis l'application, sur un bien déclaré volé : « Le faire connaître » →
   « Payer et publier ». **Régler en mobile money** — la carte n'est pas active
   sur ce compte (voir plus haut) ; en mode test, Paystack affiche lui-même la
   marche à suivre pour simuler le règlement.
5. Revenir dans l'application : l'écran se met à jour tout seul.
6. Vérifier que le bien paraît sur <https://preuve.click/voles>.

**Ce qui prouve que la boucle est fermée**, ce n'est pas la page de retour —
elle ne fait que relire — mais l'apparition du bien sur la liste. Le retour du
navigateur ne prouve aucun paiement : il suffirait de rappeler l'adresse à la
main pour s'attribuer un service.

## Passage en production

Remplacer la clé par `sk_live_…` dans `/admin/tarifs`, et déclarer la **Live
Webhook URL** chez Paystack — c'est un champ **distinct** de celui de test. Le
répertoire de test continue de fonctionner avec sa propre clé ; les deux
n'échangent aucune transaction.

## Ce qui reste ouvert

- **PawaPay est écarté** (décision de l'éditeur, 06/08/2026). `PaymentProvider`
  connaît encore ses valeurs — elles existent dans l'ENUM SQL de `payments` et
  les retirer casserait des lignes historiques — mais aucune passerelle ne les
  implémente, et l'application ne les propose nulle part : elle ouvre toujours
  Paystack. Le format maison du webhook reste en place pour un éventuel autre
  opérateur.
- **La carte n'est pas activée sur ce compte Paystack.** Ce n'est pas un défaut
  de la plateforme, et rien n'est à changer dans le code — mais si l'on veut un
  jour encaisser par carte, cela se demande à Paystack, pas à nous.
- **CinetPay est interdit** (CLAUDE.md).
