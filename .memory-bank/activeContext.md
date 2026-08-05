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
- ✅ **CAPTCHA implémenté (Cloudflare Turnstile, 03/08/2026)** : le refus 429 remet la clé publique, le client résout le défi et représente un jeton, qui rouvre le passage pour un nombre BORNÉ de consultations (`preuve.captcha.grant_lookups`, 10 par défaut). Turnstile plutôt que reCAPTCHA parce qu'il ne dépose pas de cookie publicitaire — faire passer par un régisseur les gens qui consultent sans compte contredirait la minimisation imposée partout ailleurs. L'adresse du visiteur n'est pas transmise à Cloudflare (`remoteip` facultatif, délibérément omis). Échoue fermé. Clés réglables depuis l'espace administrateur. **Widget écrit côté web (04/08) et côté mobile (05/08).** Le mobile ne l'embarque pas : il **renvoie le défi au navigateur du téléphone**, sur `/verifier?q=…` où il tourne déjà — une WebView aurait coûté une quatrième dépendance native et plusieurs mégaoctets à tout le monde pour un écran que presque personne ne verra. **Défaut trouvé au passage** : `LookupService.check` **jetait la clé du défi** en convertissant le 429 en verdict ; l'écran affichait « PATIENTE » sans issue alors que le serveur venait d'indiquer par où passer. **Reste** : renseigner les clés du compte Cloudflare en production.
- ✅ **Acheminement des notifications en file depuis le 03/08/2026** (débloqué par le rétablissement du cron). Voir l'entrée détaillée plus bas.
- ✅ **Ancrage configuré et opposable depuis le 03/08/2026** : canal courriel vers une adresse d'archivage externe à la plateforme. Premier ancrage publié (tête #2), confronté avec succès. La sonde rend `audit_chain_opposable: true`. **Reste** : le canal « stockage séparé » n'apporte rien tant que le seul disque disponible est celui de la plateforme — il demande R2. Et l'ancrage quotidien ne se rejouera pas tant que le cron ne s'exécute pas (voir ci-dessous).
- **Vivacité du selfie appréciée à l'œil** : aucun fournisseur de détection de vivacité n'est retenu (ST-0103 mentionne « liveness »). La colonne `liveness_score` existe et reste nulle ; un agent juge la concordance sur l'image. Une photo de photo peut donc passer.
- ✅ **CT-01 mesuré sur l'hébergement cible, à volume, le 03/08/2026** : **2 ms au 95e centile** avec **179 996 biens et 300 300 consultations journalisées**, sur le mutualisé. 10 ms au 99e. Le seuil promis est de 1 000 ms — la marge est de deux ordres de grandeur. Mesure faite dans une base dédiée (`u726808002_drill`) : le registre en service n'a pas reçu une ligne. **Bout en bout, après désactivation du CDN Hostinger le 03/08/2026** : 536 ms au premier octet en médiane, **647 ms au 95e centile** — CT-01 tenu. Le CDN ajoutait 5 secondes et échouait par intermittence (2,55 s mesurés depuis Abidjan avant bascule, pour 2 ms de traitement) ; il est désactivé, `preuve.click` résout directement sur l'origine. **À revérifier après toute intervention d'Hostinger sur le domaine.** Reste à confirmer depuis Abidjan. La mesure de bout en bout depuis le poste de développement est inexploitable — le CDN Hostinger y route vers un edge de Bangkok (650 ms de RTT TCP) pour un serveur situé en France. À faire depuis la Côte d'Ivoire : `curl -s -o /dev/null -w '%{time_starttransfer}\n' https://preuve.click/api/v1/lookup/XXX`.
- **Restauration éprouvée sur poste de développement seulement** (02/08/2026, `preuve:restore-drill`) : la procédure et l'outil sont validés — y compris par un test négatif, une sauvegarde amputée de ses déclencheurs que seul le test d'écriture a détectée. Reste à mener sur l'hébergement cible, à volume réel. La sauvegarde du bucket est désormais automatisée (`preuve:backup-documents`, quotidienne, plus un contrôle d'intégrité hebdomadaire qui relit tout — l'incrémental ne peut pas voir une pièce substituée après coup). Le remontage est outillé (`preuve:restore-documents`, n'écrase jamais, vérifie avant d'écrire) et le cycle complet sauvegarde → perte totale → remontage → intégrité a été éprouvé le 02/08/2026 sur poste de développement. Le sinistre partiel est outillé (`preuve:reconcile-documents`, regarde dans les deux sens, ne supprime jamais) et éprouvé le 02/08/2026 sur les trois divergences simultanées. Planifiée le 1er du mois, rapport expédié au destinataire d'exploitation (`ops.report_recipient`, réglable depuis l'espace administrateur) et déposé dans `storage/logs/reconciliation-documents.log` — **journal à inclure dans la rotation**. **Reste à éprouver sur MinIO et à volume réel.**
- **Extraction Mindee des cartes grises non éprouvée sur documents réels** (ST-0202) : le produit visé est configurable depuis l'espace administrateur, mais aucun endpoint Mindee ne cible spécifiquement la carte grise ivoirienne. Le repli sur le balayage du texte, filtré par le chiffre de contrôle du VIN, est ce qui fait tenir la fonction — à mesurer sur de vrais documents avant d'annoncer un taux.
- ✅ **File d'envoi côté client écrite** (ST-0206, 04/08/2026) : `UploadManager` + `PendingUploadStore` persistant, reprise après redémarrage, empreinte SHA-256 en Dart pur. Elle ne réessaie **jamais** d'elle-même — un minuteur enfoui viderait le forfait de quelqu'un dans son dos.
- ✅ **Import de flotte en file au-delà de 200 lignes** (03/08/2026, débloqué par le rétablissement du cron). En deçà, il reste synchrone — rendre un identifiant de suivi pour douze véhicules serait une régression d'usage déguisée en progrès. Au-delà, il est **découpé en tranches de 50** : un unique travail de mille lignes tiendrait le travailleur et disputerait le verrou d'audit pendant plusieurs minutes, rejetant les actions de tous les autres utilisateurs. Entre deux tranches, le verrou est rendu. Réponse **202** avec un identifiant de suivi (`GET /fleet/{company}/imports/{id}`), rattaché à la société : un identifiant d'import n'ouvre pas l'inventaire d'un tiers. Idempotent par construction — une tranche rejouée recompte ses lignes en doublons, pas en erreurs. Plafond absolu à 10 000 lignes ; au-delà, c'est une reprise de données. Le fichier est effacé du disque de travail dès l'import terminé : il porte l'inventaire complet d'un parc.
- ✅ **Quota d'enregistrement BLOQUANT depuis le 03/08/2026** (décision produit d'Aboubakar) : au-delà des 3 places gratuites et des places payées, `POST /assets` rend **402** avec l'état du quota et le prix de la place suivante. Contrôlé dans `AssetRegistrationService`, donc pour tout chemin d'enregistrement présent et à venir. Les flottes en sont exclues — elles relèvent de leur abonnement et de la suspension douce. **Conséquence à surveiller** : on ne déclare pas volé un bien qu'on n'a pas enregistré ; un particulier au-delà de son quota devra donc payer avant de pouvoir signaler un vol, à un moment où il est déjà victime. Si cela se constate en usage, l'exception à envisager est d'autoriser l'enregistrement lorsqu'une déclaration de vol suit immédiatement.
- **Décompte mensuel ≠ facture fiscale** : le document produit porte le calcul mais pas les mentions exigibles en Côte d'Ivoire (régime, numéro de contribuable, TVA) — à faire valider par un comptable avant émission.
- ✅ **Frais de dossier BLOQUANTS au DÉPÔT, montant réglable en exploitation** (décision produit d'Aboubakar, 03/08/2026, après réexamen). Ouvrir un dossier et y verser des pièces restent libres ; c'est `submit()` qui exige le règlement, parce que c'est là que le bien est gelé et le détenteur prévenu — le moment où la réclamation commence à coûter à quelqu'un d'autre. Refus en **402** portant le montant. Remboursé si la réclamation aboutit.
  - **La soupape** : `PUT /api/v1/admin/claim-fee` accepte **0**, qui lève entièrement le blocage. C'est ce qui empêche le filtre anti-nuisance de devenir un filtre anti-pauvres — la victime d'un enregistrement frauduleux est souvent celle qui a le moins. Chaque changement est journalisé avec l'ancien ET le nouveau montant : savoir qu'un changement a eu lieu ne suffit pas à juger s'il a restreint ou ouvert l'accès au recours.
  - Zéro est traité comme une valeur légitime et non comme un réglage absent — un `?:` naïf rétablirait des frais que l'administrateur vient de lever. Un test le verrouille.
  - Les tests d'arbitrage tournent en mode gratuit : ils portent sur l'arbitrage, pas sur les frais.
- ✅ **Acheminement des notifications en file `notifications`** (03/08/2026). La ligne du centre in-app reste écrite dans la requête — c'est la trace durable — et seule la sortie hors application part en file. Ce que la file apporte et qui était impossible avant : **le réessai**. En synchrone, un échec de passerelle ne laissait que deux choix, refuser l'action métier ou perdre l'alerte ; aucun n'est acceptable pour une déclaration de vol. Trois tentatives espacées (60 s, 300 s) ; un doublon vaut mieux qu'une perte. Le push, non critique, n'est pas rejoué. **L'action métier ne peut jamais échouer à cause d'un acheminement, quel que soit le pilote de file** — y compris `sync` et file en panne : un test le verrouille. Travailleur relancé chaque minute par le planificateur (`--stop-when-empty --max-time=50`), faute de superviseur sur mutualisé : une alerte peut donc attendre jusqu'à une minute avant de quitter la plateforme.
- **Push et SMS à configurer avant lancement** : sans clé FCM ni passerelle SMS renseignées, seules les notifications in-app sont délivrées.
- ✅ ~~`AuditChainTransactionConcurrencyTest` sensible à la charge machine~~ — **note périmée, corrigée le 04/08/2026.** L'invariant vérifié n'est plus « les 16 processus réussissent » mais « tout processus qui a annoncé un succès a SON entrée, aucune perte, aucune substitution, chaîne vérifiable », avec un plancher de deux succès pour qu'un test où tout échoue ne passe pas sans rien éprouver. Un échec n'est accepté que s'il porte « verrou d'écriture non obtenu ». Trois exécutions consécutives vérifiées le 04/08/2026.

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

## Client mobile — socle et consultation (04/08/2026)

`mobile/`, deux paquets :

| Paquet | Ce qu'il porte | Vérifiable sans appareil |
|---|---|---|
| `preuve_core` | Contrats d'API, modèles, contrôle local des identifiants, versions | **Oui** — `dart analyze` + 24 tests |
| `preuve_app` | Application Flutter : thème DJASSA, saisie, verdict | Non |

**LA SÉPARATION N'EST PAS DÉCORATIVE.** Les règles qui décident ce qu'un acheteur
voit vivent en Dart pur, sans dépendance à Flutter : elles s'exécutent en une
seconde dans une console, sans émulateur. Ne jamais faire remonter une règle
métier dans `preuve_app`.

**Partis pris à ne pas rediscuter :**
1. **Erreurs traduites en CONDUITES, pas en codes** (`exceptions.dart`). Un
   client qui ne verrait que des nombres traiterait un quota épuisé comme une
   faute de saisie. 402 → payer · 409 → réclamer, jamais réessayer · 426 →
   mettre à jour SANS bloquer la consultation · 429 → défi si proposé.
2. **`getAnonymous()` : le jeton n'accompagne jamais une consultation.** Un
   porteur de jeton échappe au plafond horaire, mais ce confort ne vaut pas de
   transformer une consultation anonyme en historique nominatif.
3. **Le contrôle local n'est jamais plus sévère que le serveur.** Un client trop
   strict rendrait inconsultables des biens enregistrés, et le défaut serait
   invisible côté serveur — aucune requête n'y parviendrait. En cas de doute,
   laisser passer.
4. **Aucune dépendance externe** : `dart:io` suffit. Une bibliothèque HTTP de
   plus est une chaîne d'approvisionnement de plus dans une application qui
   transporte des pièces d'identité.
5. **Libellés et couleurs de statut viennent du serveur** (CT-04). Une table
   locale se périmerait au premier statut ajouté, sur des téléphones qui ne se
   mettent pas à jour.

**⚠️ `preuve_app` N'A JAMAIS ÉTÉ COMPILÉE** : le SDK Flutter n'est pas installé
sur la machine de développement. Elle n'emploie que des composants du cœur de
Flutter, sans paquet tiers — risque borné, pas nul. Premier `flutter run` à
prévoir avec quelques ajustements.

Éprouvé contre la production via `preuve_core/tool/sonde_production.dart` :
lecture de la version exigée, verdict « pas enregistré », refus d'une saisie trop
courte.

**Lot 2 du cœur livré le 04/08/2026** (41 tests) : connexion par code avec coffre
à jeton injecté, catalogue à ETag, enregistrement avec chronomètre CT-02, et
**file d'envoi différée avec reprise** (ST-0206, CT-05).

- L'identifiant de session est tiré par le CLIENT : un `POST` rejoué retrouve la
  session au lieu d'en ouvrir une seconde — sans quoi chaque coupure laisserait
  une session orpheline sur le disque du mutualisé.
- Sur conflit, **la position rendue par le serveur fait autorité**, jamais le
  compteur local : le client peut avoir cru envoyer un morceau jamais arrivé.
- **Défaut trouvé en écrivant cette file** : le client traduisait TOUT 409 en
  « déjà enregistré », alors qu'un 409 d'envoi signifie « position
  désynchronisée ». Deux conflits de sens opposé sous le même code, désormais
  distingués par le CORPS et non par le chemin.
- Le coffre à jeton est une INTERFACE. Si l'écriture échoue, la session ne
  s'ouvre pas ; à l'inverse, une déconnexion efface le jeton local même quand le
  serveur ne répond pas — on se déconnecte souvent parce qu'on prête son
  téléphone.

## Trois trous d'API découverts en écrivant les écrans (04/08/2026)

Le cœur mobile lot 3 avait été écrit contre `docs/api/integration-client.md`,
qui donnait les CHEMINS sans les CORPS. En branchant les écrans, trois parcours
se sont révélés inatteignables — non pas mal implémentés, mais **sans porte
d'entrée**. Tous corrigés, tous verrouillés par test.

1. **`GET /api/v1/assets` n'existait pas.** Un particulier n'avait aucun moyen
   de connaître l'identifiant interne de ses propres biens ; or vol, transfert
   et réclamation passent tous par `/assets/{id}/…`. La flotte avait son tableau
   de bord, le particulier n'avait rien. Nouvelle route + `OwnedAssetResource` —
   qui rend le numéro complet et l'`id`, ce que la vue publique tait. **Ce n'est
   pas un assouplissement de la règle n° 4** : elle protège l'identité du
   détenteur contre les TIERS, elle n'a jamais interdit à quelqu'un de relire ce
   qu'il a saisi. Aucune identité n'y figure malgré tout.
2. **`GET /api/v1/transfers` n'existait pas.** L'invitation de l'acheteur est un
   code par SMS, qui ne porte aucun numéro de transfert : il n'avait rien à
   confirmer, et tout transfert expirait de lui-même. La liste rend aussi le
   `role`, calculé côté serveur — le deviner ferait confirmer une vente à qui
   croyait accepter. Le bien y est rendu en vue **publique même pour le
   vendeur** : un transfert part vers un numéro saisi à la main.
3. **`POST /api/v1/claims` (par `public_ref`) n'existait pas.** Réclamer suppose
   de désigner le bien d'un AUTRE, dont l'identifiant interne n'est communiqué à
   personne. Le recours de l'EP-05 existait en base et nulle part ailleurs. Pire,
   le refus 409 annonçait `claim_url = /api/v1/claims?public_ref=…`, **un GET qui
   n'a jamais été servi** : un client qui l'aurait suivi menait la victime vers
   un 404, au moment précis où on lui apprend que son bien est au nom d'un autre.

**Trois contrats du cœur mobile étaient faux et leurs tests passaient** :
`recipient_phone` au lieu de `buyer_phone`, `role` absent à la confirmation,
`document_id` en JSON là où le serveur attend un `multipart` avec le fichier.
Cause racine : `FakeTransport` n'enregistrait que le CHEMIN, jamais le CORPS. Il
enregistre désormais les deux, et trois tests verrouillent les noms de champs.
**Un harnais qui ne peut pas constater un contrat rompu ne prouve rien.**

Les natures de preuve sont passées de chaînes libres à `EvidenceKind` dans le
cœur — deux des quatre valeurs employées étaient inventées. Figer cette liste
n'est pas contradictoire avec la configuration distante des catégories : une
catégorie est une donnée d'exploitation, cette liste EST la grille d'arbitrage.

## Mode lecture seule : les lectures passent désormais (04/08/2026)

`EnsurePlatformIsWritable` bloquait TOUT son groupe, GET compris — inventaire,
quota, notifications, dossier de réclamation. « Lecture seule » doit vouloir dire
ce que son nom annonce : aucune de ces requêtes n'écrit, et les refuser
transformait une indisponibilité partielle en panne apparente. Aligné sur
`EnsureAppIsSupported::isMethodSafe`.

## Écrans mobiles livrés (04/08/2026)

Consultation (déjà là) · connexion · **inventaire · enregistrement piloté par le
catalogue · vol et levée · fin de vie · transfert (proposer, confirmer des deux
côtés, annuler) · réclamation (ouvrir, annoncer ses pièces, déposer)**.

- L'application **ouvre toujours sur la consultation**, et la reprise de session
  ne retarde ni ne bloque l'affichage : quelqu'un qui vérifie une moto au marché
  n'attend pas le réseau.
- La connexion n'est demandée **qu'au moment où elle sert** (CT-06) : au clic sur
  « Mes biens », ou sur « Contester cet enregistrement » — jamais avant un
  verdict.
- « Déclarer volé » est **le premier bouton de la fiche**, en rouge, jamais dans
  un menu.
- Les actions impossibles sont **absentes et expliquées**, pas grisées : un bien
  gelé refuserait le geste, et le refus paraîtrait arbitraire.
- Les frais de dossier ne sont **jamais écrits dans l'application** : ils se
  lisent dans le refus 402, parce qu'un administrateur peut les mettre à zéro.

**⚠️ TOUJOURS PAS COMPILÉ** : le SDK Flutter n'est pas installé, et `preuve_app`
n'a même jamais reçu un `pub get` — aucune analyse statique n'est possible sur
ces écrans. `preuve_core`, lui, reste vérifié (65 tests, `dart analyze` propre).

## Détail d'un bien, et une file de modération qui décide enfin (04/08/2026)

**Côté détenteur** : `GET /assets/{id}/documents` et la pièce elle-même, servie
en clair par une route AUTHENTIFIÉE — jamais un lien signé, qui serait une
capacité au porteur sur un titre de propriété. La fiche mobile montre les photos
ARRIVÉES et celles EN ATTENTE d'envoi, distinguées : ne montrer que les unes
ferait croire les autres perdues. On peut en ajouter à tout moment — une voiture
change en trois ans.

**Côté back-office** : `GET /admin/assets/{id}` ouvre le dossier complet, et les
lignes du registre sont cliquables. **IL NE DIT TOUJOURS RIEN DU DÉTENTEUR**, ni
de qui a agi : la règle métier absolue n° 4 n'a pas d'exception interne. La
réponse porte à la place l'adresse du chemin prévu — levée sur réquisition,
administrateurs seuls, fondement consigné, double trace. Deux tests le
verrouillent, dont un qui relit la réponse entière.

**La file de modération ne décidait de RIEN.** L'API était complète depuis le
début — file, images, décision — mais la console se contentait de lister : les
trois images d'un dossier d'identité n'étaient pas même atteignables, et aucun
bouton n'existait. Quelqu'un déposait sa pièce, personne ne pouvait la valider,
et la promesse « examiné sous 48 heures » ne tenait à rien. Vignettes,
extraction et décisions sont là, **motif exigé au refus** — un refus sans raison
se redépose à l'identique. Les gestionnaires sont posés en JavaScript et jamais
en attribut (`script-src 'unsafe-inline'` est interdit) : un test l'exige.

Les réclamations restent hors de cette file : elles se tranchent sur une grille
pondérée, et les réduire à deux boutons ferait décider d'un transfert de
propriété d'un clic.

## Tarifs réglables et paiement Paystack (04/08/2026)

**Tous les montants de la plateforme en un seul endroit** (`PricingService`,
écran « Tarifs » réservé aux administrateurs) : rapport détaillé, place
d'enregistrement, frais de dossier. Un prix qui vit dans le code est un prix
qu'on ne peut pas corriger sans livrer.

**ZÉRO EST UNE VALEUR, PAS UN VIDE.** Un tarif à zéro rend la chose GRATUITE :
le rapport s'ouvre sans paiement ni jeton d'opérateur, l'enregistrement redevient
illimité, le recours redevient libre. **`QuotaService` contenait exactement le
piège annoncé** — un repli `?: 500` qui rétablissait un tarif supprimé. Corrigé,
et tenu par un test. Chaque changement est journalisé avec l'ANCIEN et le NOUVEAU
montant.

**Paystack était absent.** L'achat créait une intention et rendait « réglez
auprès de l'opérateur », **sans adresse** : personne ne pouvait payer.
`PaystackGateway` ouvre la transaction et rend l'adresse ; la référence est tirée
par nous et posée AVANT la redirection, pour que le webhook la rapproche et que
l'unicité `(provider, provider_ref)` rende un rejeu inoffensif.

**LE RETOUR NE PROUVE RIEN**, et c'est la règle à ne pas assouplir : seul le
webhook signé accorde l'accès. Sans elle, rappeler l'adresse de retour à la main
suffirait à obtenir un rapport sans payer. Tant que le jeton rend 404, le
paiement n'est pas confirmé — **ne jamais faire recommencer le paiement**.

**Le règlement s'ouvre dans le NAVIGATEUR DU SYSTÈME**, jamais dans une vue web
embarquée : une page de carte bancaire sans barre d'adresse prive l'utilisateur
du seul endroit où vérifier chez qui il paie. D'où `url_launcher`, **troisième et
dernière dépendance mobile**.

**`POST /reports` par référence publique** : même raison que pour la réclamation
— un acheteur ne connaît pas l'identifiant interne, et le publier permettrait de
balayer le registre.

Migration : `report_purchases.payment_id` devient facultatif. Créer un paiement
de zéro franc pour satisfaire une contrainte mettrait un mensonge dans le journal
comptable. Écart assumé avec le schéma de référence, consigné dans la migration.

## Ce qui manque encore pour lancer (état vérifié le 04/08/2026)

Constaté en base et en réglages, pas supposé :

1. **`sms.provider` vaut `mail`** — le code de connexion part par COURRIEL.
   Quelqu'un qui n'a qu'un numéro de téléphone ne peut pas ouvrir de compte.
   C'est le blocage n° 1 : la passerelle est configurable sans livraison, il
   manque un opérateur et ses clés.
2. **Aucune clé Paystack** — tout tarif supérieur à zéro produit un refus au
   paiement. L'écran Tarifs le dit en rouge. Repli : mettre les montants à zéro.
3. **Aucune clé Turnstile** — le plafond de 10 consultations/h échoue FERMÉ, sans
   échappatoire, sur le parcours qui est la promesse du produit.
4. **Aucune détection de vivacité** — et c'est plus grave depuis que la
   validation d'identité est opérationnelle : une photo de photo passe.
5. **`legal.contact_email` vide** — la page de confidentialité annonce un guichet
   « en cours d'ouverture », alors que la Loi 2013-450 impose un responsable
   joignable.

Ce qui va bien : le cron bat, l'agrégation des consultations tourne, l'ancrage a
un destinataire, et **le traitement d'une consultation prend 60 ms** mesuré SUR
le serveur. Les mesures depuis le poste de développement restent inexploitables
(un fichier statique y varie de 0,0 s à 4,5 s).

## Déploiement du 04/08/2026 — et le piège d'adresse qui a coûté une heure

**Tout le travail serveur de la session est en production** (`a16f412`). Avant
lui, l'application mobile parlait à une API qui n'existait pas : `GET /assets`
rendait **405**, `GET /transfers` et `POST /claims` **404**. C'était la cause du
blocage signalé en usage — pas un défaut du client.

**LE SERVEUR DE `preuve.click` EST `62.72.37.247`, PORT 65002.** Le
`known_hosts` de la machine de développement contenait `145.79.20.193`, une
AUTRE machine du même compte Hostinger — celui-ci héberge huit domaines. Une
clé publique correctement déposée y était refusée sans que rien ne le laisse
deviner : le refus d'une clé et le refus d'un compte inexistant sont
indistinguables. **Toujours partir de `dig +short preuve.click`, jamais d'une
entrée d'historique.**

Vérifié après déploiement : consultation 200 en **0,5 s**, écriture sans jeton
401, console redirigée vers `/admin/connexion`, `.env` en 403, politique de
sécurité complète de l'application (pas celle de l'hébergeur), aucun
`X-Powered-By`. Aucune migration n'était en attente.

