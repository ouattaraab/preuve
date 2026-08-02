# Privilèges MariaDB sur `audit_log` — à appliquer au déploiement

## Pourquoi

La spec (§4.6, « Inaltérabilité du journal — défense en profondeur ») demande deux niveaux de
protection indépendants pour `audit_log` :

1. des déclencheurs MariaDB `BEFORE UPDATE`/`BEFORE DELETE` qui lèvent une erreur SQL — livrés dans
   `database/migrations/2026_08_01_173500_add_immutability_triggers_to_audit_log_table.php` ;
2. **un utilisateur de base applicatif sans droit `UPDATE` ni `DELETE`** sur cette table — objet de
   ce document, qui n'est concrétisé nulle part dans le code : il ne peut pas l'être, car les
   migrations elles-mêmes (`CREATE TRIGGER`, `DROP TRIGGER` dans `down()`) et certains tests ont
   besoin de privilèges que l'utilisateur applicatif de production ne doit précisément pas avoir.

Ces deux protections sont complémentaires, pas redondantes : un déclencheur peut en théorie être
contourné par qui a le droit `DROP`/`CREATE TRIGGER` (ou par un import direct qui recrée la table
sans lui). Un utilisateur de base sans `UPDATE`/`DELETE`/`DROP`/`TRIGGER` sur `audit_log` rend cette
table inaltérable même si un bug applicatif ou une requête d'administration malencontreuse tentait de
la modifier — ce qui compte d'autant plus sur un hébergement mutualisé partagé avec d'autres sites,
où l'isolation ne peut pas reposer sur autre chose que les privilèges de la base elle-même.

## Correctif important (re-revue) : les privilèges MariaDB sont additifs, pas soustractifs

Une première version de ce document proposait d'accorder l'utilisateur applicatif au niveau du
schéma entier (`GRANT ALL ON preuve.* TO ...`), puis de restreindre `audit_log` par un `REVOKE` ciblé
sur cette seule table. **Cette procédure ne fonctionne pas** : les privilèges MariaDB (comme MySQL)
sont additifs et évalués par niveau (global, base, table, colonne) — un privilège accordé à un niveau
plus large n'est jamais retiré par un `REVOKE` à un niveau plus étroit. Un `REVOKE ... ON
preuve.audit_log FROM ...` après un `GRANT ALL ON preuve.* TO ...` échoue même à s'exécuter
(`ERROR 1147: There is no such grant defined ... on table 'audit_log'`, puisque le privilège
concerné n'a jamais été accordé *à ce niveau précis*), et si on l'ignore, la table reste
entièrement modifiable. Un exploitant qui suivait l'ancienne version de ce document croyait avoir
durci la table la plus sensible du produit alors que rien n'avait changé.

**La procédure correcte accorde les privilèges table par table dès le départ, jamais au niveau du
schéma entier**, avec `audit_log` limitée à `SELECT, INSERT`. C'est plus verbeux (une ligne par
table, à maintenir quand une migration ajoute une table) et c'est le prix nécessaire d'un privilège
qui protège réellement.

## Pourquoi les tests exigent l'inverse (et pourquoi ce n'est pas une contradiction)

Plusieurs tests de la suite (`tests/Feature/AuditChainDbIntegrityTest.php`,
`tests/Feature/AuditChainConcurrencyTest.php`) exécutent des `TRUNCATE TABLE audit_log` et des
`DROP TRIGGER`/`CREATE TRIGGER` pour :

- réinitialiser la table entre des tests qui ne peuvent pas s'appuyer sur `RefreshDatabase` (des
  processus PHP concurrents committent réellement, hors de toute transaction de test) ;
- désactiver temporairement le déclencheur `BEFORE UPDATE` afin de simuler, dans un test, une
  altération directe en base qui contourne l'application — précisément le scénario que la défense en
  profondeur doit couvrir.

C'est la pratique normale et attendue : les tests tournent avec un utilisateur de base privilégié
(celui du conteneur MariaDB de développement/CI, propriétaire du schéma), la production tourne avec
un utilisateur restreint qui ne peut ni modifier ni supprimer une ligne d'audit, ni désactiver le
déclencheur qui l'en empêcherait, ni supprimer la table. **Ne pas** affaiblir les tests pour les
aligner sur les privilèges de production : cela reviendrait à ne plus tester la défense en
profondeur elle-même.

## Instructions SQL — à exécuter au déploiement

Remplacer `'preuve_app'@'%'` par l'utilisateur applicatif réel utilisé par la connexion Laravel
`mariadb` (`DB_USERNAME` en production) et l'hôte depuis lequel il se connecte. Remplacer
`'mot-de-passe-fort'` par un secret généré, jamais celui-ci.

```sql
-- L'utilisateur applicatif n'est JAMAIS accordé au niveau du schéma
-- (`GRANT ... ON preuve.*`) : un privilège accordé à ce niveau ne peut plus
-- être retiré par un REVOKE plus étroit sur une seule table (voir plus
-- haut). Chaque table reçoit exactement ce dont l'application a besoin.
CREATE USER IF NOT EXISTS 'preuve_app'@'%' IDENTIFIED BY 'mot-de-passe-fort';

