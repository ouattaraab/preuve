# Audit de l'environnement Hostinger

> Relevé du 01/08/2026 sur le compte mutualisé cible, domaine temporaire
> `palegreen-snake-483042.hostingersite.com`.
> **Les identifiants de connexion ne figurent pas dans ce dépôt** (public).
> Hôte, port, utilisateur et mot de passe sont conservés hors dépôt.

## Verdict

L'environnement convient au lot 1, **sous réserve de deux vérifications restant à faire dans hPanel** (§ Actions requises). Trois écarts par rapport aux hypothèses du cadrage doivent être répercutés dans les migrations et la CI.

## Relevé

| Élément | Valeur constatée | Conséquence |
|---|---|---|
| Système | CloudLinux 4.18 (LVE), `fr-int-web1049.main-hosting.eu` | Mutualisé confirmé, isolation par LVE |
| PHP sélectionné | **8.4** (`~/.cl.selector/defaults.cfg`) | Versions 8.1 à 8.5 disponibles au sélecteur |
| PHP CLI | 8.4.19 | Aligné sur la version web |
| Extensions | `pdo_mysql` `mbstring` `bcmath` `intl` `gd` `zip` `fileinfo` `openssl` `curl` `imagick` `gmp` `soap` `opcache` | Toutes les dépendances de Laravel 11 et Filament sont couvertes |
| **Base de données** | **MariaDB 11.8.8** | **Écart majeur** — voir §1 |
| **Redis** | Extension PHP présente, **aucun serveur** (port 6379 fermé) | Confirme les drivers `database` de la spec |
| Composer | 2.9.8 | Déploiement par `composer install` possible |
| Git | 2.43.7 | Déploiement par `git pull` possible |
| `memory_limit` | 512 Mo | Confortable |
| `max_execution_time` | 0 en CLI | Aucune contrainte sur les tâches planifiées |
| `upload_max_filesize` | 256 Mo | Largement suffisant pour les pièces justificatives |
| Accès HTTPS sortant | Ouvert (Mindee, Cloudflare joignables) | OCR, R2 et passerelles de paiement accessibles |
| **`crontab` en CLI** | **Absent** | Les tâches planifiées se créent uniquement via hPanel — voir §3 |
| Occupation disque | 3,1 Go pour l'ensemble du compte | Le compte héberge déjà 9 autres domaines |
| Racine du domaine cible | `~/domains/palegreen-snake-483042.hostingersite.com/public_html` | Le dossier parent porte un `DO_NOT_UPLOAD_HERE` |

## 1. MariaDB au lieu de MySQL 8 — écart majeur

Le schéma de référence `database/preuve_schema_mysql8.sql` cible MySQL 8.0 et déclare la collation `utf8mb4_0900_ai_ci`. **Cette collation n'existe pas dans MariaDB.**

### Décisions

| Point | Décision |
|---|---|
| Connexion Laravel | `DB_CONNECTION=mariadb` — Laravel 11 fournit un driver MariaDB distinct du driver MySQL. Ne pas utiliser `mysql` |
| Collation | `utf8mb4_uca1400_ai_ci` (collation Unicode moderne de MariaDB 11.x) |
| Environnement de test | **La CI et le poste de développement doivent tourner sur `mariadb:11.8`.** Tester sur MySQL et déployer sur MariaDB reviendrait à valider un moteur et à en livrer un autre |

### Ce qui reste valide sans modification

- **Le pattern d'unicité active** `UNIQUE (identifier_normalized, active_flag)` : MariaDB, comme MySQL, considère les `NULL` comme distincts dans un index unique. La règle « un identifiant = un enregistrement actif » fonctionne à l'identique.
- Les contraintes `CHECK` (MariaDB ≥ 10.2), les `ENUM`, `SELECT … FOR UPDATE`, les colonnes générées et les transactions InnoDB.

### Ce qui change

- Le type `JSON` de MariaDB est un alias de `LONGTEXT` assorti d'une contrainte `json_valid()`. Les fonctions JSON existent et `$table->json()` de Laravel fonctionne, mais **l'indexation directe d'un chemin JSON n'a pas le même comportement qu'en MySQL 8**. C'est sans effet sur PREUVE : par conception, l'identifiant canonique n'est jamais stocké dans le JSON (§5.2 de la spec).
- Le fichier `database/preuve_schema_mysql8.sql` devient un document de référence historique. **Les migrations Laravel font foi.**

## 2. Redis : extension sans serveur

L'extension PHP `redis` est compilée sur toutes les versions à partir de 8.1, mais aucun serveur n'écoute sur le port 6379. La présence de l'extension ne doit pas induire en erreur : les drivers `cache`, `queue` et `session` restent en `database`, conformément au §3 de la spec.

## 3. Tâches planifiées

`crontab` n'est pas disponible en ligne de commande : les tâches se créent exclusivement depuis hPanel → Avancé → Tâches Cron. La granularité minimale offerte par l'interface **n'a pas pu être vérifiée en SSH** et conditionne le comportement des files.

## Actions requises avant la tâche 1 du plan

| # | Action | Où | Pourquoi |
|---|---|---|---|
| 1 | Créer une tâche cron de test à la minute et confirmer qu'elle s'exécute bien toutes les minutes | hPanel → Avancé → Tâches Cron | Si l'intervalle minimal est de 5 minutes ou plus, les files et l'expiration des jetons se dégradent. À consigner comme dette |
| 2 | Créer la base de données et son utilisateur dédiés à PREUVE | hPanel → Bases de données MySQL | Le compte héberge 9 autres projets : PREUVE doit avoir sa base et son utilisateur propres, sans droits sur les autres |
| 3 | Vérifier que le document root du domaine cible peut pointer vers un sous-dossier `public` | hPanel → Sites → Gestionnaire de domaines | Détermine si l'application se pose au-dessus de `public_html` ou si `public_html` reçoit le contenu de `/public` |
| 4 | Changer le mot de passe SSH | hPanel → Avancé → Accès SSH | Le mot de passe a circulé en clair |

## Isolation vis-à-vis des projets existants

Le compte héberge déjà `bookmi.click`, `oonclick.com`, `delivroons.net`, `pantheonivoire.com`, `seemi.click`, `signaloons.com`, `crosstestapps.com` et un dossier `secr`. PREUVE doit :

- vivre dans son propre dossier applicatif, hors des arborescences existantes ;
- disposer d'une base et d'un utilisateur de base dédiés ;
- ne partager aucun fichier de configuration ni aucun cron avec les autres projets.

Le voisinage sur un même compte mutualisé reste un point faible : une compromission d'un des autres sites expose potentiellement les fichiers de PREUVE. C'est un argument supplémentaire pour que les documents d'identité vivent sur R2, chiffrés côté application, et jamais sur ce disque.
