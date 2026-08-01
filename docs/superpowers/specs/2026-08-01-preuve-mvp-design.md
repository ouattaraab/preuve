# PREUVE — Design du MVP

> Spécification de conception validée le 01/08/2026 avec Aboubakar Ouattara (OVERNETFLOW).
> Source : Project Brief BMAD v1.0, PRD v1.0, schéma MySQL v1.1, Backlog v1.1, prototypes DJASSA et Admin.
> Ce document fait foi sur les points où il diverge des documents de cadrage antérieurs.

## 1. Objet

Ce document fixe l'architecture du MVP de PREUVE : registre déclaratif de propriété et de statut des biens en Côte d'Ivoire. Il traduit les décisions d'arbitrage prises le 01/08/2026, corrige les écarts constatés entre les prototypes et le schéma de référence, et délimite le premier lot de livraison.

Il ne remplace pas le PRD : il le complète sur la conception technique et l'amende là où l'arbitrage l'a modifié.

## 2. Décisions arbitrées

| # | Décision | Conséquence principale |
|---|---|---|
| D1 | On démarre par l'application mobile et l'espace administrateur | Le web public SEO est différé après le lot 2 |
| D2 | Bascule au mieux-documenté, validée par un administrateur | Nouvelle table `takeover_requests` ; la réclamation payante cesse d'être le seul recours |
| D3 | La preuve de détention à la demande entre au MVP | Nouveau service `DetentionProofService` |
| D4 | Monétisation B2B **et** B2C dès le lancement | Rapport détaillé, certificat de consultation, quotas et abonnement flotte |
| D5 | Hébergement mutualisé Hostinger, documents sur stockage objet externe | Redis, Horizon, MinIO et Vault sont écartés du MVP |
| D6 | Types de biens dynamiques, définis en JSON | `asset_type` cesse d'être un ENUM ; configuration distante servie à l'app mobile |
| D7 | Téléphone comme identifiant principal, e-mail optionnel | Ouvre le repli OTP par e-mail et réduit la facture SMS |
| D8 | Premier lot = socle technique complet | Rendu démontrable par des seeders et une page de statut minimale |
| D9 | Back-office construit avec Filament v3, thémé à l'identique du prototype | Le design system du proto Admin fait foi |

### Décisions antérieures reconduites sans discussion

Backend Laravel 11 sur MySQL 8 ; consultation de statut gratuite, anonyme et sans compte ; toute écriture authentifiée par OTP ; anonymat symétrique inviolable ; chaîne d'audit append-only ; CinetPay interdit ; verticale d'amorçage loueurs B2B ; foncier en phase 2.

### Correction du contexte

La `memory-bank` indique « 3 prototypes HTML (Tampon / Sceau / Feu Vert), direction non arbitrée ». C'est obsolète : les prototypes livrés ne contiennent qu'une direction, **DJASSA — grand public**, et elle fait foi. `activeContext.md` doit être corrigé.

## 3. Contrainte d'hébergement et conséquences

L'hébergement mutualisé Hostinger ne permet ni processus résident, ni service tiers installable. Quatre briques de la stack initialement verrouillée sortent du MVP : **Redis, Horizon, MinIO, Vault**.

| Besoin | Solution MVP | Dette assumée |
|---|---|---|
| Files d'attente | Driver `database`, `queue:work --stop-when-empty --max-time=55` en cron chaque minute | Latence d'exécution à la minute. L'OTP reste synchrone, hors file |
| Cache et rate limiting applicatif | Driver `database` | Chaque consultation anonyme écrit en base |
| Tâches planifiées | Un cron unique `schedule:run` chaque minute | Aucune |
| Documents et photos | Cloudflare R2, chiffrement **côté application** avant envoi | Aucune — supérieur à un MinIO auto-hébergé sur ce point |
| Secrets | `.env` placé au-dessus du document root | Pas de rotation automatisée |
| Supervision des files | Table `failed_jobs` + écran admin « Supervision » | Pas de tableau Horizon |