-- audit_log : SELECT et INSERT uniquement — jamais UPDATE, DELETE, DROP,
-- ALTER ou TRIGGER. L'application n'écrit que par INSERT
-- (AuditChain::append()/transaction()) ; DROP/ALTER/TRIGGER n'appartiennent
-- qu'à l'utilisateur qui exécute les migrations, un utilisateur distinct.
GRANT SELECT, INSERT ON preuve.audit_log TO 'preuve_app'@'%';

-- Tables métier ordinaires : CRUD complet, une ligne par table. Cette liste
-- doit être tenue à jour à chaque migration qui ajoute une table.
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.users TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.otp_codes TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.sessions TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.password_reset_tokens TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.cache TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.cache_locks TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.jobs TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.job_batches TO 'preuve_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.failed_jobs TO 'preuve_app'@'%';

-- Ni SELECT, ni aucun autre privilège sur `migrations` : l'application n'a
-- jamais besoin d'y accéder, seul l'utilisateur qui exécute les migrations
-- doit pouvoir la lire/écrire.

FLUSH PRIVILEGES;

-- Vérifier ce qui est réellement accordé, table par table :
SHOW GRANTS FOR 'preuve_app'@'%';
```

## Vérification post-déploiement

Avec les identifiants applicatifs de production réels (jamais ceux d'un compte d'administration) :

```sql
-- Doit échouer avec « command denied » (ER_TABLEACCESS_DENIED_ERROR, 1142) —
-- la vérification de privilège a lieu avant même que le déclencheur
-- applicatif (SIGNAL) ait l'occasion de s'exécuter. La protection vient
-- réellement des privilèges, en profondeur de celle des déclencheurs, pas en
-- remplacement.
UPDATE audit_log SET action = 'test' WHERE id = 1;
DELETE FROM audit_log WHERE id = 1;
DROP TRIGGER audit_log_interdit_update;

-- Doivent réussir :
SELECT COUNT(*) FROM audit_log;
INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, payload, record_hash, prev_hash, chain_hash, created_at)
VALUES ('system', NULL, 'test', 'asset', 1, '{}', REPEAT('a', 64), REPEAT('0', 64), REPEAT('b', 64), NOW());
```

## Preuve d'exécution réelle (base locale, utilisateur jetable)

Cette procédure a été exécutée contre la base de développement locale (utilisateur `root` du
conteneur MariaDB local, pour créer/gérer l'utilisateur de test) avec un utilisateur créé pour
l'occasion (`preuve_app_test`) et supprimé ensuite. Sortie réelle, verbatim :

```
$ mariadb -uroot -proot preuve -e "
    CREATE USER 'preuve_app_test'@'%' IDENTIFIED BY 'TestPassword123!';
    GRANT SELECT, INSERT ON preuve.audit_log TO 'preuve_app_test'@'%';
    GRANT SELECT, INSERT, UPDATE, DELETE ON preuve.users TO 'preuve_app_test'@'%';
    ... (une ligne par autre table métier) ...
    FLUSH PRIVILEGES;"
→ (aucune erreur)

