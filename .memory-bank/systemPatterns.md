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
- `V-PRV → V-ACT` : automatique J+30 sans réclamation recevable (job planifié)
- `V-ACT|V-PRV|V-LOC → V-LIT` : UNIQUEMENT recevabilité d'une réclamation
- `V-LIT → V-ACT | transfert forcé | V-LIT maintenu` : UNIQUEMENT décision d'arbitrage motivée
- `* → V-VOL` : par le détenteur (OTP) ; levée par le MÊME détenteur (OTP), journalisée
- `V-VTE → V-ACT (nouveau détenteur)` : double OTP vendeur+acheteur ; expiration J+7 → retour état antérieur
- Toute transition → ligne dans `asset_status_history` + entrée `AuditChain`

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
`chain_hash = SHA-256(prev_hash || payload_hash)` — lecture du dernier `chain_hash` sous verrou pour garantir la continuité. Table append-only, sans FK. Job quotidien : ancrage du hash de tête en externe (e-mail horodaté + stockage séparé).

## 7. Accès au rapport détaillé
Chemin unique : paiement validé → `report_purchases` (access_token CHAR(40), expiration 30 j, compteur d'accès). Invité : `payments.buyer_name/email/phone` obligatoires (CHECK en base) + OTP téléphone AVANT paiement. Après achat : proposer la conversion en compte.

## 8. Anti-profilage consultation
Rate limit Redis : 10 lookups/heure par IP anonyme, CAPTCHA au-delà, comptes authentifiés non limités. `lookups.ip_hash = SHA-256(IP + sel quotidien)`. Purge > 12 mois (politique ARTCI).