**Prérequis à vérifier** : le plan Hostinger doit fournir un accès SSH et un cron à la minute. Les plans Premium descendent rarement sous cinq minutes et n'offrent pas toujours SSH, ce qui dégraderait l'ensemble du tableau ci-dessus. Business ou Cloud conviennent.

**Règle de conception contraignante** : tout le code s'écrit contre les abstractions Laravel (`Cache`, `Queue`, `Storage`), jamais contre une implémentation. La migration vers un VPS doit rester un changement de configuration, pas une réécriture.

### Cloudflare en frontal

Le domaine passe par Cloudflare (offre gratuite). Ce n'est pas un confort : le pare-feu applicatif et la limitation de débit s'appliquent **avant** que le mutualisé ne dépense un cycle processeur, ce qui protège directement la règle anti-profilage et l'objectif de consultation sous la seconde. Les pages publiques de statut sont servies depuis le cache CDN. R2 vit dans le même écosystème, sans frais de sortie.

La limitation de débit existe donc en deux couches : Cloudflare arrête les abus grossiers, la couche applicative applique la règle métier des dix consultations par heure. La règle survit à une indisponibilité de Cloudflare.

## 4. Architecture

### 4.1 Topologie

```
Flutter (iOS/Android) ─┐
                       ├─→ Cloudflare (WAF · rate limit · cache CDN)
Web public / SEO ──────┘         │
                                 ↓
                 Hostinger mutualisé — Laravel 11 (monolithe)
                 docroot → /public · .env au-dessus du docroot
                                 │
      ┌──────────────┬───────────┴──────────┬─────────────────┐
   MySQL 8      Cloudflare R2            Mindee          Paystack
 (Hostinger)  (documents chiffrés)      (OCR/KYC)       + PawaPay
                                            │
                              SMS (fournisseur à choisir) + repli OTP e-mail
```

### 4.2 Application unique, deux domaines d'authentification

Une seule application Laravel sert l'API et le back-office, séparés par domaine et par garde d'authentification :

- `api.preuve.ci` — API REST `/api/v1`, garde Sanctum, identité = téléphone vérifié par OTP
- `admin.preuve.ci` — Filament v3, garde `admin`, identité = e-mail professionnel + mot de passe + second facteur

**Ces deux domaines d'identité ne doivent jamais être fusionnés.** Un administrateur n'est pas un utilisateur privilégié : c'est un acteur d'un autre type, avec un cycle de vie, une politique de mot de passe et une traçabilité distincts. Le modèle `AdminUser` est séparé de `User`.

Le monolithe est retenu parce qu'il est le seul à tenir dans la contrainte d'hébergement : un déploiement, un cron, une base. L'isolation nécessaire s'obtient par les gardes, pas par la séparation des processus.

### 4.3 Services applicatifs

Contrôleurs fins, logique dans `app/Services/` :

| Service | Responsabilité | Dépendances |
|---|---|---|
| `AuditChain` | Point de passage **unique** de toute action sensible. `append()` sous verrou court | Aucune |
| `IdentifierNormalizer` | Normalisation, checksum VIN, Luhn IMEI, plaques, lots | Aucune |
| `AssetRegistrationService` | Enregistrement en quatre gestes, unicité, F1/V-PRV | Normalizer, AuditChain, CategoryRegistry |
| `CategoryRegistry` | Catégories et champs dynamiques, validation par catégorie, publication distante | Aucune |
| `StatusTransitionService` | Matrice des transitions, écriture dans l'historique | AuditChain |
| `TrustLevelEngine` | Règles F1/F2/F3 versionnées | AuditChain |
| `TakeoverService` | Bascule au mieux-documenté (§6) | StatusTransition, AuditChain |
| `DetentionProofService` | Preuve de détention à la demande (§7) | AuditChain |
| `ClaimArbitrationService` | Recevabilité, gel, grille pondérée, décision, export hashé | StatusTransition, AuditChain |
| `TransferService` | Double OTP, verrou, archivage de l'actif | StatusTransition, AuditChain |
| `LookupService` | Consultation publique, limitation de débit, journalisation | Normalizer |
| `ConsultationCertificateService` | Certificat de consultation horodaté et hashé (§8) | AuditChain |
| `NotificationService` | Agrégation horaire, routage in-app/push/SMS | Aucune |
| `ReportAccessService` | Jetons d'accès, parcours invité | PaymentService |
| `PaymentService` | Paystack, PawaPay, webhooks idempotents | AuditChain |