$ mariadb -uroot -proot -e "SHOW GRANTS FOR 'preuve_app_test'@'%';"
Grants for preuve_app_test@%
GRANT USAGE ON *.* TO `preuve_app_test`@`%` IDENTIFIED BY PASSWORD '...'
GRANT SELECT, INSERT ON `preuve`.`audit_log` TO `preuve_app_test`@`%`
GRANT SELECT, INSERT, UPDATE, DELETE ON `preuve`.`users` TO `preuve_app_test`@`%`
... (une ligne par autre table métier, toutes en SELECT, INSERT, UPDATE, DELETE) ...

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "UPDATE audit_log SET action='test' WHERE id=1;"
ERROR 1142 (42000) at line 1: UPDATE command denied to user 'preuve_app_test'@'localhost' for table `preuve`.`audit_log`

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "DELETE FROM audit_log WHERE id=1;"
ERROR 1142 (42000) at line 1: DELETE command denied to user 'preuve_app_test'@'localhost' for table `preuve`.`audit_log`

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "DROP TRIGGER audit_log_interdit_update;"
ERROR 1142 (42000) at line 1: TRIGGER command denied to user 'preuve_app_test'@'localhost' for table `preuve`.`audit_log`

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "ALTER TABLE audit_log ADD COLUMN test_col INT;"
ERROR 1142 (42000) at line 1: ALTER command denied to user 'preuve_app_test'@'localhost' for table `preuve`.`audit_log`

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "SELECT COUNT(*) FROM audit_log;"
COUNT(*)
0

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "INSERT INTO audit_log (...) VALUES (...);"
→ (aucune erreur : la ligne a bien été insérée)

$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "SELECT COUNT(*) FROM users;"
COUNT(*)
0
```

**Pour comparaison, l'ancienne procédure (incorrecte), rejouée pour cette même re-vérification :**

```
$ mariadb -uroot -proot preuve -e "
    CREATE USER 'preuve_app_test'@'%' IDENTIFIED BY 'TestPassword123!';
    GRANT ALL PRIVILEGES ON preuve.* TO 'preuve_app_test'@'%';
    FLUSH PRIVILEGES;"
→ (aucune erreur — c'est déjà le problème : ce GRANT au niveau schéma est accepté silencieusement)

$ mariadb -uroot -proot preuve -e "REVOKE UPDATE, DELETE, DROP, ALTER, TRIGGER ON preuve.audit_log FROM 'preuve_app_test'@'%';"
ERROR 1147 (42000) at line 1: There is no such grant defined for user 'preuve_app_test' on host '%' on table 'audit_log'

$ mariadb -uroot -proot -e "SHOW GRANTS FOR 'preuve_app_test'@'%';"
Grants for preuve_app_test@%
GRANT USAGE ON *.* TO `preuve_app_test`@`%` IDENTIFIED BY PASSWORD '...'
GRANT ALL PRIVILEGES ON `preuve`.* TO `preuve_app_test`@`%`
-- inchangé : le REVOKE a échoué à s'exécuter, et même s'il avait été ignoré,
-- rien n'aurait été retiré du GRANT ALL au niveau schéma.

-- Preuve que la table reste effectivement modifiable dès que le déclencheur
-- applicatif est désactivé (ce que la défense en profondeur doit précisément
-- couvrir, en profondeur des privilèges, pas à leur place) :
$ mariadb -uroot -proot preuve -e "DROP TRIGGER audit_log_interdit_update;"
$ mariadb -upreuve_app_test -pTestPassword123! preuve -e "UPDATE audit_log SET action='test-old-procedure' WHERE id=1; SELECT id, action FROM audit_log WHERE id=1;"
id  action
1   test-old-procedure
-- la mise à jour a réellement été appliquée : l'ancienne procédure de
-- privilèges n'apportait aucune protection réelle.
```

Utilisateur de test et déclencheur restaurés après vérification (`DROP USER`, `php artisan
migrate:fresh` sur la base locale, sans effet sur un environnement réel).

## Ce que ce document ne couvre pas

- L'utilisateur propriétaire du schéma (celui qui exécute `php artisan migrate` au déploiement)
  garde nécessairement les privilèges nécessaires aux migrations (`CREATE`, `ALTER`, `DROP`,
  `TRIGGER`). Ce document ne concerne que l'utilisateur applicatif utilisé par la connexion Laravel
  `mariadb` au quotidien (`DB_USERNAME`), pas celui utilisé ponctuellement pour déployer.
- La liste de tables ci-dessus doit être mise à jour à chaque migration qui ajoute une table : c'est
  le coût assumé d'un privilège qui protège réellement, plutôt qu'un `GRANT ALL` pratique mais inerte
  face à `audit_log`.
- La purge réglementaire (ARTCI, 12 mois) et l'ancrage externe du hash de tête sont des sujets
  d'architecture distincts, hors périmètre de ce document.
