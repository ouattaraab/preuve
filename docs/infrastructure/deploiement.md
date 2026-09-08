# Déploiement en production

> Cible : `https://preuve.click`, mutualisé Hostinger (CloudLinux, LiteSpeed).
> **Les identifiants ne figurent pas dans ce dépôt** — il est public.

Ce document existe parce que quatre défauts de production n'ont été trouvés
qu'en regardant les réponses du serveur, jamais en lisant le code. Chacun a une
section « Pièges », et chacun a coûté une demi-heure de recherche à l'endroit où
personne ne cherchait.

## Disposition sur le serveur

| Élément | Chemin |
|---|---|
| Dépôt applicatif | `~/domains/preuve.click/app` |
| Racine servie | `~/domains/preuve.click/public_html` → **lien symbolique** vers `app/public` |
| Sauvegardes de la base | `app/storage/app/private/backups` (chiffrées, 30 conservées) |
| Journaux | `app/storage/logs` |

La racine servie étant un lien, **le `public/` du dépôt EST le dossier web** : ce
qui y est commité est servi tel quel, sans étape de copie.

## Déployer

```bash
cd ~/domains/preuve.click/app
git fetch origin
git reset --hard origin/<branche>          # jamais `git pull` : le serveur n'a rien à fusionner
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`reset --hard` et non `pull` : le serveur n'est pas un poste de travail, il n'a
aucune modification locale à préserver. Une divergence y serait un accident, pas
une intention.

Les trois caches sont **obligatoires** : sans `route:cache`, une nouvelle route
n'apparaît pas ; sans `view:cache`, un gabarit modifié continue d'être servi
depuis l'ancienne compilation.

## Vérifier après chaque déploiement

```bash
# Consultation publique : gratuite, anonyme, et sous la seconde (CT-01)
curl -s -o /dev/null -w '%{http_code} %{time_starttransfer}s\n' https://preuve.click/api/v1/lookup/1M8GDM9AXKP042788

# Écriture sans jeton : doit être refusée
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Accept: application/json' https://preuve.click/api/v1/assets   # 401

# Console : redirige vers la connexion, ne rend jamais un 403 du serveur
curl -s -o /dev/null -w '%{http_code} → %{redirect_url}\n' https://preuve.click/admin

# En-têtes de sécurité : la politique complète doit apparaître, pas celle de l'hébergeur
curl -s -D - -o /dev/null https://preuve.click/ | grep -i 'content-security\|x-powered'

