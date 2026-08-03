# Runbook de déploiement — Hostinger mutualisé

> Cible : `preuve.click`. Backend Laravel 12 + API d'administration.
> Environnement relevé dans `docs/infrastructure/hostinger-audit.md` (01/08/2026).
> **Aucun identifiant ne figure dans ce fichier** : le dépôt est public.

## Ce qui est déployé, et ce qui ne l'est pas

| Composant | Déployé ici |
|---|---|
| API REST (consultation, enregistrement, transferts, réclamations…) | ✅ |
| API d'administration (`/api/v1/admin/*`, 16 endpoints) | ✅ |
| Tâches planifiées (11 commandes) | ✅ |
| **Interface web d'administration** | ❌ **elle n'existe pas** — l'espace administrateur est une API |
| Application mobile Flutter | ❌ hors dépôt |
| Front public de consultation | ❌ hors dépôt |

Il n'y a **rien à déployer séparément** pour l'espace administrateur : c'est la
même application Laravel. Une interface cliquable reste à construire.

---

## Avant de commencer — quatre actions hPanel

Aucune n'est faisable en SSH. Elles bloquent le déploiement.

| # | Action | Où | Pourquoi |
|---|---|---|---|
| 1 | Créer la base MariaDB **et un utilisateur dédié** | Bases de données | Le compte héberge 9 autres projets ; PREUVE ne doit avoir de droits que sur la sienne |
| 2 | Faire pointer le document root sur le sous-dossier `public` | Gestionnaire de domaines | Sinon `.env`, `storage/` et le code sont servis en clair sur le Web |
| 3 | Créer une tâche cron **à la minute** et vérifier qu'elle tourne bien chaque minute | Tâches Cron | `crontab` n'existe pas en CLI. Si la granularité minimale est de 5 min, les tâches horaires dérivent — à consigner comme dette |
| 4 | Créer la boîte `no-reply@preuve.click` | Emails | Identifiants SMTP ; ce ne sont pas ceux du compte d'hébergement |

L'action 2 est la plus dangereuse à manquer : un document root sur la racine du
projet expose `.env`, donc `APP_KEY`, donc **tous les secrets et toutes les
sauvegardes**.

---

## 1. Code

```sh
cd ~/domains/preuve.click
git clone <dépôt> app && cd app
composer install --no-dev --optimize-autoloader
```

Le document root du domaine doit pointer sur `~/domains/preuve.click/app/public`.

---

## 2. `.env` — et la seule irréversibilité de ce runbook

```sh
cp .env.example .env
php artisan key:generate
```

> ### ⚠️ `APP_KEY` se génère UNE FOIS, et jamais plus
>
> Elle chiffre les clés d'API des fournisseurs (SMS, Mindee), **les sauvegardes
> de la base et toutes les pièces sauvegardées**. La régénérer ne « casse » rien
> de visible : l'application redémarre, les écrans fonctionnent — et l'ensemble
> des sauvegardes antérieures devient définitivement indéchiffrable, sans qu'un
> seul message ne le signale. C'est le défaut silencieux le plus coûteux de
> l'installation.
>
> **Sauvegardez `APP_KEY` hors de la machine, dès sa génération.**
> `preuve:restore-drill` la contrôle à chaque exercice : une archive
> indéchiffrable y sort en échec explicite.

Valeurs à renseigner :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://preuve.click

DB_CONNECTION=mariadb          # PAS `mysql` : la cible est MariaDB 11.8
DB_DATABASE=…                  # créés à l'action hPanel n° 1
DB_USERNAME=…
DB_PASSWORD=…

# Aucun serveur Redis sur ce compte, malgré l'extension PHP présente.
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=no-reply@preuve.click
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS="no-reply@preuve.click"

# Stockage des pièces. R2 n'est pas encore en place (décision du 03/08/2026) :
# le disque local est utilisé en attendant, et les pièces y sont CHIFFRÉES AU
# REPOS avec APP_KEY. Une lecture de fichier depuis un des neuf sites voisins
# rend alors du chiffré, et non des cartes grises. Ce n'est pas l'équivalent
# d'un bucket privé — cela limite les dégâts, cela ne les évite pas.
PREUVE_DOCUMENTS_DISK=local
PREUVE_DOCUMENTS_ENCRYPT=true  # ne pas désactiver sur un disque partagé

# Le disque de sauvegarde doit être AILLEURS que la machine sauvegardée.
PREUVE_BACKUP_DISK=…
PREUVE_MYSQLDUMP_PATH=mysqldump