## SDK Flutter installé — l'application compile et tourne (04/08/2026)

**Flutter 3.44.8 / Dart 3.12**, cloné dans `~/development/flutter`. Le clone
superficiel oblige l'outil à récupérer tout l'historique pour se dater : n'en
fetcher que les ÉTIQUETTES (`git fetch --depth 1 origin '+refs/tags/*:…'`) rend
la version en une seconde. `preuve_app` n'avait aucun dossier de plateforme ;
générés par `flutter create --platforms=ios,android --org click.preuve`.

**Le défaut trouvé à la première compilation est le plus grave possible** :
`Djassa.build()` levait une assertion, donc **l'application ne démarrait sur
aucun écran**, consultation comprise. `ThemeData.textTheme` ne porte que les
COULEURS — les tailles sont fusionnées plus tard, par locale — et y appliquer un
`fontSizeFactor` est interdit. La géométrie est désormais fournie explicitement,
et deux tests la verrouillent.

Deux autres corrections faites en regardant l'écran réel, invisibles autrement :
l'`autofocus` de l'accueil ouvrait le clavier au lancement et poussait le titre
et la promesse hors écran ; et un `minHeight` sur les onglets laissait la barre
de navigation **prendre tout l'écran** — la contrainte verticale qu'elle reçoit
n'est pas bornée.

