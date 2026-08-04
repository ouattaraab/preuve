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
- ✅ **CAPTCHA implémenté (Cloudflare Turnstile, 03/08/2026)** : le refus 429 remet la clé publique, le client résout le défi et représente un jeton, qui rouvre le passage pour un nombre BORNÉ de consultations (`preuve.captcha.grant_lookups`, 10 par défaut). Turnstile plutôt que reCAPTCHA parce qu'il ne dépose pas de cookie publicitaire — faire passer par un régisseur les gens qui consultent sans compte contredirait la minimisation imposée partout ailleurs. L'adresse du visiteur n'est pas transmise à Cloudflare (`remoteip` facultatif, délibérément omis). Échoue fermé. Clés réglables depuis l'espace administrateur. **Reste** : renseigner les clés du compte Cloudflare en production, et écrire le widget côté client.
- ✅ **Acheminement des notifications en file depuis le 03/08/2026** (débloqué par le rétablissement du cron). Voir l'entrée détaillée plus bas.
- ✅ **Ancrage configuré et opposable depuis le 03/08/2026** : canal courriel vers une adresse d'archivage externe à la plateforme. Premier ancrage publié (tête #2), confronté avec succès. La sonde rend `audit_chain_opposable: true`. **Reste** : le canal « stockage séparé » n'apporte rien tant que le seul disque disponible est celui de la plateforme — il demande R2. Et l'ancrage quotidien ne se rejouera pas tant que le cron ne s'exécute pas (voir ci-dessous).
- **Vivacité du selfie appréciée à l'œil** : aucun fournisseur de détection de vivacité n'est retenu (ST-0103 mentionne « liveness »). La colonne `liveness_score` existe et reste nulle ; un agent juge la concordance sur l'image. Une photo de photo peut donc passer.
- ✅ **CT-01 mesuré sur l'hébergement cible, à volume, le 03/08/2026** : **2 ms au 95e centile** avec **179 996 biens et 300 300 consultations journalisées**, sur le mutualisé. 10 ms au 99e. Le seuil promis est de 1 000 ms — la marge est de deux ordres de grandeur. Mesure faite dans une base dédiée (`u726808002_drill`) : le registre en service n'a pas reçu une ligne. **Bout en bout, après désactivation du CDN Hostinger le 03/08/2026** : 536 ms au premier octet en médiane, **647 ms au 95e centile** — CT-01 tenu. Le CDN ajoutait 5 secondes et échouait par intermittence (2,55 s mesurés depuis Abidjan avant bascule, pour 2 ms de traitement) ; il est désactivé, `preuve.click` résout directement sur l'origine. **À revérifier après toute intervention d'Hostinger sur le domaine.** Reste à confirmer depuis Abidjan. La mesure de bout en bout depuis le poste de développement est inexploitable — le CDN Hostinger y route vers un edge de Bangkok (650 ms de RTT TCP) pour un serveur situé en France. À faire depuis la Côte d'Ivoire : `curl -s -o /dev/null -w '%{time_starttransfer}\n' https://preuve.click/api/v1/lookup/XXX`.
- **Restauration éprouvée sur poste de développement seulement** (02/08/2026, `preuve:restore-drill`) : la procédure et l'outil sont validés — y compris par un test négatif, une sauvegarde amputée de ses déclencheurs que seul le test d'écriture a détectée. Reste à mener sur l'hébergement cible, à volume réel. La sauvegarde du bucket est désormais automatisée (`preuve:backup-documents`, quotidienne, plus un contrôle d'intégrité hebdomadaire qui relit tout — l'incrémental ne peut pas voir une pièce substituée après coup). Le remontage est outillé (`preuve:restore-documents`, n'écrase jamais, vérifie avant d'écrire) et le cycle complet sauvegarde → perte totale → remontage → intégrité a été éprouvé le 02/08/2026 sur poste de développement. Le sinistre partiel est outillé (`preuve:reconcile-documents`, regarde dans les deux sens, ne supprime jamais) et éprouvé le 02/08/2026 sur les trois divergences simultanées. Planifiée le 1er du mois, rapport expédié au destinataire d'exploitation (`ops.report_recipient`, réglable depuis l'espace administrateur) et déposé dans `storage/logs/reconciliation-documents.log` — **journal à inclure dans la rotation**. **Reste à éprouver sur MinIO et à volume réel.**
- **Extraction Mindee des cartes grises non éprouvée sur documents réels** (ST-0202) : le produit visé est configurable depuis l'espace administrateur, mais aucun endpoint Mindee ne cible spécifiquement la carte grise ivoirienne. Le repli sur le balayage du texte, filtré par le chiffre de contrôle du VIN, est ce qui fait tenir la fonction — à mesurer sur de vrais documents avant d'annoncer un taux.
- **File d'envoi côté client à écrire** (ST-0206) : le serveur fournit l'identité stable et la position exacte ; la file locale, l'envoi en arrière-plan et le réessai restent à implémenter dans l'application Flutter.
- ✅ **Import de flotte en file au-delà de 200 lignes** (03/08/2026, débloqué par le rétablissement du cron). En deçà, il reste synchrone — rendre un identifiant de suivi pour douze véhicules serait une régression d'usage déguisée en progrès. Au-delà, il est **découpé en tranches de 50** : un unique travail de mille lignes tiendrait le travailleur et disputerait le verrou d'audit pendant plusieurs minutes, rejetant les actions de tous les autres utilisateurs. Entre deux tranches, le verrou est rendu. Réponse **202** avec un identifiant de suivi (`GET /fleet/{company}/imports/{id}`), rattaché à la société : un identifiant d'import n'ouvre pas l'inventaire d'un tiers. Idempotent par construction — une tranche rejouée recompte ses lignes en doublons, pas en erreurs. Plafond absolu à 10 000 lignes ; au-delà, c'est une reprise de données. Le fichier est effacé du disque de travail dès l'import terminé : il porte l'inventaire complet d'un parc.
- ✅ **Quota d'enregistrement BLOQUANT depuis le 03/08/2026** (décision produit d'Aboubakar) : au-delà des 3 places gratuites et des places payées, `POST /assets` rend **402** avec l'état du quota et le prix de la place suivante. Contrôlé dans `AssetRegistrationService`, donc pour tout chemin d'enregistrement présent et à venir. Les flottes en sont exclues — elles relèvent de leur abonnement et de la suspension douce. **Conséquence à surveiller** : on ne déclare pas volé un bien qu'on n'a pas enregistré ; un particulier au-delà de son quota devra donc payer avant de pouvoir signaler un vol, à un moment où il est déjà victime. Si cela se constate en usage, l'exception à envisager est d'autoriser l'enregistrement lorsqu'une déclaration de vol suit immédiatement.
- **Décompte mensuel ≠ facture fiscale** : le document produit porte le calcul mais pas les mentions exigibles en Côte d'Ivoire (régime, numéro de contribuable, TVA) — à faire valider par un comptable avant émission.
- ✅ **Frais de dossier BLOQUANTS au DÉPÔT, montant réglable en exploitation** (décision produit d'Aboubakar, 03/08/2026, après réexamen). Ouvrir un dossier et y verser des pièces restent libres ; c'est `submit()` qui exige le règlement, parce que c'est là que le bien est gelé et le détenteur prévenu — le moment où la réclamation commence à coûter à quelqu'un d'autre. Refus en **402** portant le montant. Remboursé si la réclamation aboutit.
  - **La soupape** : `PUT /api/v1/admin/claim-fee` accepte **0**, qui lève entièrement le blocage. C'est ce qui empêche le filtre anti-nuisance de devenir un filtre anti-pauvres — la victime d'un enregistrement frauduleux est souvent celle qui a le moins. Chaque changement est journalisé avec l'ancien ET le nouveau montant : savoir qu'un changement a eu lieu ne suffit pas à juger s'il a restreint ou ouvert l'accès au recours.
  - Zéro est traité comme une valeur légitime et non comme un réglage absent — un `?:` naïf rétablirait des frais que l'administrateur vient de lever. Un test le verrouille.
  - Les tests d'arbitrage tournent en mode gratuit : ils portent sur l'arbitrage, pas sur les frais.
- ✅ **Acheminement des notifications en file `notifications`** (03/08/2026). La ligne du centre in-app reste écrite dans la requête — c'est la trace durable — et seule la sortie hors application part en file. Ce que la file apporte et qui était impossible avant : **le réessai**. En synchrone, un échec de passerelle ne laissait que deux choix, refuser l'action métier ou perdre l'alerte ; aucun n'est acceptable pour une déclaration de vol. Trois tentatives espacées (60 s, 300 s) ; un doublon vaut mieux qu'une perte. Le push, non critique, n'est pas rejoué. **L'action métier ne peut jamais échouer à cause d'un acheminement, quel que soit le pilote de file** — y compris `sync` et file en panne : un test le verrouille. Travailleur relancé chaque minute par le planificateur (`--stop-when-empty --max-time=50`), faute de superviseur sur mutualisé : une alerte peut donc attendre jusqu'à une minute avant de quitter la plateforme.
- **Push et SMS à configurer avant lancement** : sans clé FCM ni passerelle SMS renseignées, seules les notifications in-app sont délivrées.
- `AuditChainTransactionConcurrencyTest` exige que les 16 processus concurrents réussissent, alors que le rejet sous contention est le comportement voulu : sensible à la charge machine, il peut clignoter en CI.

- ✅ **Cron rétabli le 03/08/2026** (commande corrigée dans hPanel : chemin absolu vers `artisan`, sans `cd`). Le témoin de passage confirme un battement toutes les cinq minutes, la sonde rend `scheduler: ok`. Débloque la bascule des notifications en file et l'import de flotte au-delà de 200 lignes. Historique du blocage, conservé pour mémoire :
- 🔵 ~~BLOQUANT — les tâches cron ne s'exécutent pas sur l'hébergement~~ (constaté le 03/08/2026). Deux tâches indépendantes, dont un simple `/usr/bin/date`, créent leur fichier de sortie à `HH:MM:02` — signature d'un déclenchement — puis n'écrivent **jamais** un octet, sur des observations de 4 à 18 minutes. Ce n'est donc pas la commande : `date` ne peut pas échouer. Écrire directement dans `/var/spool/cron/` (qui appartient pourtant à l'utilisateur) n'est pas lu non plus, et aucun binaire `crontab` ni outil hPanel n'existe en ligne de commande. **Ticket support Hostinger à ouvrir.** Conséquence : aucune des 13 tâches planifiées ne tourne — promotion des biens provisoires, agrégation des consultations, pics, expiration des transferts, **ancrage quotidien**, purges de rétention. Impact immédiat nul (registre vide), inacceptable dès qu'il portera des biens. Repli possible : déclenchement HTTP externe par un service tiers, au prix d'un endpoint protégé par secret — à arbitrer.

