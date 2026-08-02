# Progress — PREUVE

> Convention : mettre à jour ce fichier en fin de session Claude Code significative.
> Statuts : ⬜ à faire · 🟨 en cours · ✅ terminé · ❌ bloqué (avec raison)

## Jalon 1 — Fondations (fin S3)
- ✅ Repo Laravel 12 + CI (Pest, Larastan, Pint) ; base locale MariaDB sans Docker (`docs/infrastructure/dev-local-mariadb.md`)
- 🟨 Migrations conformes au schéma v1.1 : users, otp_codes, sessions, audit_log, asset_categories, category_fields, companies, assets, asset_status_history, lookups, notifications (+ app_settings, personal_access_tokens) — manquent asset_documents, transfers, claims, claim_evidences, watch_alerts, payments, report_purchases ; ✅ jeu de démonstration (7 statuts, 3 niveaux, 4 comptes)
- ✅ ST-0101/0102 Auth OTP : `OtpService` (HMAC APP_KEY, verrouillage progressif par destination), endpoints `auth/otp/request|verify`, `auth/me`, `auth/logout`, jetons Sanctum — reste à brancher un vrai fournisseur SMS
- ✅ ST-0106 AuditChain + tests de continuité + ancrage externe quotidien (2 canaux) + `preuve:verify-audit-chain` — reste à CONFIGURER les canaux en production, sans quoi la chaîne n'est pas opposable
- ✅ Règle 3 — unicité active `(identifier_normalized, active_flag)` verrouillée par test
- ✅ Règle 6 — `StatusTransitionService` : matrice verrouillée par table de vérité, historique + audit dans la même transaction
- ✅ ST-0103 KYC : CNI recto/verso + selfie, n° haché (HMAC), extraction Mindee configurable et non bloquante, revue agent, doublon de pièce refusé · ⚠️ vivacité appréciée à l'œil, pas de détection automatique
- ⬜ ST-0104 Comptes entreprise (RCCM, validation back-office)
- ⬜ ST-0105 Droits Loi 2013-450 · ✅ ST-0107 Préférences notifications

## Jalon 2 — Alpha interne (fin S7) : démo aux 5 loueurs pilotes
- 🟨 EP-02 Enregistrement express : ✅ ST-0201 (4 gestes, F1/V-PRV, télémétrie CT-02), ST-0203 (normalisation, détection de type), ST-0204 (unicité, collision → fiche + réclamation) · ✅ ST-0205 (tentative journalisée + détenteur alerté anonymement), ST-0207 (dépôt + jauge), ST-0208 (file de revue, motif obligatoire) · ⬜ ST-0202 OCR Mindee, ST-0206 uploads différés
- ✅ EP-03 Consultation 2 clics : ST-0301 (champ unique, détection auto), ST-0302 (verdict 6 états + couleur), ST-0303 (inconnu ≠ rassurant), ST-0304 (journal, IP salée, purge 12 mois), ST-0305 (10/h anonymes — CAPTCHA à brancher côté client), ST-0306 (consultation par `public_ref`)
- 🟨 EP-04 Confiance graduée : ✅ ST-0401 moteur F1-F3 versionné · ✅ ST-0402 job J+30 · ✅ ST-0403 veille, ST-0405 pics · ✅ ST-0404 signaux temporels
- ✅ EP-10 (partiel) ST-1001 centre in-app (fil, badge, marquage lu) + ST-1002 agrégation horaire anonyme · ✅ ST-0107 préférences (types critiques non désactivables) · ✅ ST-1003 push FCM, ST-1004 SMS critique (coût tracé par type)

## Jalon 3 — Bêta fermée (fin S10)
- 🟨 ST-0901 Back-office : socle posé (rôles `user`/`agent`/`admin`, middleware, réglages chiffrés, configuration de la passerelle SMS)
- ✅ EP-05 Réclamation & arbitrage (ST-0501 à ST-0506) : dépôt KYC, recevabilité auto ou manuelle, gel V-LIT immédiat, contradictoire J+15, grille pondérée, 3 issues, appel unique, export empreint · ✅ relances J+7/J+13 + instruction sur pièces au terme · ⬜ frais de dossier (EP-08)
- ✅ EP-06 Transferts (ST-0601 à ST-0606) : double OTP, archivage + création en une transaction, expiration J+7 planifiée, chaîne des détenteurs, vol en un geste, levée, fin de vie
- ⬜ ST-0901 Back-office admin de base

