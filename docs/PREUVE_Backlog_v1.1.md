# PREUVE — Backlog produit v1.1

**BookMi · Août 2026 · Méthodologie BMAD** — dérivé du PRD v1.0 et du schéma MySQL v1.1 (14 tables).

> **Conventions.** Points : suite de Fibonacci (1, 2, 3, 5, 8). Priorités MoSCoW. 13 sprints de 2 semaines en 4 phases : Fondations (S1-S3), Cœur produit (S4-S7), Protection (S8-S10), Marché (S11-S13). **Definition of Done transverse** : critères CT-01 à CT-06 du PRD respectés (consultation ≤ 2 interactions, enregistrement < 90 s médian, friction proportionnée au risque), tests automatisés, action sensible journalisée dans la chaîne d'audit, revue sécurité pour toute story touchant OTP/paiement/statut.

## Synthèse

**59 stories · 294 points · 10 epics · 13 sprints**

| Epic | Intitulé | Stories | Points |
|---|---|---:|---:|
| EP-01 | Socle & comptes | 7 | 37 |
| EP-02 | Enregistrement express | 8 | 44 |
| EP-03 | Consultation 2 clics | 6 | 28 |
| EP-04 | Confiance graduée | 5 | 20 |
| EP-05 | Réclamation & arbitrage | 8 | 41 |
| EP-06 | Transferts & cycle de vie | 6 | 23 |
| EP-07 | Offre B2B flotte | 5 | 27 |
| EP-08 | Monétisation | 6 | 33 |
| EP-09 | Back-office & observabilité | 4 | 23 |
| EP-10 | Notifications in-app | 4 | 18 |
| **Total** | | **59** | **294** |

**Répartition MoSCoW (points)** : Must 266 · Should 28.

**Charge par sprint (points)** : S1 = 16 · S2 = 13 · S3 = 8 · S4 = 26 · S5 = 26 · S6 = 25 · S7 = 25 · S8 = 21 · S9 = 25 · S10 = 26 · S11 = 27 · S12 = 28 · S13 = 28. Vélocité cible ≈ 23 pts/sprint.

## EP-01 — Socle & comptes (7 stories · 37 pts)

*Fondations d'authentification, KYC progressif et conformité — tout le reste en dépend.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0101 | En tant que visiteur, je veux créer un compte avec mon numéro de téléphone et un code OTP afin d'accéder aux fonctions d'enregistrement sans mot de passe. | M | 5 | S1 | OTP SMS 6 chiffres, expiration 5 min, 3 tentatives, anti-brute-force progressif |
| ST-0102 | En tant qu'utilisateur, je veux me reconnecter par OTP sur un nouvel appareil afin de retrouver mes biens. | M | 3 | S1 | Session Sanctum, déconnexion des autres appareils optionnelle |
| ST-0103 | En tant qu'utilisateur, je veux compléter mon KYC (CNI recto/verso + selfie liveness) afin de débloquer le niveau Documenté, les réclamations et les transferts. | M | 8 | S2 | OCR Mindee, liveness, statut pending/verified/rejected, n° CNI stocké hashé uniquement |
| ST-0104 | En tant qu'entreprise de location, je veux créer un compte société (RCCM + KYC du représentant) afin d'accéder à l'offre flotte. | M | 5 | S2 | Validation back-office obligatoire avant activation (statut pending) |
| ST-0105 | En tant qu'utilisateur, je veux consulter et exercer mes droits (accès, rectification, suppression) afin de respecter la Loi 2013-450. | M | 5 | S3 | Suppression = anonymisation avec conservation légale des journaux, export de mes données |
| ST-0106 | En tant que système, je veux journaliser toute action sensible dans la chaîne d'audit SHA-256 afin de garantir l'inaltérabilité. | M | 8 | S1 | Service AuditChain::append sous verrou, chain_hash unique, ancrage quotidien externe |
| ST-0107 | En tant qu'utilisateur, je veux gérer mes préférences de notification (in-app, push, SMS) afin de contrôler les alertes reçues. | S | 3 | S3 | Opt-out par type, SMS réservé aux événements critiques |