## Confidentialité publiée (04/08/2026)

`/confidentialite`, indexable, sans session ni cookie comme le reste du front —
la lire ne doit rien coûter en traces à qui s'inquiète justement de ce qu'on
garde de lui. Lien en pied de page.

**ELLE DÉCRIT CE QUE LE CODE FAIT**, pas ce qu'il serait souhaitable qu'il
fasse : hachage salé quotidien de l'adresse, effacement des consultations à
douze mois, numéro de pièce conservé en empreinte et **non restituable y compris
sur réquisition**, anonymat symétrique sans exception interne, registre
inaltérable des levées. Elle dit aussi une limite franchement : la chaîne d'audit
survivrait à un droit à l'effacement — elle ne contient ni nom, ni numéro, ni
adresse, et c'est précisément pour cela qu'elle peut être inaltérable.

**L'adresse de contact est un RÉGLAGE** (`legal.contact_email`), pas une
constante : une adresse change, et la loi impose de l'afficher — pas de livrer
une version du serveur pour la corriger. Tant qu'aucune n'est réglée, la page
annonce un guichet **en cours d'ouverture**. En afficher une inventée serait
pire : la personne croirait avoir saisi le responsable, et le silence passerait
pour un refus.

**⬜ À FAIRE PAR ABOUBAKAR** : régler cette adresse depuis Supervision. L'écran y
reçoit désormais les DEUX adresses d'exploitation — celle des rapports, qui
n'avait aucune interface, et celle des droits — avec leur état en clair.

