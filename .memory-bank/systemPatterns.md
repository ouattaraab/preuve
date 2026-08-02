# System Patterns — PREUVE

## 1. Matrice des statuts (2 dimensions indépendantes, affichées ensemble)

### Fiabilité (trust_level)
| Code | Libellé UI | Conditions |
|---|---|---|
| F1 | Déclaré, non vérifié (gris) | Identifiant unique + ≥ 1 photo, compte OTP |
| F2 | Documenté (bleu) | F1 + justificatif recevable + KYC complet (CNI + liveness) |
| F3 | Vérifié (vert) | F2 + contrôle croisé back-office (à terme : rapprochement institutionnel) |

### Statut de vie (life_status)
| Code | Libellé UI | Couleur |
|---|---|---|
| V-ACT | Actif | neutre |
| V-PRV | Enregistrement récent (contestation 30 j) | gris pointillé |
| V-LOC | En location — vente frauduleuse | ambre |
| V-VTE | Transfert en cours | bleu |
| V-VOL | Volé déclaré — ne pas acheter | rouge |
| V-LIT | Litige en cours — propriété contestée | rouge |
| V-FDV | Hors d'usage | gris |

### Transitions autorisées (toute autre = exception levée)
La matrice porte DEUX dimensions : le couple (statut d'origine, statut d'arrivée)
ET l'origine de la transition. Un même couple accepté pour un déclencheur est
refusé pour un autre. Implémentation : `StatusTransitionService::MATRIX`,
verrouillée par la table de vérité réécrite à la main dans
`tests/BusinessRules/MatriceTransitionsTest.php`.

| Depuis | Vers | Déclencheurs recevables |
|---|---|---|
| V-PRV | V-ACT | `system` (bascule automatique J+30 sans réclamation recevable) |
| V-PRV | V-LOC / V-VOL / V-FDV | `owner` |
| V-PRV | V-LIT | `claim` |
| V-ACT | V-LOC / V-VOL / V-FDV | `owner` |
| V-ACT | V-VTE | `transfer` |
| V-ACT | V-LIT | `claim`, `arbitration` — l'appel qui réforme un maintien doit pouvoir regeler (ajouté le 02/08/2026 : sans cela un appel ne pourrait jamais aboutir à « litige non tranché ») |
| V-LOC | V-ACT (démarquage) / V-VOL / V-FDV | `owner` |
| V-LOC | V-VTE | `transfer` |
| V-LOC | V-LIT | `claim` |
| V-VTE | V-ACT | `transfer` (double OTP), `owner` (annulation), `system` (expiration J+7) |
| V-VTE | V-PRV / V-LOC | `owner`, `system` — retour au statut antérieur |
| V-VTE | V-VOL | `owner` |
| V-VTE | V-LIT | `claim` |
| V-VOL | V-ACT | `owner` — levée par le MÊME détenteur (OTP), journalisée |
| V-VOL | V-LIT | `claim` |
| V-LIT | V-ACT | `arbitration` UNIQUEMENT — décision motivée |
| V-LIT | V-LIT | `arbitration` — maintien « litige non tranché » (écart < 20 pts), journalisé |
| V-FDV | V-ACT | `backoffice` UNIQUEMENT — libération de l'identifiant (ST-0606) |

Trois interdictions confirmées par Aboubakar le 02/08/2026 — ce sont des
décisions, pas des oublis :
1. `V-PRV → V-VTE` INTERDIT : un bien fraîchement enregistré ne peut pas partir
   en transfert, sinon la fenêtre de contestation de 30 jours perd son objet.
2. `V-VOL → V-FDV` INTERDIT : il faut d'abord lever le vol, sinon une
   déclaration de vol s'efface derrière une fin de vie.
3. `V-LIT` ne mène nulle part hors arbitrage : ni vol, ni transfert, ni fin de
   vie tant que la propriété est contestée.

Le transfert forcé décidé en arbitrage n'est pas une transition de statut mais
un changement de détenteur : archivage de l'actif et création du nouveau dans
la même transaction (voir §2).

Toute transition → ligne dans `asset_status_history` + entrée `AuditChain`,
dans la MÊME transaction (`AuditChain::transaction()`).