## EP-02 — Enregistrement express (8 stories · 44 pts)

*Le formulaire 4 gestes < 90 secondes — cœur de l'acquisition propriétaires.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0201 | En tant que propriétaire connecté, je veux enregistrer un bien en 4 gestes (type → identifiant → photos → envoi) afin de le protéger en moins de 90 secondes. | M | 8 | S4 | Une vue progressive, chrono télémétrie CT-02, création en F1/V-PRV, aucun KYC exigé |
| ST-0202 | En tant que propriétaire, je veux scanner ma carte grise ou ma facture afin de pré-remplir l'identifiant sans saisie. | M | 8 | S4 | OCR Mindee carte grise/facture, repli saisie manuelle, taux de pré-remplissage mesuré |
| ST-0203 | En tant que propriétaire, je veux scanner un code-barres/QR ou saisir l'IMEI assisté (*#06#) afin d'identifier un téléphone ou une moto. | M | 5 | S4 | Validation Luhn IMEI, checksum VIN, normalisation (majuscules, sans espaces/tirets) |
| ST-0204 | En tant que système, je veux contrôler l'unicité de l'identifiant en temps réel afin d'empêcher tout doublon actif. | M | 5 | S4 | Index unique (identifier_normalized, active_flag), collision → redirection fiche + parcours réclamation |
| ST-0205 | En tant que système, je veux alerter le détenteur actuel lors d'une tentative de doublon afin de détecter les fraudes tôt. | M | 3 | S5 | Notification duplicate_attempt immédiate, tentative journalisée |
| ST-0206 | En tant que propriétaire, je veux que mes photos partent en file différée afin d'enregistrer même en 3G instable. | M | 5 | S5 | Queue locale avec reprise, upload arrière-plan, bien créé sans attendre les uploads |
| ST-0207 | En tant que propriétaire, je veux renforcer mon enregistrement après coup (facture → F2, contrôle → F3) afin d'augmenter ma fiabilité sans friction initiale. | M | 5 | S5 | Jauge de score, bénéfices explicités, upload justificatifs depuis Mes biens |
| ST-0208 | En tant qu'agent, je veux revoir les justificatifs soumis afin de valider les passages F2/F3. | M | 5 | S5 | File de revue, statuts accepted/rejected/suspected_forgery, motif obligatoire au rejet |

## EP-03 — Consultation 2 clics (6 stories · 28 pts)

*Le moteur de vérité public — gratuit, anonyme, sans compte.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0301 | En tant qu'acheteur, je veux taper ou scanner un identifiant et obtenir le statut en 2 interactions afin de décider avant de payer. | M | 8 | S5 | Champ unique, détection auto du type, résultat < 1 s P95 en 3G, sans compte |
| ST-0302 | En tant qu'acheteur, je veux un verdict visuel plein écran (couleur + symbole + mot) afin de comprendre en 1 seconde. | M | 5 | S6 | 6 états couverts, fiabilité + signaux temporels affichés, identité jamais divulguée |
| ST-0303 | En tant qu'acheteur, je veux un message clair si l'identifiant est inconnu afin de ne pas mal interpréter l'absence. | M | 2 | S6 | Ni bon ni mauvais signe + CTA Enregistrer ce bien |
| ST-0304 | En tant que système, je veux journaliser chaque consultation (IP hashée salée) afin d'alimenter signaux et limites sans donnée personnelle. | M | 3 | S6 | Table lookups, purge > 12 mois, jamais d'IP en clair |
| ST-0305 | En tant que système, je veux limiter à 10 consultations/heure par IP anonyme avec CAPTCHA au-delà afin d'empêcher le profilage de masse. | M | 5 | S6 | Rate limiting Redis, CAPTCHA, comptes authentifiés non limités |
| ST-0306 | En tant que visiteur web, je veux des pages de statut publiques indexables afin de trouver un bien via Google. | S | 5 | S6 | URL par public_ref opaque, SEO, aucune donnée personnelle |