Chaque service s'utilise sans lire ses internes et se teste isolément.

### 4.4 Tâches planifiées

| Tâche | Fréquence | Rôle |
|---|---|---|
| `queue:work --stop-when-empty` | 1 min | Exécution des files (remplace Horizon) |
| `PromoteProvisionalAssets` | horaire | V-PRV → V-ACT à J+30 |
| `AggregateLookupNotifications` | horaire | Notifications de consultation agrégées |
| `ExpireTransfers` | horaire | Transferts expirés à J+7 |
| `ExpireDetentionProofs` | 15 min | Péremption des jetons de vente |
| `DemoteUnconsolidatedTheft` | quotidien | Retombée du drapeau de vol non consolidé (§9) |
| `AnchorAuditHead` | quotidien | Ancrage externe du hash de tête |
| `PurgeLookups` | quotidien | Purge au-delà de 12 mois (ARTCI) |
| `DetectLookupSpikes` | 15 min | Alertes de pic de consultations |

## 5. Modèle de données

### 5.1 Deltas par rapport au schéma v1.1

Le schéma de référence compte 14 tables. Le MVP en compte environ 28 : neuf tables métier ajoutées et cinq tables d'infrastructure Laravel imposées par les drivers `database`. Dix modifications, dont six corrigent des manques et quatre découlent des arbitrages.

| # | Modification | Motif |
|---|---|---|
| 1 | `asset_categories` + `category_fields` ; `assets.attributes` en JSON | D6. L'identifiant canonique n'est jamais stocké dans le JSON : il reste dans `identifier_normalized`, colonne réelle et indexée. Les autres champs dynamiques sur lesquels on doit filtrer reçoivent une colonne générée indexée, ajoutée au cas par cas |
| 2 | `admin_users`, `admin_roles`, `admin_role_page`, `admin_activity_log` | Le back-office est absent du schéma. Domaine d'authentification séparé (§4.2) |
| 3 | `otp_codes` | Absent, alors que l'anti-brute-force progressif de ST-0101 exige de la persistance et de l'audit |
| 4 | `users.email`, `users.email_verified_at`, nullables | D7. Ouvre le repli OTP par e-mail |
| 5 | `takeover_requests` | D2 (§6) |
| 6 | `detention_proofs` | D3 (§7) |
| 7 | `payments.buyer_phone_verified_at` | Le CHECK garantit la présence du numéro, pas sa vérification |
| 8 | Index `lookups (found_asset_id, created_at)` ; colonne `lookups.approx_city` | Sans le premier, le job d'agrégation horaire balaie la plus grosse table. La seconde remplace toute tentative de géolocaliser après coup (§10.2) |
| 9 | Index partiel : un seul transfert actif par bien ; plafond de réclamations ouvertes par bien | Deux transferts simultanés sont aujourd'hui possibles ; les réclamations non plafonnées exposent l'arbitrage humain à une saturation délibérée |
| 10 | Tables Laravel : `jobs`, `failed_jobs`, `cache`, `sessions`, `personal_access_tokens` | Imposées par les drivers `database` (§3) |

### 5.2 Catégories dynamiques

