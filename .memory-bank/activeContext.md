# Active Context — PREUVE

> Dernière mise à jour : 02/08/2026 — Sprint 1 en cours sur `feat/lot1-socle`.

## Où on en est
Cadrage COMPLET (brief BMAD, PRD, schéma MySQL v1.1, backlog v1.1, prototypes).
Développement du socle en cours :
- ✅ Application Laravel 12 configurée pour hébergement mutualisé (sans Redis), CI Pest + Larastan + Pint
- ✅ Enums du domaine (`LifeStatus`, `TrustLevel`, `ActorType`, `OtpPurpose`, `TriggerType`) avec libellés en langage courant (CT-04)
- ✅ Tables `users`, `otp_codes`, `sessions` (jamais d'IP en clair), `audit_log`
- ✅ ST-0106 `AuditChain` : empreinte sur toutes les colonnes métier, verrou nommé, déclencheurs d'inaltérabilité, `transaction()` pour englober une action métier
- ✅ `IdentifierNormalizer` : VIN (checksum), IMEI (Luhn), plaques
- ✅ Catégories de biens dynamiques servies par configuration distante (décision D6)
- ✅ Tables `companies` et `assets` + unicité active `(identifier_normalized, active_flag)` verrouillée par test
- ✅ `asset_status_history` + `StatusTransitionService` : matrice des transitions verrouillée par une table de vérité écrite à la main dans les tests
- ✅ ST-0101/ST-0102 : authentification par OTP (Sanctum), anti-brute-force par destination, `auth/otp/request|verify`, `auth/me`, `auth/logout`
- ✅ ST-0201/ST-0203/ST-0204 : `AssetRegistrationService` + `POST /api/v1/assets` — F1/V-PRV, normalisation, doublon → fiche existante + réclamation, `PublicAssetResource` (point de passage unique de l'anonymat)
- ✅ **EP-05 Réclamation & arbitrage** : `claims`/`claim_evidences`, recevabilité large, gel immédiat, grille 40/25/15/10/5/5, seuil de 20 points, 3 issues, appel unique par un autre agent, export empreint remis aux deux parties, relances J+7/J+13 puis instruction sur pièces
- ✅ **EP-06 Transferts** : `transfers`, double OTP, archivage + création dans la MÊME transaction (règle 3), expiration J+7, chaîne des détenteurs · vol en un geste, levée par le déclarant, fin de vie
- ✅ Jeu de démonstration (`DemoSeeder`) : 7 statuts, 3 niveaux de fiabilité, comptes particulier/loueur/agent/admin — n'écrit rien dans la chaîne d'audit
- ✅ ST-0403/ST-0405 : `watch_alerts` + `WatchAlertService` (veille réservée au détenteur actuel OU passé), `preuve:detect-lookup-spikes` (seuil configurable, alerte agrégée et anonyme, silence anti-répétition)
- ✅ ST-0402 : `preuve:promote-provisional` — bascule V-PRV → V-ACT au terme des 30 jours, bornée, notifiée ; planification durcie (`withoutOverlapping`, minutes décalées)
- ✅ ST-0106 (complète) : ancrage externe quotidien du hash de tête (courrier horodaté + stockage séparé), registre append-only, `preuve:verify-audit-chain` — voir `docs/infrastructure/ancrage-chaine-audit.md`
- ✅ ST-0103 : KYC (CNI recto/verso + selfie), n° de pièce haché HMAC jamais en clair, extraction Mindee configurable et non bloquante, revue par agent, recalcul en cascade des biens
- ✅ ST-0401/ST-0207/ST-0208 : `TrustLevelEngine` (règles versionnées, redescente possible), `asset_documents`, dépôt + jauge, file de revue des agents, contrôle croisé F3
- ✅ EP-03 (ST-0301 à ST-0306) : `LookupService` + `GET /api/v1/lookup/{identifier}` — sans auth, par identifiant OU référence publique, IP hachée salée quotidiennement, plafond de 10/h par visiteur anonyme avec CAPTCHA au-delà, purge planifiée à 12 mois
- ✅ EP-10 (partiel) + ST-0107 + ST-0205 : table `notifications`, `NotificationService` (anonymat symétrique, agrégation), job horaire `preuve:aggregate-lookups`, centre in-app et préférences
- ✅ Laravel monté de 11 à 12 (`composer audit` vide) ; rôles de back-office (`user`/`agent`/`admin`), `app_settings` chiffrés, passerelle SMS configurable depuis `/api/v1/admin/sms-provider` — voir `docs/infrastructure/fournisseur-sms.md`

## Décisions récentes (à ne pas rediscuter)
1. Backend **Laravel 12 + MariaDB** (pas PostgreSQL ; monté de 11 à 12 le 02/08/2026 pour clore 3 avis de sécurité) — unicité via `(identifier_normalized, active_flag)`.
2. Enregistrement d'un bien : **authentification obligatoire** (OTP). Consultation : jamais.
3. Notifications de consultation : **agrégées, anonymes**, in-app + push, SMS réservé au critique.
4. Rapport détaillé : accessible **connecté OU invité identifié** (nom+email+téléphone OTP avant paiement), accès par token 30 j.
5. Anonymat **symétrique** : l'acheteur du rapport ne voit pas le déclarant, le propriétaire ne voit pas l'acheteur.
6. Verticale de lancement : **loueurs B2B** ; foncier repoussé phase 2.
7. Base de développement locale : **MariaDB Homebrew sur le port 3307**, isolée d'une éventuelle installation MySQL — voir `docs/infrastructure/dev-local-mariadb.md`. Pas de Docker.
8. **Matrice des transitions** : 3 interdictions confirmées le 02/08/2026 — `V-PRV → V-VTE`, `V-VOL → V-FDV`, et toute sortie de `V-LIT` hors arbitrage. Détail et justification dans systemPatterns.md §1.
9. **Matrice amendée le 02/08/2026** : `V-ACT → V-LIT` accepte aussi `arbitration` — un appel qui réforme un maintien doit pouvoir regeler le bien.
10. Colonnes d'horodatage métier en **DATETIME UTC** et non TIMESTAMP (`audit_log`, `asset_status_history`) : leur représentation textuelle est hachée ou rapprochée dans les exports, elle ne doit pas dépendre du fuseau de la session.

## Prochaines actions
1. ST-0404 : signaux temporels sur le verdict (ancienneté de l'enregistrement et du compte déclarant) — les données existent, l'exposition manque
3. Vérification CT-01 (< 1 s P95 en 3G) sur données volumineuses : les index sont posés, la mesure reste à faire

## Dette assumée, à reprendre
- **CAPTCHA non implémenté** : le plafond de consultation répond 429 avec `captcha_required`, mais aucun fournisseur de défi n'est branché. Sans lui, un visiteur légitime derrière une adresse partagée (cybercafé, partage de connexion mobile) reste bloqué une heure.
- **Envoi SMS synchrone** (10 s de délai d'attente) : à basculer sur la file `notifications` quand Horizon sera en place.
- **Ancrage à configurer avant toute mise en production** : sans adresse d'archivage ni disque séparé renseignés dans l'espace administrateur, le job quotidien sort en échec et la chaîne reste non opposable. Rien n'est opposable non plus avant le premier ancrage.
- **Vivacité du selfie appréciée à l'œil** : aucun fournisseur de détection de vivacité n'est retenu (ST-0103 mentionne « liveness »). La colonne `liveness_score` existe et reste nulle ; un agent juge la concordance sur l'image. Une photo de photo peut donc passer.
- **Frais de dossier de réclamation non prélevés** (2000/5000 FCFA prévus) : ils supposent EP-08. Le dépôt reste ouvert sans paiement — bloquer une victime sur un paiement indisponible fermerait son seul recours.
- **Transports FCM et SMS non branchés** (ST-1003, ST-1004, sprint 13) : la colonne `channel` dit par quel canal une notification DOIT partir, mais seules les notifications in-app sont réellement délivrées. Une alerte critique — tentative de doublon, vol — n'atteint donc pas encore un propriétaire qui n'ouvre pas l'application.
- `AuditChainTransactionConcurrencyTest` exige que les 16 processus concurrents réussissent, alors que le rejet sous contention est le comportement voulu : sensible à la charge machine, il peut clignoter en CI.

## Questions ouvertes (à trancher avec Aboubakar)
- Direction design finale (Tampon vs Feu Vert selon cible de lancement) → conditionne le design system Flutter
- Nom définitif « Preuve » : vérifier marque OAPI + domaine (preuve.ci ?)
- Tarif exact rapport détaillé (500 vs 1000 FCFA) et paliers abonnement flotte
