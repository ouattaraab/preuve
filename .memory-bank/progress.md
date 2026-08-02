# Progress — PREUVE

> Convention : mettre à jour ce fichier en fin de session Claude Code significative.
> Statuts : ⬜ à faire · 🟨 en cours · ✅ terminé · ❌ bloqué (avec raison)

## Jalon 1 — Fondations (fin S3)
- ✅ Repo Laravel 11 + CI (Pest, Larastan, Pint) ; base locale MariaDB sans Docker (`docs/infrastructure/dev-local-mariadb.md`)
- 🟨 Migrations conformes au schéma v1.1 : users, otp_codes, sessions, audit_log, asset_categories, category_fields, companies, assets, asset_status_history (9/14) — seeders 6 états à faire
- ✅ ST-0101/0102 Auth OTP : `OtpService` (HMAC APP_KEY, verrouillage progressif par destination), endpoints `auth/otp/request|verify`, `auth/me`, `auth/logout`, jetons Sanctum — reste à brancher un vrai fournisseur SMS
- 🟨 ST-0106 AuditChain + tests de continuité ✅ — job d'ancrage quotidien ⬜ (**sans lui, la chaîne n'est pas opposable**)
- ✅ Règle 3 — unicité active `(identifier_normalized, active_flag)` verrouillée par test
- ✅ Règle 6 — `StatusTransitionService` : matrice verrouillée par table de vérité, historique + audit dans la même transaction
- ⬜ ST-0103 KYC Mindee (CNI + liveness, CNI hashée)
- ⬜ ST-0104 Comptes entreprise (RCCM, validation back-office)
- ⬜ ST-0105 Droits Loi 2013-450 · ST-0107 Préférences notifications

## Jalon 2 — Alpha interne (fin S7) : démo aux 5 loueurs pilotes
- ⬜ EP-02 Enregistrement express (4 gestes, OCR, unicité, uploads différés, renforcement F2/F3)
- ⬜ EP-03 Consultation 2 clics (lookup < 1 s, verdict 6 états, rate limit, pages SEO)
- ⬜ EP-04 Confiance graduée (moteur F1-F3, V-PRV J+30, veille, signaux temporels)
- ⬜ EP-10 (partiel) Centre de notifications in-app + agrégation horaire des consultations

## Jalon 3 — Bêta fermée (fin S10)
- ⬜ EP-05 Réclamation & arbitrage (dépôt, gel V-LIT, contradictoire, grille, export hashé, appel)
- ⬜ EP-06 Transferts double OTP + chaîne des détenteurs + vol un geste + V-FDV
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
