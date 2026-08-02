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
- 🟨 EP-04 Confiance graduée : ✅ ST-0401 moteur F1-F3 versionné · ✅ ST-0402 job J+30 · ✅ ST-0403 veille, ST-0405 pics · ⬜ ST-0404 signaux temporels
- ✅ EP-10 (partiel) ST-1001 centre in-app (fil, badge, marquage lu) + ST-1002 agrégation horaire anonyme · ✅ ST-0107 préférences (types critiques non désactivables) · ⬜ ST-1003 push FCM, ST-1004 SMS critique (S13)

## Jalon 3 — Bêta fermée (fin S10)
- 🟨 ST-0901 Back-office : socle posé (rôles `user`/`agent`/`admin`, middleware, réglages chiffrés, configuration de la passerelle SMS)
- ⬜ EP-05 Réclamation & arbitrage (dépôt, gel V-LIT, contradictoire, grille, export hashé, appel)
- ✅ EP-06 Transferts (ST-0601 à ST-0606) : double OTP, archivage + création en une transaction, expiration J+7 planifiée, chaîne des détenteurs, vol en un geste, levée, fin de vie
- ⬜ ST-0901 Back-office admin de base

## Jalon 4 — Lancement public (fin S13)
- ⬜ EP-07 Offre flotte B2B (import Excel, dashboard, marquage masse, alertes, rôles)
- ⬜ EP-08 Monétisation (rapport détaillé, guest checkout OTP, quotas, abonnements, webhooks idempotents)
- ⬜ EP-09 Observabilité (anti-fraude, télémétrie CT-01/CT-02, sauvegardes/PRA)
- ⬜ EP-10 (fin) Push FCM + SMS critique

## Journal des sessions
| Date | Travail réalisé | Stories touchées | Notes / décisions |
|---|---|---|---|
| 01/08/2026 | Cadrage BMAD complet (brief, PRD, schéma v1.1, backlog, prototypes, Memory Bank) | — | Développement non démarré ; questions ouvertes dans activeContext.md |
| 02/08/2026 | Socle des biens : companies, assets, unicité active ; asset_status_history + StatusTransitionService (matrice complète) ; base de dev MariaDB locale sans Docker | ST-0106, ST-0402, ST-0503, ST-0601, ST-0604, ST-0606, ST-0702 (socle des transitions) | 3 interdictions de la matrice confirmées par Aboubakar, consignées dans systemPatterns.md §1 |
| 02/08/2026 | Authentification par OTP : Sanctum, OtpService, endpoints request/verify/me/logout | ST-0101, ST-0102 | Fournisseur SMS non arbitré → interface `OtpSender`, implémentation de développement qui refuse la production. `composer audit` : 3 avis sur laravel/framework, dont un « high », sans correctif sur la branche 11 |
| 02/08/2026 | Enregistrement express : `AssetRegistrationService`, `POST /api/v1/assets`, `PublicAssetResource`, filtrage des champs sur le catalogue de catégories | ST-0201, ST-0203, ST-0204 | ST-0205 limitée à la journalisation de la tentative : la notification au détenteur attend la table `notifications` |
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
