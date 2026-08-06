# Brancher Paystack

> Éprouvé le 06/08/2026 sur `preuve.click` avec une clé `sk_test_…` :
> transaction ouverte, **devise XOF acceptée**, montant `20000` pour 200 FCFA,
> domaine `test`. Vérifié via `transaction/verify`.

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
   « Payer et publier ». Régler avec une **carte de test** Paystack.
5. Vérifier que le bien paraît sur <https://preuve.click/voles>.

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

- **PawaPay (Wave, Orange Money, MTN MoMo) n'est pas branché.** `PaymentProvider`
  les connaît, l'endpoint générique les attend, mais aucune passerelle
  n'implémente l'ouverture de transaction : un paiement mobile rend
  `checkout_url: null`, et le client affiche « à régler auprès de l'opérateur »
  sans adresse. C'est le prochain chantier de paiement, et il compte davantage
  que la carte sur ce marché.
- **CinetPay est interdit** (CLAUDE.md).