## EP-04 — Confiance graduée (5 stories · 20 pts)

*Le mécanisme anti-squat : niveaux F1-F3, signaux temporels, fenêtre de contestation.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0401 | En tant que système, je veux calculer le niveau F1/F2/F3 selon des règles versionnées afin d'afficher une fiabilité cohérente. | M | 5 | S6 | Moteur de règles, recalcul sur événement, historique des changements de niveau |
| ST-0402 | En tant que système, je veux maintenir le statut provisoire pendant 30 jours (V-PRV) afin d'ouvrir la fenêtre de contestation. | M | 3 | S7 | provisional_until, bascule automatique V-ACT à J+30 sans réclamation recevable |
| ST-0403 | En tant que propriétaire, je veux activer une veille sur mes identifiants afin d'être alerté de toute tentative ou pic de consultations. | M | 5 | S7 | watch_alerts, canaux push/SMS, alerte lookup_spike sur seuil |
| ST-0404 | En tant qu'acheteur, je veux voir l'ancienneté de l'enregistrement et du compte déclarant afin de jauger la confiance. | M | 2 | S7 | Signaux non antidatables affichés sur le verdict |
| ST-0405 | En tant que système, je veux détecter les pics anormaux de consultations sur un bien afin de signaler une revente potentielle en cours. | S | 5 | S7 | Seuil paramétrable, notification lookup_spike, tableau back-office |

## EP-05 — Réclamation & arbitrage (8 stories · 41 pts)

*La riposte du vrai propriétaire : gel public et contradictoire outillé.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0501 | En tant que victime, je veux déposer une réclamation guidée avec mes preuves afin de contester un enregistrement frauduleux. | M | 8 | S8 | KYC complet requis, upload preuves typées, frais 2000/5000 FCFA, brouillon reprenable |
| ST-0502 | En tant que système, je veux vérifier la recevabilité (auto ou manuelle 48 h) afin de filtrer sans bloquer les victimes. | M | 5 | S8 | ≥ 1 justificatif nominatif OU récépissé de plainte, sinon revue manuelle |
| ST-0503 | En tant que système, je veux geler le bien en Litige en cours dès recevabilité afin de tuer sa revendabilité. | M | 3 | S8 | Transition V-LIT journalisée, verdict public mis à jour immédiatement |
| ST-0504 | En tant qu'enregistrant mis en cause, je veux être notifié et produire mes justificatifs sous 15 jours afin d'exercer le contradictoire. | M | 5 | S8 | respondent_deadline, relances J+7 et J+13, silence = instruction sur pièces |
| ST-0505 | En tant qu'agent, je veux instruire avec les dossiers côte à côte et la grille de pondération assistée afin de décider de façon motivée. | M | 8 | S9 | Grille 40/25/15/10/5/5, seuil écart 20 points, décision motivée obligatoire, 3 issues |
| ST-0506 | En tant que partie, je veux recevoir la décision motivée et l'export PDF horodaté afin de poursuivre en justice si non tranché. | M | 5 | S9 | Export hashé (export_sha256), remise aux deux parties |
| ST-0507 | En tant que partie, je veux faire appel une fois auprès d'un second agent afin de contester la décision. | S | 5 | S9 | Fenêtre 15 jours, agent senior différent, décision finale |
| ST-0508 | En tant que victime gagnante, je veux le remboursement automatique de mes frais de dossier afin de ne pas payer pour me défendre. | M | 2 | S9 | Refund via provider d'origine, fee_refunded tracé |

## EP-06 — Transferts & cycle de vie (6 stories · 23 pts)