# Le disque de travail des envois différés doit rester local : on y ajoute des
# octets en place, ce qu'un stockage objet ne permet pas.
PREUVE_UPLOADS_STAGING_DISK=local
```

---

## 3. Base de données

```sh
php artisan migrate --force
```

Puis **appliquer `deploy/privileges-audit-log.md`**. Ce n'est pas optionnel :
c'est la seconde barrière d'inaltérabilité du journal, celle qui tient même si
les déclencheurs sont supprimés.

Les privilèges MariaDB étant **additifs**, l'utilisateur applicatif ne peut pas
recevoir `GRANT ALL` puis se voir retirer `audit_log` : il faut deux comptes
distincts, l'un pour les migrations, l'autre pour l'application. La procédure
complète est dans ce document.

Vérification :

```sh
php artisan tinker --execute="DB::statement(\"UPDATE audit_log SET action='x' LIMIT 1\");"
# DOIT échouer.
```

---

## 4. Amorçage — l'ordre compte

> ### Le verrou de démarrage
>
> Sur une installation neuve, **personne ne peut se connecter** :
> se connecter exige un code OTP · l'envoi d'un OTP exige un fournisseur SMS ·
> configurer le fournisseur exige un administrateur · être administrateur exige
> un compte. Le `LogOtpSender` par défaut **refuse de s'exécuter en
> production** — délibérément : il écrirait des codes d'accès en clair dans un
> fichier de log.
>
> Il faut donc ouvrir la boucle par la ligne de commande, une seule fois.

**4.1 — Configurer le fournisseur SMS hors interface**

Écrire ce fragment dans un fichier **hors du dépôt** (il porte un secret), puis :

```php
<?php // ~/amorcage.php — à supprimer après usage
use App\Services\Otp\ConfigurableOtpSender as Otp;
use App\Services\Settings\SettingsRepository;

$s = app(SettingsRepository::class);
$s->set(Otp::PROVIDER_KEY, 'http');
$s->set(Otp::CONFIG_KEY, [
    'endpoint_url'     => 'https://…',
    'http_method'      => 'POST',
    'payload_template' => '{"to":"{destination}","text":"{message}"}',
    'message_template' => 'Votre code PREUVE : {code}',
]);
// setSecret, et non set : la clé est chiffrée au repos avec APP_KEY.
$s->setSecret(Otp::CONFIG_KEY.'.auth_header', 'Bearer …');
```

```sh
php artisan tinker --execute="require '$HOME/amorcage.php';"
rm ~/amorcage.php
```

Séquence éprouvée le 02/08/2026 sur l'environnement de développement : le
sélecteur résout bien `ConfigurableOtpSender` et relit l'en-tête chiffré.

**4.2 — Créer le compte par l'API**, comme n'importe quel utilisateur :

```sh
curl -X POST https://preuve.click/api/v1/auth/otp/request -d 'phone=+225XXXXXXXXXX'
curl -X POST https://preuve.click/api/v1/auth/otp/verify  -d 'phone=+225XXXXXXXXXX&code=XXXXXX'
```

**4.3 — Le promouvoir :**

```sh
php artisan preuve:role +225XXXXXXXXXX admin
```

Le rôle n'est **jamais** assignable par une requête HTTP : `role` est absent du
`$fillable` de `User`, précisément pour qu'aucune élévation de privilège ne
puisse passer par une assignation de masse.

**4.4 — Régler depuis l'espace administrateur** ce qui peut désormais l'être :
destinataire d'exploitation, fournisseur Mindee, transport push, canaux
d'ancrage.

---

## 5. Tâches planifiées

Une seule entrée cron, à la minute (action hPanel n° 3) :

```
* * * * * cd ~/domains/preuve.click/app && php artisan schedule:run >> /dev/null 2>&1
```

Les 11 tâches sont ensuite pilotées par Laravel, à minutes décalées — elles se
disputeraient sinon le verrou nommé de la chaîne d'audit, dont le plafond mesuré
est d'une quinzaine d'actions simultanées.

Contrôle : `php artisan schedule:list` doit afficher 11 lignes `preuve:`.

---

## 6. Mise en cache

```sh
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

À **refaire après chaque modification de `.env`** : une configuration en cache
ignore les changements du fichier, et l'écart ne se voit pas.

---

## 7. Vérifications — ne rien déclarer sans preuve

Aucune de ces commandes ne se contente d'interroger la configuration.

```sh
# La sonde répond-elle ? (sans authentification, délibérément)
curl -s https://preuve.click/api/v1/health

# La consultation publique fonctionne-t-elle SANS compte ?
curl -s https://preuve.click/api/v1/lookup/1M8GDM9AXKP042788

# La passerelle de messagerie envoie-t-elle pour de bon ?
php artisan preuve:check-mail

# La chaîne d'audit est-elle opposable ? (faux tant qu'aucun ancrage n'a abouti)
php artisan preuve:anchor-audit-head
php artisan preuve:verify-audit-chain

# Une première sauvegarde, et son contrôle.
php artisan preuve:backup
php artisan preuve:backup-documents
```

### Ce que `.env` seul ne prouve pas