**À FAIRE RELIRE PAR UN JURISTE avant lancement.** Le texte est factuel et vérifié
ligne à ligne contre le code, mais la conformité formelle à la Loi 2013-450
(déclaration ARTCI, mentions exigibles) n'a pas été appréciée.

**Exercice de restauration : toujours bloqué.** Le compte MariaDB
`u726808002_napster010826` n'a pas le droit `CREATE DATABASE` (vérifié le
04/08/2026 : « Access denied … 1044 »), et `u726808002_drill` n'existe plus. Il
faut créer une seconde base depuis hPanel pour que
`preuve:restore-drill --database=…` puisse tourner sans toucher au registre.

## Le planificateur s'exécutait DEUX FOIS (04/08/2026)

**Constaté en production, pas supposé** : deux sauvegardes à 01:30:07 et
01:30:10, deux ancrages à 02:40:06 et 02:40:09 — trois secondes d'écart. Le
registre des ancrages porte trois lignes pour la même tête #2, dont deux du même
matin.

`withoutOverlapping()` n'y pouvait rien : il empêche un CHEVAUCHEMENT, or la
première exécution était terminée avant que la seconde ne commence.
**`onOneServer()` est la seule primitive adaptée** — verrou porté par la tâche ET
la minute, gardé jusqu'à la fin de celle-ci. Posé sur les seize tâches ; un test
refuse qu'une nouvelle en soit dépourvue, et un second vérifie que `cache_locks`
existe (sans magasin capable de verrouiller, `onOneServer()` ferait tomber le
planificateur entier — la protection casserait ce qu'elle protège).

Vérifié en production : deux `schedule:run` consécutifs, le second annonce
« Skipping … because the command already ran on another server ».

**LA CAUSE EST EN AMONT ET APPARTIENT À ABOUBAKAR** : une tâche cron
vraisemblablement déclarée deux fois dans hPanel. À vérifier et à dédoublonner.
La protection applicative rend la double invocation inoffensive, mais elle ne la
supprime pas — et une protection qui dépendrait d'une console tierce n'en serait
pas une.

## Journal d'exploitation réellement écrit (04/08/2026)

Le rapport tronque au-delà de quarante lignes et renvoie au « détail complet dans
le journal sur le serveur ». Ce fichier n'était écrit que par `appendOutputTo()`
sur la tâche de réconciliation — donc **uniquement sous le planificateur**, et
**sans aucune rotation**. Un passage manuel laissait le courriel pointer vers un
fichier inexistant.

C'est désormais `OpsReporter` — celui qui ANNONCE le chemin — qui l'écrit, via
`OpsJournal` : entrée datée, écriture AVANT l'envoi et quel que soit son sort
(un destinataire non réglé ou une passerelle en panne sont précisément les
moments où la trace locale est la seule qui reste), rotation à 2 Mio sur une
seule génération. Ni `logrotate` ni cron système sur un mutualisé : la rotation
doit être faite par l'application.

Même raison pour `LOG_STACK`, passé de `single` à **`daily`** dans le défaut du
code, `.env.example` et la production : un journal unique grossit sans fin
jusqu'à remplir le quota du compte, et **un quota atteint arrête TOUTE écriture,
y compris celle du registre**. L'ancien `laravel.log` (23 Ko) subsiste, inerte.

Les sauvegardes de base sont, elles, déjà bornées (`preuve:backup --keep=30`).

## En-têtes de sécurité et incident du 403 (04/08/2026)

**INCIDENT — `https://preuve.click/admin/` rendait un 403 du serveur.** Les
polices et le script de la console vivaient dans `public/admin/`. LiteSpeed y
résolvait `/admin/` — dossier réel, sans index et sans listage autorisé — et
répondait AVANT que Laravel ne voie la requête. `/admin/connexion` et les écrans
nommés fonctionnaient : seule la racine était murée, ce qui ne se voyait que pour
qui tapait l'adresse à la main. **Règle : un préfixe de route et un dossier de la
racine servie ne doivent jamais porter le même nom.** Les ressources vivent
désormais sous `/console/`. Un test regarde le disque — aucun test d'intégration
ne pouvait l'attraper, le serveur de test ne servant pas de fichiers statiques.

**En-têtes de sécurité** posés par le middleware global `SecurityHeaders`, donc
par l'application et non par le serveur : sur un mutualisé la configuration ne
nous appartient pas, et ce qui est posé à la main disparaît au prochain
redéploiement.

- `frame-ancestors 'none'` + `X-Frame-Options: DENY` — le clique-détournement vise
  la console : un cadre invisible superposé à l'écran des comptes ferait cliquer
  un agent sur « Suspendre » en lui faisant croire qu'il ferme une bannière.
- `script-src 'self'` **sans** `'unsafe-inline'`. C'est ce qui a justifié de
  retirer les attributs `onsubmit` des gabarits d'administration : une console qui
  affiche des pièces d'identité n'a pas les moyens d'autoriser ce qu'une faille
  XSS injecte. `style-src` garde `'unsafe-inline'` — compromis assumé, la maquette
  est écrite en styles en ligne, et un style ne s'exécute pas.
- L'origine du défi anti-automate n'est ouverte **que sur la page qui l'affiche**
  (attribut de requête posé par le contrôleur public). L'autoriser en permanence
  rendrait invérifiable la promesse « aucune ressource tierce sur le chemin
  nominal ».
- HSTS un an, **sans `includeSubDomains`** : la directive engagerait des
  sous-domaines que nous ne servons pas.

**PIÈGE DE L'HÉBERGEUR, à ne pas réintroduire :** LiteSpeed **remplace**
`Content-Security-Policy` par la sienne et **réinjecte** `X-Powered-By` après le
passage de PHP — tous les autres en-têtes de l'application arrivaient intacts.
`mod_headers` s'exécute après et a le dernier mot : `public/.htaccess` recopie la
politique depuis `X-Preuve-CSP`, posé par le middleware, plutôt que de l'écrire en
dur — elle varie d'une réponse à l'autre. Un test verrouille l'égalité des deux
en-têtes, sans quoi la politique appliquée en production cesserait d'être celle
que les tests vérifient.

## Front public de consultation (ST-0306, 04/08/2026)

`preuve.click/` servait encore le gabarit par défaut de Laravel : une plateforme
dont la promesse est « vérifier avant d'acheter, sans compte » n'avait aucune page
pour le faire. Trois routes, sans session ni JavaScript.

- `/` — un champ, un bouton (CT-01, deux interactions). Aucun choix de type de bien
  à faire d'abord : la normalisation reconnaît seule un châssis, une plaque ou un
  IMEI, et faire trancher l'acheteur lui ferait porter une erreur qui n'est pas la
  sienne.
- `/verifier?q=…` — résultat d'une saisie libre. **`noindex` sans exception**, en
  balise ET en en-tête : l'URL porte l'identifiant réel, et l'indexer publierait,
  moteur après moteur, l'annuaire des numéros de châssis enregistrés.
- `/b/{PRV-XXXXXXXX}` — page de statut **indexable**, adressée par la référence
  opaque. La route n'accepte que cette forme.

**Partis pris à ne pas rediscuter :**
1. **Même service que l'API** (`LookupService`) : même empreinte d'adresse, même
   quota horaire, même échappatoire par défi. Un second chemin plus permissif
   ferait de ces pages l'outil de balayage que le plafond existe pour empêcher.
2. **Pas de plan de site.** Un sitemap énumérant les références publierait le
   registre sous forme de liste : chaque page prise isolément est anodine, leur
   collection ne l'est pas. Les pages se découvrent par le lien qu'un vendeur
   partage.
3. **Aucune session, donc aucun cookie** (routes hors `StartSession`) : un
   identifiant de session permettrait de recoudre les consultations successives
   d'un visiteur, ce que le hachage quotidien de l'adresse existe pour empêcher —
   et un `Set-Cookie` interdirait la mise en cache partagée de la page la plus
   consultée du site. `Referrer-Policy: no-referrer` en complément.
4. **Aucune ressource tierce sur le chemin nominal.** Le script Turnstile n'est
   chargé qu'APRÈS un refus pour plafond atteint. Accueil servi en 4,7 Ko.
5. Le code HTTP suit le verdict (200 / 404 / 422 / 429), y compris en HTML : un
   moteur qui verrait un 200 sur une page de refus l'indexerait à la place du
   verdict.

Titre et description sont composés dans le contrôleur, pas en sections Blade —
une section multiligne emporte ses retours à la ligne dans la balise `<title>`,
ce qui ne se voit qu'en lisant le HTML rendu, puis dans les résultats de
recherche. Un test le verrouille.

**Reste** : le widget Turnstile est en place côté serveur et côté page, mais
aucune clé Cloudflare n'est renseignée en production — le refus tient, sans
échappatoire, tant que les clés manquent.

## Espace administrateur (construction par lots, en production)

Console servie par Laravel, sans étape de construction — l'hébergement cible est
un mutualisé sans Node, et la maquette (`docs/Preuve - Admin.html`) n'utilisait
aucun framework. Palette et polices reprises telles quelles.

**Authentification par cookie de session `httpOnly`, jamais par jeton en
`localStorage`** : cette console affiche des cartes grises et des pièces
d'identité ; un jeton y serait lisible par la première faille XSS.

Les gabarits ne rendent AUCUNE donnée côté serveur : ils sont peuplés par le
navigateur, qui interroge les mêmes `/api/v1/admin/*` que n'importe quel client.
Deux chemins de lecture finiraient par diverger, et l'écart ne se verrait qu'au
moment d'une décision d'agent.

- ✅ **Lot 1 (03/08/2026)** : connexion OTP à deux temps, coquille, navigation, Modération, Supervision.
- ✅ **Lot 2 (03/08/2026)** : Registre des biens (aucune colonne « détenteur »), Annuaire des comptes (coordonnées masquées, suspension motivée qui révoque les jetons sans jamais suspendre la protection des biens).
- ✅ **Lot 3 (03/08/2026)** : Piste d'audit, Catégories & champs, Équipe & rôles — **fermés aux agents** : ils ne servent pas à instruire des dossiers mais à configurer la plateforme.
- ✅ **Lot 4 (04/08/2026)** : Vue d'ensemble (ouverte aux agents) et Statistiques app (administrateurs seuls). **Les neuf écrans de la maquette sont servis** ; l'affordance « à venir » a été retirée avec ce qu'elle annonçait, et la console ouvre désormais sur la vue d'ensemble.

**Deux partis pris du lot 4, à ne pas rediscuter :**
1. **La vue d'ensemble ouvre sur ce qui attend une décision humaine**, pas sur des volumes. Chaque file porte son ancienneté : trois dossiers déposés ce matin et trois oubliés depuis douze jours donnent le même compteur et n'appellent pas la même journée. Aucun chiffre ne désigne quelqu'un.
2. **Les « téléchargements » de la maquette ne sont pas mesurables** : ce chiffre appartient aux magasins d'applications. Le reconstituer à partir des comptes créés donnerait un nombre plausible et faux — on déciderait dessus. L'écran montre le **parc d'appareils** (`device_tokens`, total et vus sous 30 jours) et le dit explicitement dans son `notice`. Brancher les API des magasins reste possible plus tard ; inventer le chiffre, non.

**Trois partis pris du lot 3, à ne pas rediscuter :**
1. **La piste d'audit n'offre aucune route d'écriture** — pas seulement parce que les déclencheurs l'interdisent, mais parce qu'un bouton qui échouerait toujours enseignerait qu'une modification est concevable. L'export est diffusé en flux par lots de 500 : un journal d'exploitation atteint vite le million de lignes, et le charger en mémoire ferait échouer l'export précisément le jour où il compte. Chaque ligne porte son `chain_hash`, seul moyen de la rapprocher d'un ancrage externe.
2. **Une catégorie se désactive, jamais ne se supprime**, et l'identifiant canonique ne se déplace pas : il porte l'unicité de l'enregistrement actif, et le déplacer sur une catégorie peuplée ferait apparaître des doublons rétroactivement.
3. **Les pages visibles sont DÉDUITES du rôle et non stockées.** La maquette prévoyait quatre profils avec des pages cochables ; la plateforme en a trois (`user`, `agent`, `admin`) et tient ses accès par des middlewares. Une table d'habilitations serait une seconde source de vérité : le jour où elles divergeraient, l'écran afficherait un droit que le code refuse — ou l'inverse. La navigation cache aux agents ce qu'ils ne peuvent pas atteindre : un lien menant à un 403 n'est pas de la transparence.

## Forçage de mise à jour des applications (04/08/2026)

Version minimale exigée, réglable depuis Statistiques app (`app.minimum_version`),
annoncée publiquement par `GET /api/v1/config/app` **sans authentification** —
l'application doit pouvoir afficher son écran de mise à jour au démarrage, plutôt
que de laisser l'utilisateur saisir un bien pendant quatre-vingt-dix secondes pour
se heurter au refus à l'envoi.

**IL NE TOUCHE JAMAIS À LA CONSULTATION.** La règle métier absolue n° 1 dit qu'un
verdict est gratuit, anonyme et sans compte ; elle ne dit pas « sauf si votre
téléphone est vieux ». `EnsureAppIsSupported` est posé sur le seul groupe
d'écritures authentifiées, à côté de `EnsurePlatformIsWritable`, et laisse en
outre passer les **lectures** (`isMethodSafe`) : l'application doit pouvoir
expliquer POURQUOI elle ne peut plus écrire, une coquille vide ressemblerait à
une panne. Refus en **426** portant la version exigée, pas en 403 — on demande une
action réalisable, on ne refuse pas un droit.

Trois garde-fous : une version **illisible ou absente passe** (refuser sur un
en-tête qu'on n'a pas su lire punirait l'utilisateur pour un défaut de la
plateforme) ; le défaut est **« aucune exigence »** (une version mal saisie
mettrait sinon tout le parc hors service d'un seul réglage) ; **vider le champ
lève l'exigence**, manœuvre de repli sans livraison serveur. Chaque changement est
journalisé avec l'ancienne ET la nouvelle exigence. **Rien n'est exigé en
production au 04/08/2026.**

## Levée d'anonymat sur réquisition (03/08/2026)

Elle existe **parce que l'alternative est pire** : une réquisition judiciaire
arrivera, et sans chemin prévu l'exploitant y répondra par une requête SQL
directe — sans fondement consigné, sans trace, sans registre.

Délibérément plus difficile que tout le reste : réservée aux administrateurs
(contrôle refait dans le contrôleur, pas seulement au routage) ; fondement
**structuré et obligatoire** (autorité, référence, date, objet) parce qu'un champ
libre unique se remplirait de « enquête » ; **une personne à la fois**, aucun
listage ni export ; **aucun état « déverrouillé »** — la réponse est rendue une
fois, et la seconde d'après l'identité est de nouveau inaccessible ; **double
trace ineffaçable**, chaîne d'audit et table `identity_disclosures`, toutes deux
append-only par déclencheurs (vérifiés en production le 03/08/2026 sur une ligne
posée puis annulée en transaction).

Le numéro de pièce n'est **pas restituable**, y compris sur réquisition : il
n'est conservé qu'en SHA-256. La réponse le dit explicitement plutôt que de
laisser chercher.

Le sujet n'est pas prévenu, et ce silence est une décision : une réquisition
s'accompagne le plus souvent d'une obligation de confidentialité. La contrepartie
est le registre, lisible par les administrateurs sans procédure — un document de
reddition de comptes qui exigerait une procédure pour être consulté ne servirait
à rien.

## Questions ouvertes (à trancher avec Aboubakar)
- Direction design finale (Tampon vs Feu Vert selon cible de lancement) → conditionne le design system Flutter
- Nom définitif « Preuve » : vérifier marque OAPI + domaine (preuve.ci ?)
- Tarif exact rapport détaillé (500 vs 1000 FCFA) et paliers abonnement flotte