Une catégorie porte : une clé, un libellé, une icône (emoji), un ordre, un drapeau d'activation, et une collection de champs. Un champ porte : un libellé, un type, un caractère obligatoire ou non, et une règle de validation optionnelle.

Modèle repris fidèlement du prototype :

```
moto     🛵  actif      N° de châssis (requis) · Plaque · Marque & modèle (requis) · 4 photos (requis)
auto     🚗  actif      Plaque (requis) · N° VIN (requis) · Marque & modèle (requis) · 4 photos (requis)
tel      📱  actif      IMEI (requis) · Marque & modèle (requis) · 2 photos (requis)
terrain  📍  inactif    N° de lot (requis) · Commune (requis) · Superficie · ACD / attestation
```

**Contrainte de conception** : quels que soient les champs dynamiques, chaque catégorie désigne exactement un champ comme **identifiant canonique** (châssis, VIN, IMEI, n° de lot). Ce champ est extrait dans `assets.identifier_normalized`, colonne réelle et indexée. La règle d'unicité active ne dépend donc jamais du JSON.

### 5.3 Unicité active — inchangée

`UNIQUE (identifier_normalized, active_flag)` avec `active_flag ∈ {1, NULL}`. L'archivage et la création du nouvel actif se font dans une transaction unique avec verrou. Ce pattern est conservé tel quel.

## 6. Bascule au mieux-documenté

Le mécanisme corrige un déséquilibre du modèle initial : un squatteur enregistrait un identifiant à coût nul, et le propriétaire légitime devait payer entre 2 000 et 5 000 FCFA plus un KYC complet pour récupérer son propre bien.

### Déroulé

1. Un déclarant soumet un identifiant déjà pris. Aucune création n'a lieu — cette règle ne change pas.
2. Le système évalue le tenant actuel. **S'il est en F1 sans aucun justificatif**, une `takeover_request` **gratuite** s'ouvre au lieu du parcours de réclamation payant.
3. Le nouveau déclarant dépose un justificatif nominatif et complète son KYC.
4. **Dès que le justificatif est jugé recevable** — automatiquement s'il s'agit d'un document officiel nominatif ou d'un récépissé de plainte, sinon après revue manuelle sous 48 heures — le bien passe en `V-LIT` : gel public immédiat, la revendabilité meurt. Un justificatif jugé irrecevable clôt la demande sans jamais geler le bien, ce qui empêche d'utiliser la bascule comme outil de nuisance.
5. Le tenant est notifié et dispose du délai contradictoire.
6. **Un administrateur tranche**, avec motivation obligatoire, tracée dans la chaîne d'audit.
7. Si la bascule est validée : `active_flag = NULL` sur l'ancien enregistrement et création du nouvel actif, dans une transaction unique sous verrou. La chaîne des détenteurs est intégralement conservée.

Si le tenant est F2 ou F3, le parcours reste la réclamation complète avec frais et arbitrage — les frais ne se justifient que face à un adversaire lui-même documenté.

### Ce que ça change

Le squatteur perd son avantage économique. Un humain reste dans la boucle, donc aucun automatisme n'est exploitable.

## 7. Preuve de détention à la demande

Le registre atteste qu'un bien est enregistré par quelqu'un. Il n'atteste pas que ce quelqu'un est la personne qui tend les clés à l'acheteur. Sans ce mécanisme, un voleur peut présenter la fiche verte authentique du propriétaire qu'il a volé, et le verdict devient un instrument de blanchiment.

### Déroulé

1. L'acheteur consulte la fiche et demande : « le détenteur confirme-t-il qu'il vend ? »
2. Le détenteur reçoit une notification et confirme d'un geste, validé par OTP.
3. Un jeton de vente est émis, valable 30 minutes, affiché sur la fiche publique.
4. Aucune identité n'est échangée dans un sens ni dans l'autre : l'anonymat symétrique est intégralement préservé.
5. L'émission et la péremption sont journalisées dans la chaîne d'audit.