# Le secret ne fuit pas
curl -s -o /dev/null -w '%{http_code}\n' https://preuve.click/.env     # 403
```

Attendu : `200` sur la consultation, `401` sur l'écriture, `302` vers
`/admin/connexion`, une politique de sécurité **longue** (pas
`upgrade-insecure-requests` seul), **aucun** `X-Powered-By`, `403` sur `.env`.

## Pièges de cet hébergement

### 1. La version de PHP du serveur web n'est pas celle du CLI

Le sélecteur du compte annonce 8.4 et le CLI l'applique ; le serveur web servait
une version plus ancienne, et l'application rendait **500 au premier appel** sans
que rien ne le laisse prévoir depuis SSH.

`public/.htaccess` force le gestionnaire :

```apache
AddHandler application/x-httpd-php84 .php
```

**Cette ligne doit vivre dans le dépôt.** Posée à la main sur le serveur, le
déploiement suivant l'effacerait et casserait le site en silence.

### 2. Un dossier de `public/` masque le préfixe de route du même nom

`public/admin/` portait les ressources de la console. LiteSpeed y résolvait
`/admin/` — dossier réel, sans index et sans listage autorisé — et rendait un
**403 du serveur** avant que Laravel ne voie la requête. `/admin/connexion`
fonctionnait : seule la racine était murée, ce qui ne se voyait que pour qui
tapait l'adresse à la main.

Les ressources vivent sous `/console/`. **Règle : un préfixe de route et un
dossier de la racine servie ne doivent jamais porter le même nom.** Un test
(`ConsoleAdministrationTest`) regarde le disque et refuse toute nouvelle
collision — aucun test d'intégration ne pourrait l'attraper, le serveur de test
ne servant pas de fichiers statiques.

### 3. LiteSpeed réécrit deux en-têtes après le passage de PHP

`Content-Security-Policy` est **remplacée** par celle de l'hébergeur, et
`X-Powered-By` est **réinjecté** avec la version exacte de PHP. Tous les autres
en-têtes posés par l'application arrivaient intacts.

`mod_headers` s'exécute après et a le dernier mot. `public/.htaccess` rétablit la
politique depuis `X-Preuve-CSP`, posé par le middleware, plutôt que de l'écrire
en dur : elle varie d'une réponse à l'autre — l'origine du défi anti-automate
n'est ouverte que sur la page qui l'affiche.

Si la politique servie se réduit un jour à `upgrade-insecure-requests`, c'est
que le bloc `<IfModule mod_headers.c>` a disparu.

### 4. Le planificateur peut être invoqué plusieurs fois

Constaté : deux sauvegardes à `01:30:07` et `01:30:10`, deux ancrages à
`02:40:06` et `02:40:09`. `withoutOverlapping()` n'y peut rien — il empêche un
*chevauchement*, or la première exécution était finie avant que la seconde ne
commence.

Toutes les tâches portent `onOneServer()` (verrou par tâche **et** par minute).
La cause reste en amont : **vérifier qu'une seule tâche cron appelle
`schedule:run`** dans hPanel → Avancé → Tâches Cron.

Le cron s'écrit avec le **chemin absolu vers `artisan`**, sans `cd` :

```
/usr/bin/php /home/<compte>/domains/preuve.click/app/artisan schedule:run
```

`crontab` n'existe pas en ligne de commande sur ce compte : hPanel est le seul
chemin.

## Ce qu'il ne faut jamais faire

| Interdit | Conséquence |
|---|---|
| Régénérer `APP_KEY` | **Toutes les sauvegardes et toutes les pièces stockées deviennent définitivement indéchiffrables.** La clé doit être archivée hors machine. |
| `php artisan migrate:fresh` ou `migrate:rollback` en production | Les tables d'audit et d'historique n'ont pas de `down()` destructif, mais le reste du registre partirait. |
| Modifier `audit_log` ou `identity_disclosures` | Des déclencheurs MariaDB refusent tout `UPDATE`/`DELETE`. Une tentative échoue — et c'est voulu. |
| Restaurer une sauvegarde produite par un autre compte MySQL sans retirer les `DEFINER` | `ERROR 1227 … SET USER`. `preuve:backup` les retire déjà. |
| Réactiver le CDN Hostinger sur le domaine | Mesuré depuis Abidjan : **2,55 s** au premier octet pour 2 ms de traitement, avec des échecs intermittents. Sans CDN : 536 ms en médiane. |

## Revenir en arrière

```bash
cd ~/domains/preuve.click/app
git reset --hard <sha-précédent>
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Les migrations ne se rejouent pas à l'envers.** Si le déploiement fautif en
comportait une, revenir au code seul laisse le schéma en avance — état
généralement viable, mais à vérifier avant de conclure. Une migration qui
supprimerait des données n'est jamais acceptable sur ce projet : c'est ce qui
rend ce retour en arrière possible.

## Environnement

Réglages qui ne doivent pas dériver :

| Variable | Valeur | Pourquoi |
|---|---|---|
| `DB_CONNECTION` | `mariadb` | MariaDB 11.8, pas MySQL 8 — la collation `utf8mb4_0900_ai_ci` n'y existe pas |
| `CACHE_STORE` | `database` | Aucun serveur Redis n'écoute, malgré l'extension PHP présente |
| `QUEUE_CONNECTION` | `database` | idem |
| `SESSION_DRIVER` | `database` | idem |
| `LOG_STACK` | `daily` | `single` grossit sans fin ; un quota atteint arrête **toute** écriture, y compris celle du registre |

Le voisinage reste le point faible : le compte héberge huit autres domaines. Une
compromission de l'un d'eux exposerait les fichiers de PREUVE — argument
permanent en faveur du chiffrement au repos des pièces, déjà en place.
