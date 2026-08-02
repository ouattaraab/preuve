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
`mariadb` (`DB_USERNAME` en production) et l'hôte depuis lequel il se connecte.

```sql
-- 1. Révoquer sur audit_log ce que l'application n'a jamais besoin de faire :
--    - UPDATE et DELETE : l'application n'écrit que par INSERT (AuditChain::append()).
--    - DROP et ALTER : seules les migrations, exécutées avec un utilisateur distinct
--      (propriétaire du schéma), doivent pouvoir modifier la structure de la table.
--    - TRIGGER : empêche l'utilisateur applicatif de désactiver lui-même les
--      déclencheurs d'inaltérabilité (BEFORE UPDATE/DELETE).
REVOKE UPDATE, DELETE, DROP, ALTER, TRIGGER ON preuve.audit_log FROM 'preuve_app'@'%';

-- 2. Vérifier ce qui reste accordé à l'utilisateur applicatif sur cette table :
--    SELECT et INSERT doivent être les seuls privilèges restants.
SHOW GRANTS FOR 'preuve_app'@'%';
```

Si l'utilisateur applicatif est accordé au niveau du schéma entier (`GRANT ALL ON preuve.* TO ...`)
plutôt que table par table, appliquer le `REVOKE` ci-dessus explicitement sur `audit_log` après
l'octroi global : un `REVOKE` sur une table précise restreint bien les privilèges hérités d'un
`GRANT` de niveau schéma.

## Vérification post-déploiement

Avec les identifiants applicatifs de production (jamais ceux d'un compte d'administration) :

```sql
-- Doit échouer avec « command denied » (erreur 1142), pas avec l'erreur SIGNAL des
-- déclencheurs applicatifs — la protection doit venir des privilèges, en profondeur
-- de celle des déclencheurs, pas se substituer à elle.
UPDATE audit_log SET action = 'test' WHERE id = 1;
DELETE FROM audit_log WHERE id = 1;
DROP TRIGGER audit_log_interdit_update;
```

## Ce que ce document ne couvre pas

- L'utilisateur propriétaire du schéma (celui qui exécute `php artisan migrate` au déploiement)
  garde nécessairement les privilèges nécessaires aux migrations (`CREATE`, `ALTER`, `DROP`,
  `TRIGGER`). Ce document ne concerne que l'utilisateur applicatif utilisé par la connexion Laravel
  `mariadb` au quotidien (`DB_USERNAME`), pas celui utilisé ponctuellement pour déployer.
- La purge réglementaire (ARTCI, 12 mois) et l'ancrage externe du hash de tête sont des sujets
  d'architecture distincts, hors périmètre de ce document.