## 2. Unicité active (pattern MySQL)
```
UNIQUE (identifier_normalized, active_flag) avec active_flag ∈ {1, NULL}
```
Archivage (transfert/fin de vie) : dans UNE transaction avec `lockForUpdate()` →
`UPDATE assets SET active_flag = NULL` puis INSERT du nouvel actif.
Normalisation AVANT tout contrôle : majuscules, sans espaces/tirets, checksum VIN, Luhn IMEI.
Tentative de doublon : jamais de création → afficher fiche existante + parcours réclamation + notification `duplicate_attempt` au détenteur + journalisation.

## 3. Grille d'arbitrage (ClaimArbitrationService)
| Preuve | Poids |
|---|---|
| Document officiel nominatif (carte grise, ACD) cohérent avec le KYC | 40 |
| Récépissé de plainte / document judiciaire | 25 |
| Facture nominative d'achat | 15 |
| Antériorité documentaire (les dates des pièces priment sur la date plateforme) | 10 |
| Ancienneté/historique du compte | 5 |
| Photos horodatées / contexte | 5 |

Décision : écart ≥ 20 pts → transfert ou maintien ; < 20 pts → **« litige non tranché »** (V-LIT maintenu, renvoi justice, export PDF hashé). Falsification suspectée → poids 0 + escalade. Appel interne unique (agent senior différent, fenêtre 15 j).

## 4. Anonymat symétrique (règle produit inviolable)
- Le consultant ne voit JAMAIS l'identité du déclarant (statut public : verdict, fiabilité, ancienneté enregistrement, ancienneté compte).
- Le propriétaire ne voit JAMAIS l'identité du consultant — même acheteur d'un rapport payant.
- Rapport détaillé (payant) : historique des statuts, NOMBRE de détenteurs et dates de transfert (jamais les identités), incidents.
- Levée d'anonymat : uniquement autorité judiciaire sur réquisition (dossier complet).

## 5. Notifications (NotificationService)
- `asset_lookup` : job d'agrégation HORAIRE des lookups par bien → une notification avec compteur ("consultée 3 fois aujourd'hui"). Jamais unitaire, jamais d'identité/IP.
- `asset_report_purchased` : immédiate, anonyme.
- `duplicate_attempt`, `lookup_spike` : immédiates, push + SMS (événements critiques).
- Respect des préférences utilisateur (opt-out par type, SMS réservé au critique).

## 6. Chaîne d'audit (AuditChain::append)
`record_hash = SHA-256(forme canonique de TOUTES les colonnes métier)` puis `chain_hash = SHA-256(prev_hash || record_hash)`.

Amendé le 01/08/2026 : la formule d'origine ne hachait que la charge utile, laissant l'acteur, l'action, l'entité et l'horodatage réécrivables sans détection — démontré en revue. Le payload entre sous sa forme d'octets stockée, jamais décodé puis ré-encodé, et `created_at` en `DATETIME` avec fuseau épinglé à UTC : sinon l'empreinte dépendrait de la version de PHP ou du fuseau de la session.

Écritures concurrentes sérialisées par **verrou nommé** — le motif `ORDER BY id DESC LIMIT 1 FOR UPDATE` produit des interblocages en cascade par verrous d'intervalle. Table append-only, sans FK, protégée par des déclencheurs MariaDB `BEFORE UPDATE`/`BEFORE DELETE`.

Job quotidien : ancrage du hash de tête en externe (e-mail horodaté + stockage séparé). **Tant que cet ancrage n'existe pas, la chaîne n'est pas opposable** : l'algorithme est public et sans secret, donc quiconque a le droit `INSERT` peut forger une chaîne cohérente.

## 7. Accès au rapport détaillé
Chemin unique : paiement validé → `report_purchases` (access_token CHAR(40), expiration 30 j, compteur d'accès). Invité : `payments.buyer_name/email/phone` obligatoires (CHECK en base) + OTP téléphone AVANT paiement. Après achat : proposer la conversion en compte.

## 8. Anti-profilage consultation
Rate limit Redis : 10 lookups/heure par IP anonyme, CAPTCHA au-delà, comptes authentifiés non limités. `lookups.ip_hash = SHA-256(IP + sel quotidien)`. Purge > 12 mois (politique ARTCI).
