# Active Context — PREUVE

> Dernière mise à jour : 01/08/2026 — cadrage BMAD terminé, développement non démarré.

## Où on en est
Phase de cadrage COMPLÈTE :
- ✅ Project Brief BMAD v1.0 (docx) — vision, confiance graduée, module réclamation
- ✅ PRD v1.0 (docx) — matrice des statuts, workflows, grille d'arbitrage, 38 exigences
- ✅ Schéma MySQL v1.1 — 14 tables validées (dont notifications, report_purchases, guest checkout)
- ✅ Backlog v1.1 (md) — 59 stories / 294 pts / 13 sprints / jalons S3-S7-S10-S13
- ✅ 3 prototypes HTML (Tampon / Sceau / Feu Vert) — direction finale non arbitrée
- ⏳ Benchmark concurrentiel fait (CarVertical/Carfax, CEIR/LostPhoneKE/Veriphye, IDUFCI/MCLU)

## Décisions récentes (à ne pas rediscuter)
1. Backend **Laravel 11 + MySQL 8** (pas PostgreSQL) — pattern unicité via `(identifier_normalized, active_flag)`.
2. Enregistrement d'un bien : **authentification obligatoire** (OTP). Consultation : jamais.
3. Notifications de consultation : **agrégées, anonymes**, in-app + push, SMS réservé au critique.
4. Rapport détaillé : accessible **connecté OU invité identifié** (nom+email+téléphone OTP avant paiement), accès par token 30 j.
5. Anonymat **symétrique** : l'acheteur du rapport ne voit pas le déclarant, le propriétaire ne voit pas l'acheteur.
6. Verticale de lancement : **loueurs B2B** ; foncier repoussé phase 2.

## Prochaines actions (Sprint 1 — EP-01 Fondations, 16 pts)
1. Init repo Laravel 11 + CI (Pest, Larastan, Pint) + docker-compose (MySQL 8, Redis, MinIO)
2. Migrations conformes à `preuve_schema_mysql8.sql` (14 tables) + seeders de démo (6 biens couvrant les 6 états)
3. ST-0101/ST-0102 : auth OTP complète (request/verify, anti-brute-force, Sanctum)
4. ST-0106 : `AuditChain::append()` + tests de continuité de chaîne + job AnchorAuditHead
5. Enums + `StatusTransitionService` avec matrice verrouillée par tests

## Questions ouvertes (à trancher avec Aboubakar)
- Direction design finale (Tampon vs Feu Vert selon cible de lancement) → conditionne le design system Flutter
- Fournisseur SMS OTP (coût/fiabilité CI) — candidat à benchmarker
- Nom définitif « Preuve » : vérifier marque OAPI + domaine (preuve.ci ?)
- Tarif exact rapport détaillé (500 vs 1000 FCFA) et paliers abonnement flotte