| À vérifier | Comment | Pourquoi ça ne se voit pas autrement |
|---|---|---|
| `.env` non servi sur le Web | `curl -s https://preuve.click/.env` → doit rendre 404 | Un document root mal placé n'a aucun symptôme applicatif |
| Pièces chiffrées sur le disque | déposer un justificatif, puis `grep` son contenu dans `storage/app/` → rien | Une pièce en clair se lit et s'affiche normalement : le défaut ne se voit que depuis le disque |
| `APP_DEBUG=false` | une URL inexistante ne doit pas afficher de trace | Une trace expose les identifiants de base |
| Pièces sur R2, pas en local | `php artisan preuve:reconcile-documents` | Un `PREUVE_DOCUMENTS_DISK` erroné écrit sur le disque partagé |
| Cron réellement à la minute | attendre l'heure suivante, contrôler qu'une tâche horaire a tourné | Une granularité de 5 min ne produit aucune erreur |

---

## 7 bis. Constats du déploiement du 03/08/2026

Déploiement réel mené sur `preuve.click`. Trois écarts constatés, qui ne se
voyaient pas depuis SSH.

### PHP web ≠ PHP CLI

Le sélecteur du compte annonce 8.4 et le CLI l'applique (8.4.19), mais le
serveur web servait une version plus ancienne au domaine : les dépendances
exigent `>= 8.4.1` et l'application rendait un **500 au premier appel**.

Corrigé **dans le dépôt** (`public/.htaccess`) et non sur le serveur : posée à
la main, la directive serait effacée au prochain `git reset --hard`, et le site
casserait en silence.

```apache
<IfModule mod_mime.c>
    AddHandler application/x-httpd-php84 .php
</IfModule>
```

### `sendmail` désactivé par l'hébergeur

```
503 550 Local sendmail disabled for u726808002 contact support
```

**Bloquant** : l'OTP passe par courriel (D7), donc personne ne peut se
connecter. Il faut une boîte SMTP réelle (hPanel → Emails) ou un fournisseur
externe. `preuve:check-mail` a refusé de conclure — c'est son rôle.

### Le CDN Hostinger met en cache

`x-hcdn-cache-status: HIT` sur `/api/v1/config/categories`, avec un `age` de
plusieurs minutes. C'est **voulu** pour le catalogue (`max-age=300`, ETag), mais
il faut le savoir : une republication n'est visible qu'après expiration.

La consultation, elle, ressort en `DYNAMIC` — non mise en cache au bord. Son
`max-age=60` applicatif est délibéré et documenté dans `LookupController` : au
delà d'une minute, une déclaration de vol mettrait trop de temps à devenir
visible.

### Ce qui a été vérifié en production

| Contrôle | Résultat |
|---|---|
| `GET /api/v1/health` | 200, base `ok` |
| Consultation sans compte | 200 |
| `POST /assets` sans jeton | 401 |
| Espace admin sans jeton | 401 |
| Espace admin avec jeton non-admin | 403 |
| `GET /.env` | 403 |
| Trace d'exception sur route inconnue | aucune |
| `UPDATE` sur `audit_log` | **refusé par déclencheur** |
| `DELETE` sur `audit_log` | **refusé par déclencheur** |
| Sanctum, bout en bout | 200 |

Registre laissé vierge : 0 bien, 0 utilisateur, 0 jeton. Une entrée d'audit
subsiste — la sonde d'inaltérabilité — et c'est normal : elle est inaltérable.

## 8. Ce que ce runbook ne couvre pas

- **`preuve:restore-drill` ne tournera pas** avec l'utilisateur applicatif : il
  exige `CREATE DATABASE`, un droit que ce compte ne doit pas avoir. L'exercice
  se mène avec des identifiants d'exploitation
  (`PREUVE_RESTORE_DB_USERNAME`/`_PASSWORD`) ou sur une base préparée à l'avance
  (`--database=`). **Il n'a jamais été mené sur cet hébergement.**
- **CT-01 (< 1 s au 95e centile) n'a pas été mesuré ici.** La mesure de
  `preuve:benchmark-lookup` a été faite sur poste de développement, sans charge
  concurrente ni latence 3G. À reprendre : `php artisan preuve:benchmark-lookup`.
- **Aucun ancrage externe n'a jamais abouti** sur cette installation. Tant que
  c'est le cas, la chaîne d'audit **n'est pas opposable** : sa cohérence interne
  ne prouve rien, l'algorithme étant public et reproductible par quiconque peut
  écrire en base.
- **Le voisinage reste un point faible.** Neuf autres sites partagent le compte ;
  une compromission de l'un d'eux expose les fichiers de PREUVE. C'est
  l'argument qui impose R2 pour les pièces, et un stockage de sauvegarde hors du
  compte.

---

## 9. Retour arrière

```sh
git -C ~/domains/preuve.click/app checkout <commit-précédent>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
```

**Les migrations ne se rejouent pas à l'envers.** Aucune table d'audit ni
d'historique n'a de `down()` destructif — c'est délibéré, et cela signifie qu'un
retour arrière du code ne défait pas un changement de schéma. En cas de
migration problématique, la voie est la restauration
(`docs/infrastructure/exploitation.md`), pas le `migrate:rollback`.

Pendant l'opération, **le mode lecture seule laisse la consultation ouverte** :

```http
PUT /api/v1/admin/platform-state  {"read_only": true, "reason": "Déploiement"}
```