Autorisations d'images déclarées (sans elles : plantage à l'ouverture de
l'appareil photo et rejet par l'App Store) et `INTERNET` ajouté au manifeste
Android — le gabarit ne le déclare qu'en débogage, et l'absence ne se voit
qu'après publication. **`flutter analyze` vert, 6 tests d'écran.**

## Alignement sur la maquette mobile (04/08/2026)

`docs/Preuve - Mobile.html` est un paquet auto-décompressant ; le prototype en a
été extrait, et **les jetons recopiés plutôt que réinventés** : crème `#FFF6E8`,
encre `#2B1D12`, accent `#D97706`, sourdine `#5A4632`, étiquette `#8A7358`,
rayon 16, contour 3, cible 64, et l'**ombre dure** décalée sans flou. Un
`elevation` de Material aurait rendu un dégradé générique, qui disparaît au
soleil — c'est ce trait net qui fait reconnaître l'application.

**Polices déposées** : Bricolage Grotesque, instanciée en deux graisses FIXES
depuis la variable officielle (Flutter n'applique les axes d'une variable que si
on les nomme un à un), et Atkinson Hyperlegible — dessinée par le Braille
Institute pour distinguer 0/O et 1/I, ce qui est exactement ce qu'on lit ici.

Écrans refaits : accueil, verdict (pleine couleur, symbole et mot qui tranche),
connexion en deux temps, **inscription** (écran que l'application n'avait pas),
enregistrement **en quatre étapes** avec chronomètre CT-02 affiché, alertes,
inventaire, et **barre de navigation persistante** à trois onglets.