*Vendre, déclarer un vol, clore — sans jamais casser la chaîne des détenteurs.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0601 | En tant que vendeur, je veux initier un transfert vers le téléphone de l'acheteur afin de céder mon bien proprement. | M | 5 | S9 | Statut V-VTE, invitation SMS, expiration J+7, annulation possible avant confirmation |
| ST-0602 | En tant qu'acheteur, je veux vérifier le bien puis confirmer avec mon OTP (et le vendeur le sien) afin de finaliser en double validation. | M | 8 | S10 | Double OTP, lockForUpdate, archivage active_flag=NULL + nouvel actif en une transaction |
| ST-0603 | En tant que système, je veux conserver la chaîne complète des détenteurs afin d'alimenter le rapport détaillé (anonymisé). | M | 3 | S10 | Historique interne intégral, exposition anonymisée (nombre + dates uniquement) |
| ST-0604 | En tant que propriétaire, je veux déclarer un vol en un geste (OTP) afin de rendre mon bien invendable immédiatement. | M | 3 | S10 | V-VOL immédiat public, mention non consolidée sans récépissé sous 15 jours |
| ST-0605 | En tant que propriétaire, je veux lever une déclaration de vol (OTP, journalisé) afin de corriger une erreur ou un bien retrouvé. | M | 2 | S10 | Levée par le même détenteur uniquement, trace publique dans le rapport détaillé |
| ST-0606 | En tant que propriétaire, je veux déclarer un bien hors d'usage (épave, destruction) afin de clore son cycle de vie. | S | 2 | S10 | V-FDV, identifiant libéré uniquement sur décision back-office |

## EP-07 — Offre B2B flotte (5 stories · 27 pts)

*Les loueurs : la verticale d'amorçage du marché.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0701 | En tant que loueur, je veux importer ma flotte par fichier Excel/CSV afin de couvrir 100 % de mes véhicules en < 30 minutes. | M | 8 | S11 | Modèle fourni, validation ligne à ligne, rapport d'erreurs téléchargeable, reprise partielle |
| ST-0702 | En tant que loueur, je veux marquer/démarquer En location en masse afin de refléter l'état réel de ma flotte. | M | 3 | S11 | Sélection multiple, V-LOC avec avertissement public fort |
| ST-0703 | En tant que loueur, je veux un tableau de bord (flotte, statuts, consultations, alertes) afin de piloter ma protection. | M | 8 | S11 | Compteurs par statut, consultations par véhicule, fil d'alertes |
| ST-0704 | En tant que loueur, je veux recevoir une alerte immédiate si un de mes véhicules fait l'objet d'une tentative d'enregistrement ou d'un pic de consultations afin d'agir avant la vente frauduleuse. | M | 3 | S11 | Notification duplicate_attempt/lookup_spike prioritaire, SMS inclus |
| ST-0705 | En tant que loueur, je veux gérer les accès de mes collaborateurs afin de déléguer sans partager mon compte. | S | 5 | S12 | Rôles admin/opérateur, actions tracées par acteur |

## EP-08 — Monétisation (6 stories · 33 pts)

*Payer pour le détail, jamais pour le statut.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0801 | En tant qu'acheteur, je veux acheter un rapport détaillé (500-1000 FCFA) afin de voir l'historique complet du bien. | M | 8 | S12 | Wave/OM/MoMo (PawaPay) + Paystack, accès par jeton 30 jours, contenu anonymisé |
| ST-0802 | En tant qu'acheteur non inscrit, je veux payer un rapport en fournissant nom, email et téléphone vérifié par OTP afin d'accéder sans créer de compte complet. | M | 5 | S12 | CHECK identité en base, OTP avant paiement, proposition de conversion en compte après achat |
| ST-0803 | En tant que propriétaire, je veux être notifié qu'un rapport a été acheté sur mon bien (sans identité de l'acheteur) afin de savoir qu'une transaction se prépare. | M | 2 | S12 | Notification asset_report_purchased, anonymat strict et symétrique |
| ST-0804 | En tant que particulier, je veux 3 biens gratuits puis un micro-paiement par bien afin de protéger toute ma famille à coût maîtrisé. | M | 5 | S11 | Quota, upsell non bloquant, 500 FCFA/bien/an (paramétrable) |
| ST-0805 | En tant que loueur, je veux 3 véhicules gratuits puis un abonnement mensuel par véhicule afin de payer proportionnellement à ma flotte. | M | 8 | S12 | Paliers de flotte, factures PDF conformes, relances, suspension douce en lecture seule |
| ST-0806 | En tant que système, je veux réconcilier les webhooks de paiement de façon idempotente afin de ne jamais perdre ni dupliquer une transaction. | M | 5 | S13 | uq (provider, provider_ref), retry sûrs, file dédiée Horizon |