Un voleur ne peut pas produire ce jeton. C'est, à conception égale, la fonctionnalité qui protège le plus directement l'acheteur.

## 8. Certificat de consultation

Sur un registre jeune, un rapport détaillé décrit un bien à un seul détenteur, sans transfert ni incident : il vend du vide. Le certificat de consultation, lui, a de la valeur dès la première journée d'exploitation.

L'acheteur paie pour un document horodaté et hashé attestant : « le [date, heure], l'identifiant X était en statut Y ». C'est la pièce qui établit sa bonne foi en cas de poursuite pour recel. Le hash est ancré dans la chaîne d'audit, ce qui rend le document opposable.

Le rapport détaillé reste au catalogue et prendra de la valeur avec l'âge du registre. Le certificat est l'offre B2C qui tient au lancement.

## 9. Déclaration de vol — garde-fou

Afficher publiquement « Volé déclaré — ne pas acheter » sur la seule foi d'un geste OTP revient à publier une accusation à coût nul, avec un effet maximal sur la valeur du bien. C'est une exposition en dénonciation calomnieuse, et une arme utilisable dans un conflit privé.

Règles retenues :

- **Vol déclaré non consolidé** — affichage ambre, libellé explicite « déclaration du détenteur, non confirmée par un récépissé de plainte »
- **Vol consolidé par récépissé** — affichage rouge, libellé « vol confirmé »
- **Retombée automatique** : sans récépissé déposé sous 15 jours, la tâche `DemoteUnconsolidatedTheft` retire le drapeau et journalise l'événement
- Les conditions générales portent explicitement la responsabilité du déclarant

## 10. Sécurité et conformité

### 10.1 Règles métier absolues

Les huit règles de `CLAUDE.md` sont conservées sans amendement. Chacune fait l'objet d'un test automatisé qui prouve que sa violation est impossible (§12).

### 10.2 Écart relevé sur le prototype admin

L'écran de consultation du prototype affiche **« Adresse IP »** et **« Lieu approximatif »**. L'affichage d'une adresse IP viole la règle absolue n° 8 : l'IP n'existe que sous forme de hash salé quotidien, elle n'est donc pas restituable.

Correction retenue : le champ « Adresse IP » est **supprimé** de l'interface d'administration. Le « lieu approximatif » est conservé, mais dérivé au moment de la consultation et stocké dans `lookups.approx_city` — jamais recalculé depuis une IP conservée.

### 10.3 Anonymat dans le back-office

Le prototype affiche les détenteurs sous forme masquée (« K***é A. »). Ce comportement est retenu et généralisé : **l'administration voit des identités masquées par défaut**. La levée du masque est une action distincte, motivée et journalisée, réservée au rôle Superviseur, et n'a lieu que sur réquisition judiciaire.

### 10.4 Chantier réglementaire

Le traitement de données biométriques (liveness) et de pièces d'identité relève d'un régime d'autorisation préalable de l'ARTCI, dont l'instruction se compte en mois. **Ce chantier démarre en parallèle du lot 1**, et non au moment du lancement. C'est le seul risque du projet capable de retarder la mise en service sans qu'aucune ligne de code n'y change quoi que ce soit.

Point à trancher juridiquement et non résolu à ce jour : l'articulation entre le droit à la suppression (ST-0105), la chaîne d'audit append-only et l'unicité active. Que devient un bien dont le propriétaire supprime son compte ? La politique doit préciser ce qui est anonymisé, ce qui est conservé, et sur quelle base légale.

## 11. Dégradation et gestion des erreurs

L'hébergement mutualisé rend ces règles non négociables :