**Trois écarts assumés à la maquette :**
1. Le compteur « 3 208 vols déclarés » n'est pas mesurable. L'écran dit ce que la
   plateforme FAIT, plutôt qu'un chiffre plausible et faux — même raison que les
   « téléchargements » de la console.
2. Les puces de démonstration deviennent « où trouver le numéro » : un prototype
   propose des exemples, un produit sert quelqu'un debout devant une moto.
3. Le scan (accueil et étape 2) est **désactivé et le dit**. Le serveur sait déjà
   pré-remplir (ST-0202) ; c'est le client qui manque. Une cible qui disparaît
   d'une version à l'autre se cherche.

**Deux manques serveur trouvés en écrivant ces écrans**, tous deux corrigés :
`POST /assets` rendait la vue publique — sans identifiant interne, impossible de
rattacher une photo au bien qu'on vient de créer, alors que la réponse part au
propriétaire lui-même ; et l'inventaire ne portait aucun compteur de
consultations, que la maquette affiche à deux endroits. Ajouté en une requête
groupée, **en nombre et jamais en liste**.

## Photos, empreintes et file persistante (04/08/2026)

**`image_picker` ajouté — SECONDE ET DERNIÈRE EXCEPTION** au principe « aucune
dépendance externe » (décision d'Aboubakar). Justifiée comme la première : écrire
soi-même le pont vers la caméra et la photothèque de deux plateformes serait plus
risqué que d'en dépendre, et le greffon est maintenu par l'équipe Flutter. Sans
elle, aucun bien ne dépassait « Déclaré » — ni justificatif, ni KYC, ni pièce de
réclamation, donc **aucune réclamation jamais gagnée sur la grille d'arbitrage**.

**SHA-256 écrit en Dart pur dans le cœur**, plutôt que d'ajouter `crypto` : le
cœur ne dépend de rien, et déplacer l'empreinte dans `preuve_app` aurait fait
remonter une règle métier hors du cœur — c'est elle qui prouve qu'un fichier
envoyé en morceaux est arrivé intact. Vérifié contre les **vecteurs officiels
FIPS 180-4**, y compris le million de « a ». Première version quadratique par son
tamponnage : **15 s pour un mégaoctet, mesuré** — l'application aurait paru
plantée avant d'envoyer un octet. Corrigée par un bloc de taille fixe.

**`UploadManager`** : ce qui manquait à ST-0206 n'était pas l'envoi mais ce qui
survit à la fermeture de l'application. La file est persistée **dans le coffre**
(`flutter_secure_storage`, déjà présent) plutôt que dans un fichier — écrire un
fichier aurait demandé `path_provider`, et ce qu'on y range n'est pas anodin :
pas les images, mais leurs CHEMINS, c'est-à-dire « cette personne a une photo de
CNI à cet endroit ». Volume borné à 50 pièces : remplir le trousseau ferait
échouer l'écriture du JETON DE SESSION, donc déconnecter quelqu'un pour une photo.

Elle distingue ce qui se réessaie de ce qui ne se réessaie pas : une coupure garde
la pièce, un fichier modifié ou un refus du serveur l'en retire **avec sa raison**
— une pièce disparue en silence ferait croire un dossier complet. Une session
tombée n'en perd aucune. Et elle ne réessaie **jamais** d'elle-même : un minuteur
enfoui viderait le forfait de quelqu'un dans son dos.

**Écrans ajoutés** : alertes + préférences, envois en attente, KYC, et le dépôt
de justificatifs depuis la fiche d'un bien.

**Reste au mobile** — plus rien de bloquant au 05/08/2026 : scan, flotte,
rapport, polices DJASSA et déclarations de permission iOS sont tous en place,
vérifiés sur simulateur. Historique conservé : **déposer les polices
DJASSA** sous `assets/fonts/` — non déclarées pour l'instant, car déclarer une
police sans son fichier fait échouer la construction. Prévoir aussi les
**déclarations de permission** iOS (`NSCameraUsageDescription`,
`NSPhotoLibraryUsageDescription`) : sans elles, l'application est rejetée par
l'App Store et plante à l'ouverture de l'appareil photo.

## Documentation écrite (04/08/2026)

Trois documents qui n'existaient pas, et dont l'absence bloquait quelqu'un d'autre
que moi :

- **`docs/infrastructure/deploiement.md`** — procédure, vérification par `curl`,
  retour en arrière, interdits. Surtout : les **quatre pièges de l'hébergement**
  trouvés en regardant les réponses du serveur et jamais en lisant le code
  (version PHP du web ≠ CLI, dossier de `public/` masquant un préfixe de route,
  LiteSpeed qui réécrit CSP et `X-Powered-By`, planificateur invoqué deux fois).
- **`docs/api/integration-client.md`** — intégration par PARCOURS et non par
  route : cent-deux routes ne disent pas dans quel ordre appeler. Ne documente
  que ce qui ne se devine pas, et se termine par ce que l'API ne rendra jamais.
- **`README.md`** — c'était encore celui du squelette Laravel sur un dépôt public.

**Prochain chantier : l'application Flutter.** Le guide d'intégration en est le
prérequis et il est prêt. Restent à trancher côté produit : le fournisseur de
détection de vivacité (KYC), et le calendrier de la file d'envoi différée côté
client.

## Scan de carte grise, flotte B2B, et trois contrats devinés (05/08/2026)

**ST-0202 et EP-07 sont branchés côté mobile.** Le serveur était prêt pour les
deux depuis des semaines ; il ne leur manquait qu'un point d'entrée client —
c'est la sixième fois cette série qu'une capacité complète du serveur n'était
atteignable par personne.

**Le scan** pré-remplit le formulaire d'enregistrement et **laisse le numéro
modifiable** : une OCR sur une carte grise froissée se trompe, et un numéro de
châssis faux enregistre le bien de quelqu'un d'autre. L'identifiant du scan
repart avec la soumission, pour mesurer combien de propositions survivent
intactes — sans cette mesure, on ne saurait pas si la fonction sert.

**La flotte ouvre sur ce qui ne va pas**, jamais sur un total : un loueur de
quarante motos sait qu'il en a quarante. Elle **nomme** les véhicules concernés
par leur référence publique — jamais leur immatriculation, parce que ce tableau
s'ouvre au comptoir et se lit par-dessus l'épaule.

### Trois noms de champs devinés, les trois faux

Éprouvé contre les DONNÉES RÉELLES (société de démonstration créée en
production), le tableau de bord affichait **zéro véhicule sur un parc de
quatre** :

| Ce que le modèle lisait | Ce que le serveur rend       |
|-------------------------|------------------------------|
| `total`                 | `fleet_size`                 |
| `lookups_30d` (entier)  | `{total, most_viewed}`       |
| — (ignoré)              | `needs_attention` (par bien) |

Aucun test ne l'a vu, **parce que les tests avaient été écrits avec les mêmes
noms devinés** : la fixture confirmait l'invention au lieu de la démentir. Les
fixtures de `fleet_test.dart` (core et app) sont désormais la charge utile de
production recopiée sans retouche.

**Règle qui en sort** : un modèle de réponse ne se déduit pas du contrôleur ni
du bon sens — il se relève sur une réponse réelle avant d'écrire la fixture.
Un nom faux ne casse rien, il rend zéro en silence.

### Une régression expédiée au commit précédent

La carte « Je scanne la carte grise » était figée à `height: 130` pour un
contenu de 149 : **débordement de 19 px**, et bien pire chez quelqu'un qui
grossit le texte de son téléphone — c'est-à-dire précisément ceux à qui le scan
évite de taper un châssis à la main. Passée en `minHeight`. Elle était partie en
production sans que les tests d'enregistrement soient relancés.

**Parc de démonstration en production** : société #1 « DEMO Loueur Abidjan »,
quatre véhicules aux plaques volontairement fictives (`DEMO-MOTO-0001`…), créés
hors chaîne d'audit comme le fait `DemoSeeder`.

**Couverture** : 99 tests `preuve_core`, 18 tests `preuve_app`, 839 tests Pest.

## Vu tourner sur simulateur, et deux contradictions corrigées (05/08/2026)

Les écrans de flotte et de plafond n'avaient jamais été vus rendus ailleurs
qu'en test de widget, c'est-à-dire à 800x600 — pas à la taille d'un téléphone.
Passés sur simulateur iPhone 15, deux défauts que les tests ne pouvaient pas
voir :

- **Le nom de la société s'affichait deux fois** en haut du tableau de flotte,
  dans la barre puis en titre. Une ligne entière perdue sur le seul écran où un
  loueur cherche ce qui ne va pas. La barre dit maintenant « Ma flotte ».
- **L'écran de plafond se contredisait** : « PATIENTE » en très gros au-dessus
  d'un bouton qui fait passer tout de suite, et un message serveur disant
  « Réessayez dans un moment ». Le grand mot est lu bien avant le bouton — il
  aurait fait renoncer quelqu'un qui pouvait continuer. **Corrigé des deux
  côtés** : le client affiche « UN CONTRÔLE », et `LookupResult::rateLimited()`
  prend désormais un argument, de sorte que le message change selon qu'un défi
  est configuré ou non. Le front web en profite sans une ligne de plus.

**Leçon de méthode** : un test de widget prouve la logique d'affichage, pas la
mise en page ni la cohérence du discours. Les écrans neufs doivent passer sous
les yeux, à la taille réelle, avant d'être annoncés faits.

## Un bien peut être en base et introuvable en consultation (05/08/2026)

Constaté sur les quatre véhicules de démonstration créés la veille : ils
s'affichaient dans le registre, dans l'inventaire du détenteur et dans le
tableau de flotte — **partout sauf là où le produit sert**. Leur
`identifier_normalized` avait été écrit tel quel, tirets compris, en
court-circuitant `AssetRegistrationService` ; la consultation, elle, normalise
toujours, et ne trouvait donc rien.

Conséquence plus grave que l'affichage : l'index unique
`(identifier_normalized, active_flag)` ne protège plus rien pour ces lignes. Un
enregistrement régulier du même engin n'aurait rencontré **aucune collision** —
la règle n° 3 était contournée sans que rien ne l'indique.

**Données corrigées en production** (les quatre lignes renormalisées, `DEMO-AUTO-0003`
consulté avec succès), **et le piège refermé** : `DemoSeeder` passe désormais par
le normaliseur.

**Le test qui existait ne pouvait pas voir ça** : il consultait
`identifier_normalized`, c'est-à-dire la forme déjà normalisée en base. Il
interroge maintenant `identifier_raw`, la saisie qu'un acheteur recopie du
pare-chemin. Et le jeu de démonstration porte enfin des plaques écrites comme
les gens les écrivent (`5000 AB 01`, `AA-123-BC`) — sans quoi le test passait
même avec le seeder cassé, faute d'avoir quoi que ce soit à normaliser. Vérifié
dans les deux sens avant d'être conservé.

## Tous les modèles clients confrontés au serveur réel (05/08/2026)

Après quatre noms de champs devinés et faux dans la même journée, chaque
`fromJson` de `preuve_core` a été confronté à la **réponse réelle de la
production**, en interrogeant les vingt-six points d'entrée avec un jeton
temporaire (révoqué depuis).

**Un seul défaut restant, mais réel** : `KycStatus` lisait le motif de refus
sous `rejection_reason`, un nom que le serveur n'envoie pas — il le place dans
`last_submission.review_reason`. L'écran KYC affichait déjà un encadré rouge
« Dossier refusé : … » ; le motif existait côté serveur, l'encadré existait côté
client, **et les deux ne se sont jamais rencontrés**. Un dossier refusé se
redéposait donc à l'identique, pour être refusé à l'identique : deux fois
l'attente pour la personne, deux fois le travail pour l'agent.

Ajouté au passage : `can_submit` est désormais lu **du serveur** plutôt que
déduit localement. C'est lui qui refusera ; une règle recopiée dans le client
dériverait au premier changement.

**Vérifiés conformes** : catalogue, inventaire + quota + pagination, transferts
(dont le `role` calculé par le serveur), réclamations et frais, documents,
notifications et leur `meta`, préférences, rapport, `config/app`, `auth/me`.

**Ce qui rendait le balayage nécessaire** : un nom de champ faux ne casse rien.
Il rend zéro, vide ou nul, en silence — et un test écrit avec le même nom
confirme l'invention au lieu de la démentir.

## Scan sans compte, et le pré-remplissage qui ne remplissait rien (05/08/2026)

**Décision d'Aboubakar** : ouvrir le scan de carte grise aux visiteurs sans
compte, plafonné. Le bouton « Je scanne » de l'accueil était grisé depuis le
début — le raccourci existait, mais pas pour l'acheteur anonyme, celui que le
produit sert d'abord. Recopier dix-sept caractères de châssis debout devant un
vendeur est le premier motif de « bien introuvable ».

**Ce qui a été construit** : `POST /lookup/scan`, anonyme, et
`AnonymousScanAllowance` — un plafond de dépense **distinct** de celui des
consultations. Une consultation lit la base en deux millisecondes ; un scan
appelle un fournisseur qui facture à l'appel. Les compter ensemble ferait qu'un
après-midi de vérifications au marché épuise le budget d'extraction de la
plateforme, ou qu'un plafond taillé pour la dépense étrangle la consultation.
Cinq scans par heure et par empreinte, sortie par défi anti-robot, décompte en
cache (aucune adresse conservée, pas même hachée en ligne).

**La route ne consulte RIEN, délibérément.** Le scan d'enregistrement rend
`existing_asset` pour éviter un formulaire de quatre-vingt-dix secondes voué au
refus ; ici ce champ serait une consultation déguisée, échappant au journal, aux
compteurs de trente jours, à l'alerte de pic et au plafond horaire lui-même. Un
test le verrouille.

### Le pré-remplissage n'a JAMAIS fonctionné

Cinquième contrat deviné de la journée, et le plus coûteux : le serveur rend
`identifier` comme un **objet** `{value, type}`, le client le lisait comme une
chaîne. `ScanResult.identifier` valait donc **toujours null** — le scan de
carte grise livré la veille ouvrait un formulaire vide, exactement comme si le
document avait été illisible, sans le dire. Trouvé en écrivant le test du
nouveau parcours ; jamais vu avant parce que la fonction n'avait pas de test
avec une charge utile réelle.

### Zéro coupe la dépense

`scan_rate_limit.anonymous_per_hour = 0` désactive entièrement le scan anonyme —
budget épuisé, fournisseur en panne. Un repli naïf sur la valeur par défaut
rallumerait les appels payants que l'exploitant vient d'arrêter, et il ne le
découvrirait qu'à la facture. Le projet avait déjà payé ce piège sur les tarifs.

### Le bouton aurait échoué à tous les coups en production

Vérifié juste après : **sans clé Mindee, `read()` rend « illisible »
immédiatement**. Le bouton venait donc d'être activé sur un serveur incapable de
lire — chaque appui aurait pris une photo, l'aurait envoyée, aurait consommé une
place du plafond, et rendu un échec que l'utilisateur aurait attribué à SA
photo. Il aurait recommencé. C'était pire que le bouton grisé.

Trois corrections :

1. `DocumentReader::isConfigured()` distingue **« je n'ai pas pu lire ce
   document »** de **« je ne sais pas lire »**. Sans fournisseur, la route
   répond sans rien facturer ni décompter — aucun appel n'a eu lieu, punir
   l'utilisateur d'une panne qui n'est pas la sienne n'aurait aucun sens.
2. `GET /config/app` annonce `scan_available`, pour que le client **ne propose
   pas ce qui n'existe pas**. Le bouton est ABSENT, pas grisé.
3. **200 et non 503** : le contrat de cette route est « toujours 200, et on dit
   pourquoi », et le client traduit tout 503 en « plateforme en lecture seule »
   — un message faux, qui laisserait croire la consultation fermée.

### `GET /config/app` n'était lu par personne

Découvert en branchant le point 2 : la route existe depuis le 04/08,
`LookupService.release()` aussi, et **aucun écran ne les appelait**. La
plateforme pouvait donc exiger une mise à jour minimale sans qu'aucune
application ne l'apprenne — la fonction de forçage était injoignable, comme
l'inventaire, les transferts, la réclamation par référence, le rapport par
référence, la flotte, et le défi anti-robot avant elle. **Septième occurrence du
même motif.** L'annonce est désormais lue au lancement, sans jamais bloquer.

**Couverture** : 852 Pest, 116 `preuve_core`, 29 `preuve_app`.

## Questions ouvertes (à trancher avec Aboubakar)
- Direction design finale (Tampon vs Feu Vert selon cible de lancement) → conditionne le design system Flutter
- Nom définitif « Preuve » : vérifier marque OAPI + domaine (preuve.ci ?)
- Tarif exact rapport détaillé (500 vs 1000 FCFA) et paliers abonnement flotte
