# Base de développement locale — MariaDB sans Docker (macOS)

La cible de production est un hébergement mutualisé Hostinger sous **MariaDB** ;
la CI tourne sur `mariadb:11.8`. Le développement local utilise donc MariaDB,
et non MySQL : la collation `utf8mb4_uca1400_ai_ci` déclarée dans
`config/database.php` n'existe pas sous MySQL, et les déclencheurs
d'inaltérabilité d'`audit_log` doivent être exercés sur le même moteur qu'en
production.

## Cohabitation avec une installation MySQL existante

Homebrew refuse d'installer MariaDB et MySQL ensemble : les deux formules
posent les mêmes binaires. L'installation ci-dessous garde MySQL comme
installation liée (`/opt/homebrew/bin/mysql`) et n'appelle MariaDB que par
chemin absolu.

```sh
brew unlink mysql
brew install mariadb
brew unlink mariadb        # ne pas laisser MariaDB prendre les binaires
brew link --overwrite mysql
```

L'instance du projet est **isolée** de MySQL : port, datadir, socket et fichier
de configuration distincts. Les deux serveurs ne se voient pas.

| | MySQL existant | MariaDB PREUVE |
|---|---|---|
| Port | 3306 | **3307** |
| Datadir | `/opt/homebrew/var/mysql` | `/opt/homebrew/var/mariadb-preuve` |
| Socket | par défaut | `/tmp/preuve-mariadb.sock` |
| Configuration | `/opt/homebrew/etc/my.cnf` | `/opt/homebrew/etc/preuve-mariadb.cnf` |

Le datadir dédié n'est pas un confort : MariaDB et MySQL utilisent le même
datadir par défaut, et démarrer MariaDB dessus corromprait l'installation
MySQL.

## Fichier de configuration

`/opt/homebrew/etc/preuve-mariadb.cnf` (hors dépôt, propre à la machine) :

```ini
[mariadbd]
port      = 3307
datadir   = /opt/homebrew/var/mariadb-preuve
socket    = /tmp/preuve-mariadb.sock
pid-file  = /opt/homebrew/var/mariadb-preuve/preuve.pid
bind-address = 127.0.0.1

character-set-server   = utf8mb4
collation-server       = utf8mb4_uca1400_ai_ci
default-storage-engine = InnoDB

# La chaîne d'audit hache la représentation textuelle de created_at : le fuseau
# du serveur doit être stable et ne pas dépendre du fuseau système de la machine.
default-time-zone = '+00:00'

[client]
port   = 3307
socket = /tmp/preuve-mariadb.sock
```

Le passage par `--defaults-file` est obligatoire : le `my.cnf` de MySQL
contient `mysqlx-bind-address`, variable inconnue de MariaDB, qui fait échouer
son démarrage.

## Initialisation (une seule fois)

```sh
/opt/homebrew/opt/mariadb/bin/mariadb-install-db \
  --defaults-file=/opt/homebrew/etc/preuve-mariadb.cnf \
  --auth-root-authentication-method=normal
```

Puis, serveur démarré :

```sql
CREATE DATABASE preuve CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
CREATE USER 'preuve'@'%' IDENTIFIED BY 'preuve';
CREATE USER 'preuve'@'localhost' IDENTIFIED BY 'preuve';
GRANT ALL PRIVILEGES ON preuve.* TO 'preuve'@'%';
GRANT ALL PRIVILEGES ON preuve.* TO 'preuve'@'localhost';
ALTER USER 'root'@'localhost' IDENTIFIED BY 'root';
FLUSH PRIVILEGES;
```

Ces identifiants sont ceux de `.env` et de `deploy/privileges-audit-log.md` ;
ils n'ont de valeur que sur un poste de développement.

## Démarrer et arrêter

```sh
# Démarrer
/opt/homebrew/opt/mariadb/bin/mariadbd-safe \
  --defaults-file=/opt/homebrew/etc/preuve-mariadb.cnf &

# Console
/opt/homebrew/opt/mariadb/bin/mariadb \
  --defaults-file=/opt/homebrew/etc/preuve-mariadb.cnf -uroot -proot preuve

# Arrêter
/opt/homebrew/opt/mariadb/bin/mariadb-admin \
  --defaults-file=/opt/homebrew/etc/preuve-mariadb.cnf -uroot -proot shutdown
```

## Écart de version à surveiller

Le poste de développement tourne sur la version courante de la formule Homebrew
(MariaDB 12.x), la CI sur 11.8, et la production Hostinger sur la version que
l'hébergeur propose. Cet écart est acceptable pour le développement courant
mais **la CI reste l'arbitre** : tout comportement moteur sur lequel une règle
métier s'appuie — déclencheurs d'`audit_log`, verrous nommés `GET_LOCK`,
unicité partielle par `NULL` — doit être vérifié par un test exécuté en CI, et
jamais constaté seulement en local.