- **Panne SMS** → repli OTP par e-mail si l'utilisateur en a renseigné un ; sinon message explicite avec réessai différé
- **Panne Mindee** → la saisie manuelle est toujours disponible. L'OCR n'est jamais sur le chemin critique
- **Base saturée** → mode lecture seule : la consultation continue, les écritures sont refusées proprement. C'est l'inverse de la dégradation habituelle et c'est délibéré : le service public gratuit doit survivre à la panne des services payants
- **Jobs en échec** → `failed_jobs`, relance depuis l'écran Supervision
- **Verrou d'audit** → verrou court avec délai maximal explicite, pour ne jamais heurter la limite de temps d'exécution du mutualisé
- **Uploads en 3G** → file locale avec reprise côté application. Le bien est créé sans attendre les photos

## 12. Stratégie de test

Pest, avec Larastan au niveau maximal et Pint, obligatoires avant fusion.

Règle structurante : **chacune des huit règles métier absolues a un test dédié qui échoue si la règle peut être violée.** S'y ajoutent :

- Tests de concurrence sur l'unicité active : deux enregistrements simultanés du même identifiant, un seul doit aboutir
- Tests de continuité de la chaîne d'audit, y compris sous écriture concurrente
- Tests de la matrice de transitions : toute transition non autorisée lève une exception
- Tests d'idempotence des webhooks de paiement

Ce sont les deux premiers points où un défaut serait silencieux et irrattrapable.

## 13. Design system du back-office

Repris à l'identique du prototype Admin, appliqué à Filament v3 par un thème personnalisé.

### Palette

| Rôle | Valeur | Usage |
|---|---|---|
| Encre | `#2B1D12` | Texte principal, fonds inversés, badge Superviseur |
| Crème | `#FFF6E8` | Fond général |
| Crème claire | `#FAF6EE` / `#FDF3E3` | Fonds de cartes |
| Bordure | `#EFE9DC` / `#E3D5BC` | Séparateurs |
| Texte secondaire | `#8A7358` | Libellés, légendes |
| Accent | `#D97706` | Boutons primaires, éléments actifs |
| Accent foncé | `#C77700` | Badge Modérateur, état de prudence |
| Vert | `#1E8A4C` | Verdict sûr, service opérationnel |
| Rouge | `#C62F21` | Verdict de danger, service en panne |
| Bleu | `#1D4ED8` | Niveau Documenté, badge Analyste |
| Gris | `#5C6470` | Non vérifié, badge Support |
| Ambre clair | `#FFB84D` | Accent sur fond sombre |

### Typographie

- **Bricolage Grotesque** — titres, chiffres clés, verdicts. Graisses 800.
- **Atkinson Hyperlegible** — corps de texte, formulaires, tableaux. Choisie pour la lisibilité en basse littératie ; ce choix n'est pas négociable.

### Formes

Rayon de 16 px sur les cartes, 999 px sur les pastilles, 10 à 12 px sur les boutons et champs. Hauteur de cible tactile minimale : 44 px.

### Navigation — neuf pages

`Vue d'ensemble` · `Modération` · `Registre des biens` · `Catégories & champs` · `Utilisateurs` · `Statistiques app` · `Supervision` · `Piste d'audit` · `Équipe & rôles`

Profil administrateur en bas de la barre latérale.

### Rôles et habilitations

L'habilitation se fait **par page**, conformément au prototype :

| Rôle | Couleur | Pages accessibles |
|---|---|---|
| Superviseur | `#2B1D12` | Toutes |
| Modérateur | `#C77700` | Modération · Registre des biens |
| Analyste | `#1D4ED8` | Vue d'ensemble · Statistiques app · Supervision |
| Support | `#5C6470` | Utilisateurs |

### Authentification administrateur

E-mail professionnel et mot de passe, puis second facteur : application d'authentification (TOTP) ou code à six chiffres par e-mail. Toute action d'administration est tracée dans `admin_activity_log` et, pour les actions sensibles, dans la chaîne d'audit.

## 14. Configuration distante des catégories

Le prototype porte une promesse explicite : « Catégories et champs sont servis à l'app mobile par configuration distante : chaque publication est visible immédiatement, sans mise à jour du store. »