## EP-09 — Back-office & observabilité (4 stories · 23 pts)

*Administrer, arbitrer, mesurer — et prouver les promesses produit.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-0901 | En tant qu'admin, je veux gérer comptes, biens et validations d'entreprises afin d'opérer la plateforme au quotidien. | M | 8 | S10 | Recherche, fiches, actions tracées dans l'audit, validation comptes loueurs |
| ST-0902 | En tant qu'admin, je veux un tableau de bord anti-fraude (doublons tentés, pics, comptes suspects) afin de prioriser les contrôles. | M | 5 | S13 | Signaux agrégés, drill-down vers les fiches |
| ST-0903 | En tant que produit, je veux mesurer temps médian d'enregistrement, taux ≤ 2 interactions et conversion rapports afin de vérifier CT-01/CT-02 en continu. | M | 5 | S13 | Télémétrie anonymisée, tableaux hebdomadaires, alertes de régression |
| ST-0904 | En tant qu'ops, je veux supervision, sauvegardes testées et restauration documentée afin de tenir 99,5 % de disponibilité. | M | 5 | S13 | Monitoring, backups chiffrés, PRA testé, dégradation gracieuse lecture seule |

## EP-10 — Notifications in-app (4 stories · 18 pts)

*Le canal qui transforme chaque consultation en réassurance propriétaire.*

| ID | User story | Prio | Pts | Sprint | Critères d'acceptation clés |
|---|---|:---:|---:|:---:|---|
| ST-1001 | En tant qu'utilisateur, je veux un centre de notifications in-app avec badge non-lus afin de suivre l'activité sur mes biens. | M | 5 | S7 | Fil chronologique, read_at, index (user_id, read_at) |
| ST-1002 | En tant que propriétaire, je veux être notifié des consultations de mon bien de façon agrégée et anonyme afin de sentir la protection active sans être spammé. | M | 5 | S7 | Job horaire d'agrégation des lookups, jamais d'identité/IP, libellé avec compteur |
| ST-1003 | En tant qu'utilisateur, je veux recevoir des notifications push (FCM) en plus de l'in-app afin d'être alerté en temps réel des événements critiques. | M | 5 | S13 | duplicate_attempt et claim en push immédiat, respect des préférences ST-0107 |
| ST-1004 | En tant que système, je veux router les événements critiques en SMS de secours afin de joindre les propriétaires sans smartphone actif. | S | 3 | S13 | SMS pour vol/litige/tentative de doublon uniquement, coût tracé |

## Jalons de livraison

- **Fin S3 — Fondations** : comptes OTP, KYC Mindee, chaîne d'audit opérationnelle.
- **Fin S7 — Alpha interne** : enregistrement < 90 s, consultation 2 clics, confiance graduée, notifications in-app. Démo aux 5 loueurs pilotes.
- **Fin S10 — Bêta fermée** : réclamations avec gel Litige, transferts double OTP, déclaration de vol. Ouverture aux pilotes.
- **Fin S13 — Lancement public** : offre flotte B2B, paiements (rapport détaillé + abonnements), observabilité et push. Go-to-market loueurs Abidjan.

## Hors périmètre MVP (rappel)

Verticale foncier (phase 2, partenariat IDUFCI/MCLU), rapprochements institutionnels automatisés (F3+), API B2B assureurs/banques, extension UEMOA.
