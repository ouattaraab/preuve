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
- ✅ **ST-0202 / ST-0206** : scan de carte grise pour pré-remplir l'identifiant (le scan propose, il n'enregistre jamais) et envois différés avec reprise
- ✅ **ST-0904** : mode lecture seule (consultation TOUJOURS ouverte), sauvegarde chiffrée quotidienne hors machine, sonde de santé
- ✅ **EP-10 complet** : transports push FCM et SMS critique branchés, jetons d'appareil, coût SMS tracé par type — configuration depuis `/api/v1/admin/push-provider`
- ✅ **EP-07 complet** : ST-0705 délégation aux collaborateurs (rôles admin/opérateur, invitation par numéro, actions tracées par acteur, actes de propriété jamais délégués)
- ✅ **EP-08 complet** : abonnements de flotte (relances échelonnées, suspension DOUCE en lecture seule), frais de dossier de réclamation annoncés et remboursables, décompte mensuel
- ✅ **CT-01 mesuré sur volume** : 1 ms au 95e centile sur 200 000 biens et 1 M de consultations journalisées — `preuve:benchmark-lookup`, voir `docs/infrastructure/mesure-ct01.md`
- ✅ ST-0404 : signaux temporels sur le verdict (ancienneté du bien et du compte, en TRANCHES, non antidatables, formulation factuelle)
- ✅ **EP-09 (cœur)** : télémétrie CT-01/CT-02 (centiles, `lookups.duration_ms`), tableau anti-fraude, validation des comptes entreprise, sonde de santé publique — voir `docs/infrastructure/exploitation.md`
- ✅ **EP-07 (cœur)** : import CSV borné et partiellement abouti, marquage « En location » en masse, tableau de bord flotte (alertes en tête, consultations agrégées)
- ✅ **EP-08 (cœur)** : `payments`/`report_purchases`, rapport détaillé anonymisé (nombre de détenteurs et dates, jamais les identités), guest checkout OTP avant paiement, webhooks signés et idempotents, quotas non bloquants et paliers de flotte
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
Le backlog v1.1 est couvert. Ce qui reste à faire tient dans la « dette assumée »
ci-dessous : ce sont des décisions produit ou des branchements d'exploitation,
plus des stories.

## Dette assumée, à reprendre
- **CAPTCHA non implémenté** : le plafond de consultation répond 429 avec `captcha_required`, mais aucun fournisseur de défi n'est branché. Sans lui, un visiteur légitime derrière une adresse partagée (cybercafé, partage de connexion mobile) reste bloqué une heure.
- **Envoi SMS synchrone** (10 s de délai d'attente) : à basculer sur la file `notifications` quand Horizon sera en place.
- **Ancrage à configurer avant toute mise en production** : sans adresse d'archivage ni disque séparé renseignés dans l'espace administrateur, le job quotidien sort en échec et la chaîne reste non opposable. Rien n'est opposable non plus avant le premier ancrage.
- **Vivacité du selfie appréciée à l'œil** : aucun fournisseur de détection de vivacité n'est retenu (ST-0103 mentionne « liveness »). La colonne `liveness_score` existe et reste nulle ; un agent juge la concordance sur l'image. Une photo de photo peut donc passer.
- **CT-01 non remesuré sur l'hébergement cible** : la mesure a été faite sur poste de développement, sans charge concurrente et sans latence 3G. À reprendre sur l'environnement réel avant lancement.
- **Restauration éprouvée sur poste de développement seulement** (02/08/2026, `preuve:restore-drill`) : la procédure et l'outil sont validés — y compris par un test négatif, une sauvegarde amputée de ses déclencheurs que seul le test d'écriture a détectée. Reste à mener sur l'hébergement cible, à volume réel. La sauvegarde du bucket est désormais automatisée (`preuve:backup-documents`, quotidienne, plus un contrôle d'intégrité hebdomadaire qui relit tout — l'incrémental ne peut pas voir une pièce substituée après coup). Le remontage est outillé (`preuve:restore-documents`, n'écrase jamais, vérifie avant d'écrire) et le cycle complet sauvegarde → perte totale → remontage → intégrité a été éprouvé le 02/08/2026 sur poste de développement. Le sinistre partiel est outillé (`preuve:reconcile-documents`, regarde dans les deux sens, ne supprime jamais) et éprouvé le 02/08/2026 sur les trois divergences simultanées. Planifiée le 1er du mois, rapport expédié au destinataire d'exploitation (`ops.report_recipient`, réglable depuis l'espace administrateur) et déposé dans `storage/logs/reconciliation-documents.log` — **journal à inclure dans la rotation**. **Reste à éprouver sur MinIO et à volume réel.**
- **Extraction Mindee des cartes grises non éprouvée sur documents réels** (ST-0202) : le produit visé est configurable depuis l'espace administrateur, mais aucun endpoint Mindee ne cible spécifiquement la carte grise ivoirienne. Le repli sur le balayage du texte, filtré par le chiffre de contrôle du VIN, est ce qui fait tenir la fonction — à mesurer sur de vrais documents avant d'annoncer un taux.
- **File d'envoi côté client à écrire** (ST-0206) : le serveur fournit l'identité stable et la position exacte ; la file locale, l'envoi en arrière-plan et le réessai restent à implémenter dans l'application Flutter.
- **Import de flotte borné à 200 lignes par appel** : au-delà, le loueur découpe son fichier. Chaque enregistrement prend le verrou de la chaîne d'audit ; l'import devra passer en file quand Horizon sera en place.
- **Quota d'enregistrement volontairement non bloquant** (ST-0804) : au-delà des 3 biens gratuits, l'enregistrement aboutit quand même et l'upsell est seulement proposé. Le revenu de ce poste repose donc sur la bonne volonté — le rendre bloquant est une décision produit, pas une correction technique.
- **Décompte mensuel ≠ facture fiscale** : le document produit porte le calcul mais pas les mentions exigibles en Côte d'Ivoire (régime, numéro de contribuable, TVA) — à faire valider par un comptable avant émission.
- **Frais de dossier volontairement non bloquants** : le montant est annoncé et remboursable si la réclamation aboutit, mais son non-règlement n'empêche jamais le dépôt ni l'instruction. Rendre le paiement obligatoire est une décision produit.
- **Acheminement des notifications synchrone** : push et SMS partent dans la requête qui les déclenche, ajoutant quelques centaines de millisecondes aux actions critiques. À basculer sur la file `notifications` avec Horizon. Les échecs sont absorbés — jamais propagés à l'action métier.
- **Push et SMS à configurer avant lancement** : sans clé FCM ni passerelle SMS renseignées, seules les notifications in-app sont délivrées.
- `AuditChainTransactionConcurrencyTest` exige que les 16 processus concurrents réussissent, alors que le rejet sous contention est le comportement voulu : sensible à la charge machine, il peut clignoter en CI.

## Questions ouvertes (à trancher avec Aboubakar)
- Direction design finale (Tampon vs Feu Vert selon cible de lancement) → conditionne le design system Flutter
- Nom définitif « Preuve » : vérifier marque OAPI + domaine (preuve.ci ?)
- Tarif exact rapport détaillé (500 vs 1000 FCFA) et paliers abonnement flotte
