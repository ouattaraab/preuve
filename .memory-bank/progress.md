# Progress — PREUVE

> Convention : mettre à jour ce fichier en fin de session Claude Code significative.
> Statuts : ⬜ à faire · 🟨 en cours · ✅ terminé · ❌ bloqué (avec raison)

## Jalon 1 — Fondations (fin S3)
- ⬜ Repo Laravel 11 + CI + docker-compose (MySQL 8, Redis, MinIO)
- ⬜ Migrations 14 tables conformes au schéma v1.1 + seeders 6 états de démo
- ⬜ ST-0101/0102 Auth OTP (request/verify, anti-brute-force, Sanctum)
- ⬜ ST-0106 AuditChain + ancrage quotidien + tests de continuité
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