## Jalon 4 — Lancement public (fin S13)
- 🟨 EP-07 Offre flotte B2B : ✅ ST-0701 import CSV (partiel, borné, rapport d'erreurs), ST-0702 marquage en masse, ST-0703 tableau de bord, ST-0704 alertes remontées · ✅ ST-0705 délégation aux collaborateurs
- 🟨 EP-08 Monétisation : ✅ ST-0801 rapport détaillé anonymisé, ST-0802 guest checkout OTP, ST-0803 notification anonyme, ST-0804 quota non bloquant, ST-0805 paliers de flotte, ST-0806 webhooks signés idempotents · ✅ relances d'abonnement, suspension douce, frais de dossier · ⬜ facture fiscale conforme (mentions à valider par un comptable)
- 🟨 EP-09 : ✅ ST-0901 (validation entreprises, actions tracées), ST-0902 anti-fraude, ST-0903 télémétrie CT-01/CT-02, sonde de santé · ⬜ ST-0904 sauvegardes/PRA à mettre en place (documenté)
- ✅ EP-10 (fin) Push FCM + SMS critique branchés

## Journal des sessions
| Date | Travail réalisé | Stories touchées | Notes / décisions |
|---|---|---|---|
| 01/08/2026 | Cadrage BMAD complet (brief, PRD, schéma v1.1, backlog, prototypes, Memory Bank) | — | Développement non démarré ; questions ouvertes dans activeContext.md |
| 02/08/2026 | Socle des biens : companies, assets, unicité active ; asset_status_history + StatusTransitionService (matrice complète) ; base de dev MariaDB locale sans Docker | ST-0106, ST-0402, ST-0503, ST-0601, ST-0604, ST-0606, ST-0702 (socle des transitions) | 3 interdictions de la matrice confirmées par Aboubakar, consignées dans systemPatterns.md §1 |
| 02/08/2026 | Authentification par OTP : Sanctum, OtpService, endpoints request/verify/me/logout | ST-0101, ST-0102 | Fournisseur SMS non arbitré → interface `OtpSender`, implémentation de développement qui refuse la production. `composer audit` : 3 avis sur laravel/framework, dont un « high », sans correctif sur la branche 11 |
| 02/08/2026 | Enregistrement express : `AssetRegistrationService`, `POST /api/v1/assets`, `PublicAssetResource`, filtrage des champs sur le catalogue de catégories | ST-0201, ST-0203, ST-0204 | ST-0205 limitée à la journalisation de la tentative : la notification au détenteur attend la table `notifications` |
| 02/08/2026 | Transports push FCM et SMS critique, jetons d'appareil, journal de coût SMS | ST-1003, ST-1004 | Coût tracé par type d'alerte, jamais par destinataire ; SMS sans détail — il s'affiche sur écran verrouillé |
| 02/08/2026 | ST-0705 : `company_members`, `CompanyMemberService`, invitation par numéro, rôles admin/opérateur | ST-0705 | Les actes de propriété ne se délèguent jamais : un téléphone d'employé volé ne doit pas coûter le parc |
| 02/08/2026 | Abonnements de flotte : relances échelonnées, suspension douce en lecture seule, frais de dossier de réclamation, décompte mensuel | ST-0805, ST-0501 (frais) | La suspension ne retire jamais la protection acquise : couper punirait les véhicules, pas le débiteur |
| 02/08/2026 | Mesure CT-01 sur volume : `preuve:benchmark-lookup`, 1 ms au p95 sur 200 k biens + 1 M de consultations | CT-01, ST-0903 | Mesure serveur uniquement : ni latence 3G, ni charge concurrente, ni hébergement cible |
| 02/08/2026 | ST-0404 : `AgeBracket`, signaux temporels sur le verdict public | ST-0404 | Tranches et non dates : une date exacte de création de compte aiderait à identifier le déclarant |
| 02/08/2026 | EP-09 (cœur) : `TelemetryService` (centiles), `FraudSignalsService`, validation des entreprises, sonde de santé publique, doc d'exploitation | ST-0901 à ST-0903 | Mesure CT-01 côté serveur : ne couvre pas la latence 3G, seule part maîtrisée |
| 02/08/2026 | EP-07 (cœur) : `FleetService`, import CSV partiellement abouti et borné, marquage en masse, tableau de bord | ST-0701 à ST-0704 | Import borné à 200 lignes : chaque ligne prend le verrou d'audit |
| 02/08/2026 | EP-08 (cœur) : `payments`/`report_purchases`, rapport anonymisé, guest checkout OTP avant paiement, webhooks signés idempotents, quotas et paliers de flotte | ST-0801 à ST-0806 | Quota volontairement NON bloquant : un bien non enregistré est un bien non protégé |
| 02/08/2026 | Relances du contradictoire (J+7, J+13) et passage en instruction au terme du délai | ST-0504 | Le silence ne ferme pas le dossier : il le fait instruire sur pièces, bien toujours gelé |
| 02/08/2026 | EP-05 : `ClaimArbitrationService`, grille pondérée, 3 issues, appel par un autre agent, export empreint ; `TransferService::handOver()` extrait comme point de passage unique de la règle 3 | ST-0501 à ST-0506 | Matrice amendée : `V-ACT → V-LIT` par `arbitration`, sans quoi un appel ne pouvait aboutir à « non tranché » |
| 02/08/2026 | EP-06 complet : `TransferService` (règle 3 : archivage + création en une transaction sous verrou), `AssetLifecycleService` (vol/levée/fin de vie), job d'expiration, endpoints | ST-0601 à ST-0606 | Le bien transféré repart en F1 : les justificatifs appuyaient la propriété du vendeur |
| 02/08/2026 | `DemoSeeder` : 7 statuts, 3 niveaux, comptes de démo ; correction de la fabrique et du seeder du squelette Laravel (colonnes inexistantes) | jalon S7 | Le seeder n'écrit pas dans la chaîne d'audit : y mettre des actions fictives fabriquerait de fausses preuves |
| 02/08/2026 | Veille sur identifiant (`watch_alerts`) et détection des pics de consultation ; endpoints de veille, job horaire | ST-0403, ST-0405 | Veille réservée au détenteur actuel OU passé : une veille libre permettrait de surveiller le bien d'autrui |
| 02/08/2026 | `preuve:promote-provisional` (V-PRV → V-ACT au terme des 30 j) ; planification durcie : `withoutOverlapping` et minutes décalées sur toutes les tâches qui écrivent | ST-0402 | La condition « sans réclamation recevable » est portée par la matrice (V-LIT hors périmètre), non dupliquée dans le job |
| 02/08/2026 | Ancrage externe de la chaîne d'audit : `audit_anchors` append-only, canaux courrier et stockage, commandes d'ancrage et de vérification, écran d'administration | ST-0106 | Un test démontre qu'une chaîne reconstruite passe la vérification interne : seul l'ancrage la démasque |
| 02/08/2026 | KYC : `kyc_submissions`, `KycService`, lecteur Mindee configurable, endpoints utilisateur et file de revue, recalcul en cascade des biens | ST-0103 | Débloque F2 en conditions réelles. Vivacité du selfie non automatisée |
| 02/08/2026 | `TrustLevelEngine` versionné, `asset_documents`, dépôt de justificatifs, jauge, file de revue agent, contrôle croisé F3 ; unification de config/preuve.php | ST-0401, ST-0207, ST-0208 | F2 reste inatteignable en réel tant que ST-0103 (KYC Mindee) ne fait pas passer `kyc_status` à « verified » |
| 02/08/2026 | Notifications : table, `NotificationService`, agrégation horaire, centre in-app, préférences, alerte de doublon branchée | ST-1001, ST-1002, ST-0107, ST-0205 (complète) | Transports FCM/SMS non branchés : seul l'in-app est réellement délivré |
| 02/08/2026 | EP-03 consultation publique : `LookupService`, `LookupResult`, `GET /api/v1/lookup/{identifier}`, table `lookups`, purge planifiée | ST-0301 à ST-0306 | CAPTCHA non branché : le 429 porte `captcha_required`, le défi reste à intégrer côté client |
| 02/08/2026 | Laravel 11 → 12 (clôt 3 avis de sécurité) ; rôles de back-office + `preuve:role` ; `app_settings` chiffrés ; passerelle SMS configurable et testable depuis l'espace administrateur | socle ST-0901, débloque ST-0101/0102 en réel | Le fournisseur `http` est générique (gabarit de requête) : aucun agrégateur n'est codé en dur, le choix reste une décision d'exploitation |
