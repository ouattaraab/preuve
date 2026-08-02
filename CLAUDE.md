# CLAUDE.md — Projet PREUVE

> Registre déclaratif de propriété et de statut des biens (Côte d'Ivoire).
> Éditeur : OVERNETFLOW. Langue de travail : **français** (code en anglais, commentaires métier en français).

## Démarrage de session

Lis TOUJOURS dans cet ordre avant toute tâche :
1. `.memory-bank/activeContext.md` — où on en est, quoi faire maintenant
2. `.memory-bank/systemPatterns.md` — règles métier NON NÉGOCIABLES
3. `.memory-bank/techContext.md` — stack et conventions techniques
En fin de session significative, mets à jour `activeContext.md` et `progress.md`.

## Stack (ne pas dévier)

- **Backend** : Laravel 11 (API REST), PHP 8.3, **MySQL 8.0+** (InnoDB, utf8mb4_0900_ai_ci), Redis + Horizon (queues), Sanctum (auth OTP, pas de mot de passe au MVP)
- **Mobile** : Flutter (iOS/Android)
- **Web public** : front léger orienté consultation/SEO (pages de statut par `public_ref`)
- **Stockage objets** : MinIO (documents chiffrés au repos) · **OCR/KYC** : Mindee · **Secrets** : Vault
- **Paiements** : Paystack + PawaPay (Wave, Orange Money, MTN MoMo). **CinetPay est INTERDIT.**
- Schéma de référence : `database/preuve_schema_mysql8.sql` (14 tables) — les migrations doivent lui rester conformes

## Règles métier absolues (violations = bug critique)

1. **Consultation de statut** : gratuite, anonyme, SANS compte. Ne jamais exiger d'auth pour `GET /api/v1/lookup/{identifier}`.
2. **Toute écriture** (enregistrer, transférer, réclamer, déclarer un vol) : utilisateur authentifié (Sanctum + OTP).
3. **Un identifiant = un enregistrement actif** : index unique `(identifier_normalized, active_flag)` où `active_flag ∈ {1, NULL}`. Archivage = `active_flag = NULL` + création du nouvel actif dans la MÊME transaction avec `lockForUpdate()`.
4. **Identité jamais divulguée publiquement** : ni le déclarant au consultant, ni le consultant au propriétaire — même quand quelqu'un paie. Anonymat strict et symétrique.
5. **Chaîne d'audit** : toute action sensible passe par `AuditChain::append()`. L'empreinte `record_hash` couvre **toutes les colonnes métier** de la ligne — acteur, action, entité, charge utile, horodatage — et non la seule charge utile : sans quoi « qui a fait quoi, sur quel bien, à quelle date » serait réécrivable sans détection. `chain_hash = SHA-256(prev_hash || record_hash)`. Écritures concurrentes sérialisées par verrou nommé. Table `audit_log` append-only, protégée par des déclencheurs MariaDB : AUCUN update/delete, quel que soit le chemin.
6. **Transitions de statut** : uniquement celles autorisées par la matrice (voir systemPatterns.md). Toute transition écrit dans `asset_status_history`.
7. **Rapport détaillé** : accès exclusivement via `report_purchases.access_token`. Acheteur = compte connecté OU trio nom+email+téléphone avec OTP vérifié AVANT paiement.
8. **Données personnelles (Loi 2013-450)** : IP jamais en clair (hash salé quotidien), n° CNI stocké uniquement en SHA-256, minimisation partout.

## Exigences UX transverses (critères d'acceptation de CHAQUE feature)

- CT-01 : consultation en ≤ 2 interactions, résultat < 1 s P95
- CT-02 : enregistrement d'un bien < 90 s au temps médian (télémétrie obligatoire)
- CT-04 : statuts en langage courant avec code couleur ("Volé déclaré", jamais "V-VOL" côté UI)
- CT-05 : utilisable en 3G, uploads en file différée avec reprise
- CT-06 : friction proportionnée au risque (nulle en consultation, forte uniquement pour KYC/transfert/réclamation)

## Conventions de code

- Contrôleurs fins → logique dans `app/Services/` (ex. `AssetRegistrationService`, `ClaimArbitrationService`, `AuditChain`)
- Machines à états : enums PHP 8.3 (`LifeStatus`, `TrustLevel`) + transitions validées dans un `StatusTransitionService` unique
- Toute écriture concurrente sensible : `DB::transaction()` + `lockForUpdate()`
- Webhooks paiement : idempotents (contrainte `uq (provider, provider_ref)`), traités via queue Horizon dédiée
- Tests : Pest. Chaque règle métier absolue ci-dessus a un test qui vérifie sa violation impossible
- Migrations : jamais de `down()` destructif sur les tables d'audit et d'historique

## Backlog et livraison

Backlog de référence : `docs/PREUVE_Backlog_v1.1.md` (59 stories, 294 pts, 13 sprints).
Toujours rattacher le travail à un ID de story (ST-xxxx). Jalons : S3 Fondations, S7 Alpha (démo loueurs), S10 Bêta fermée, S13 Lancement public.