Conception retenue :

- L'administrateur édite les catégories et publie explicitement, par une action distincte de l'enregistrement
- Chaque publication incrémente un numéro de version de configuration
- L'application mobile récupère la configuration au démarrage et la met en cache localement, avec la version
- Le cache local est le repli hors ligne : l'application reste utilisable sans réseau, sur la dernière configuration connue
- La configuration publiée est servie depuis le cache CDN Cloudflare, ce qui la rend quasi gratuite en charge serveur

Corollaire : l'application mobile ne code jamais en dur un type de bien ni un champ.

## 15. Périmètre du lot 1 — socle

| Élément | Contenu |
|---|---|
| Projet | Laravel 11, PHP 8.3, structure applicative cible |
| Intégration continue | Pest, Larastan niveau max, Pint, sur GitHub Actions |
| Base | Les ~28 tables, conformes au schéma v1.1 amendé par le §5 |
| Déploiement | Hostinger par git via SSH, crons installés, document root positionné, `.env` hors docroot |
| Authentification | OTP complet : demande, vérification, anti-brute-force progressif, Sanctum, repli e-mail |
| Audit | `AuditChain::append()`, tests de continuité, tâche d'ancrage quotidien |
| Domaine | Énumérations, `StatusTransitionService` avec matrice verrouillée par tests |
| Catégories | `CategoryRegistry` et endpoint de configuration distante |
| Back-office | Socle Filament thémé, authentification à second facteur, rôles et habilitations par page |
| Démonstration | Seeders couvrant les six états de vie, page publique de statut minimale |

Le lot 1 ne contient ni KYC, ni paiement, ni réclamation, ni transfert, ni application Flutter.

## 16. Points ouverts

Ces points n'empêchent pas de démarrer le lot 1, mais doivent être tranchés avant les lots suivants :

| # | Point | Échéance |
|---|---|---|
| 1 | Plan Hostinger : SSH et cron à la minute confirmés ? | Avant le déploiement du lot 1 |
| 2 | Fournisseur SMS OTP : coût et fiabilité en Côte d'Ivoire | Avant le lot 2 |
| 3 | Dossier ARTCI pour le traitement biométrique | Démarrage immédiat, en parallèle |
| 4 | Politique de suppression de compte face à l'audit append-only | Avant le KYC |
| 5 | Marque « Preuve » : dépôt OAPI et domaine `preuve.ci` | Avant toute communication publique |
| 6 | Tarif du rapport détaillé et du certificat, paliers d'abonnement flotte | Avant le lot de monétisation |
| 7 | Modèle de coût variable par utilisateur (SMS, Mindee, frais de paiement) | Avant le lot de monétisation |
| 8 | Validation Mindee sur de vraies CNI ivoiriennes | Avant le lot KYC |

## 17. Risques assumés

| Risque | Nature | Atténuation retenue |
|---|---|---|
| Registre vide au lancement | Produit — la réponse dominante est « inconnu » pendant des mois | Fiche publique partageable par QR, pour que le vendeur honnête pousse la vérification ; veille sur identifiant inconnu ouverte aux consultants |
| Consultation sous la seconde sur mutualisé | Technique — processeur partagé | Cache CDN Cloudflare, index couvrant, mesure continue. À réévaluer si le P95 dérive |
| Point de sérialisation de la chaîne d'audit | Technique — verrou global sur la dernière ligne | Tenable au volume cible. Partitionnement ou chaînage par lot à prévoir au-delà de ~100 écritures par seconde |
| Arbitrage humain structurellement déficitaire | Économique | Plafond de réclamations par bien ; la bascule gratuite (§6) absorbe les cas simples sans instruction complète |
| Planning de 294 points sur 13 sprints | Projet — aucune marge, vélocité supposée plate | Livraison par lots avec démonstration à chaque fin de lot, plutôt qu'un engagement de date sur 26 semaines |
