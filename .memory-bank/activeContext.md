# Active Context — PREUVE

> Dernière mise à jour : 02/08/2026 — Sprint 1 en cours sur `feat/lot1-socle`.

## Où on en est
Cadrage COMPLET (brief BMAD, PRD, schéma MySQL v1.1, backlog v1.1, prototypes).
Développement du socle en cours :
- ✅ Application Laravel 11 configurée pour hébergement mutualisé (sans Redis), CI Pest + Larastan + Pint
- ✅ Enums du domaine (`LifeStatus`, `TrustLevel`, `ActorType`, `OtpPurpose`, `TriggerType`) avec libellés en langage courant (CT-04)
- ✅ Tables `users`, `otp_codes`, `sessions` (jamais d'IP en clair), `audit_log`
- ✅ ST-0106 `AuditChain` : empreinte sur toutes les colonnes métier, verrou nommé, déclencheurs d'inaltérabilité, `transaction()` pour englober une action métier
- ✅ `IdentifierNormalizer` : VIN (checksum), IMEI (Luhn), plaques
- ✅ Catégories de biens dynamiques servies par configuration distante (décision D6)
- ✅ Tables `companies` et `assets` + unicité active `(identifier_normalized, active_flag)` verrouillée par test
- ✅ `asset_status_history` + `StatusTransitionService` : matrice des transitions verrouillée par une table de vérité écrite à la main dans les tests

## Décisions récentes (à ne pas rediscuter)
1. Backend **Laravel 11 + MariaDB** (pas PostgreSQL) — unicité via `(identifier_normalized, active_flag)`.
2. Enregistrement d'un bien : **authentification obligatoire** (OTP). Consultation : jamais.
3. Notifications de consultation : **agrégées, anonymes**, in-app + push, SMS réservé au critique.
4. Rapport détaillé : accessible **connecté OU invité identifié** (nom+email+téléphone OTP avant paiement), accès par token 30 j.
5. Anonymat **symétrique** : l'acheteur du rapport ne voit pas le déclarant, le propriétaire ne voit pas l'acheteur.
6. Verticale de lancement : **loueurs B2B** ; foncier repoussé phase 2.
7. Base de développement locale : **MariaDB Homebrew sur le port 3307**, isolée d'une éventuelle installation MySQL — voir `docs/infrastructure/dev-local-mariadb.md`. Pas de Docker.
8. **Matrice des transitions** : 3 interdictions confirmées le 02/08/2026 — `V-PRV → V-VTE`, `V-VOL → V-FDV`, et toute sortie de `V-LIT` hors arbitrage. Détail et justification dans systemPatterns.md §1.
9. Colonnes d'horodatage métier en **DATETIME UTC** et non TIMESTAMP (`audit_log`, `asset_status_history`) : leur représentation textuelle est hachée ou rapprochée dans les exports, elle ne doit pas dépendre du fuseau de la session.

## Prochaines actions (Sprint 1 — EP-01 Fondations)
1. ST-0101/ST-0102 : auth OTP complète (request/verify, anti-brute-force, Sanctum)
2. `TrustLevelEngine` (F1/F2/F3) et `asset_documents` — renforcement du niveau de fiabilité
3. `AssetRegistrationService` : enregistrement en 4 gestes, F1/V-PRV, tentative de doublon → fiche existante + parcours réclamation
4. Job `AnchorAuditHead` (ancrage quotidien externe du hash de tête) — **tant qu'il n'existe pas, la chaîne n'est pas opposable**
5. Seeders de démo : 6 biens couvrant les 6 états

## Questions ouvertes (à trancher avec Aboubakar)
- Direction design finale (Tampon vs Feu Vert selon cible de lancement) → conditionne le design system Flutter
- Fournisseur SMS OTP (coût/fiabilité CI) — candidat à benchmarker
- Nom définitif « Preuve » : vérifier marque OAPI + domaine (preuve.ci ?)
- Tarif exact rapport détaillé (500 vs 1000 FCFA) et paliers abonnement flotte
