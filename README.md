# PREUVE

**Registre déclaratif de propriété et de statut des biens — Côte d'Ivoire.**
Vérifier gratuitement, avant d'acheter, si un véhicule ou un téléphone est
déclaré volé, en litige ou en location.

Édité par OVERNETFLOW · <https://preuve.click>

---

## Ce que fait le produit

Quelqu'un achète une moto d'occasion sur un marché. Il tape le numéro de châssis,
obtient un verdict en moins d'une seconde, et sait s'il doit payer. Il n'a créé
aucun compte, laissé aucune trace exploitable, et le propriétaire ne saura jamais
qu'il a cherché.

Tout le reste — enregistrer un bien, le transférer, déclarer un vol, contester
une propriété — découle de cette scène et lui est subordonné.

## Les règles qui ne se négocient pas

Elles sont énoncées dans [`CLAUDE.md`](CLAUDE.md) et **chacune a un test qui rend
sa violation impossible** (`tests/BusinessRules/`).

1. **La consultation est gratuite, anonyme et sans compte.** Aucun middleware
   d'authentification ne doit jamais apparaître sur `GET /lookup/{identifiant}`.
2. **Toute écriture est authentifiée**, par code à usage unique — il n'existe
   aucun mot de passe.
3. **Un identifiant = un enregistrement actif.** Unicité tenue par la base, pas
   par du code applicatif.
4. **L'identité n'est jamais divulguée**, dans aucun sens, y compris à qui paie
   un rapport, y compris à nos propres agents.
5. **La chaîne d'audit est inaltérable**, protégée par des déclencheurs de base :
   aucun `UPDATE`, aucun `DELETE`, quel que soit le chemin emprunté.
6. Les transitions de statut suivent une matrice unique.
7. Un rapport détaillé ne s'ouvre que par son jeton d'accès.
8. **Minimisation** (Loi 2013-450) : l'adresse IP n'est conservée qu'en empreinte
   salée quotidiennement, le numéro de pièce d'identité qu'en SHA-256 — non
   restituable, y compris sur réquisition.

## Stack

Laravel 12 · PHP 8.4 · MariaDB 11.8 · Sanctum · Pest · Larastan (niveau max)
Application mobile : Flutter (à écrire) · Front public : rendu serveur, sans
JavaScript.

Ni Redis ni Docker : l'hébergement cible est un mutualisé. Les pilotes de cache,
de file et de session sont en base.

## Démarrer en local

```bash
composer install
cp .env.example .env && php artisan key:generate
# MariaDB via Homebrew, port 3307 — voir docs/infrastructure/dev-local-mariadb.md
php artisan migrate
php artisan preuve:publish-categories   # sans catalogue, aucun bien ne peut être enregistré
php artisan serve
```

## Vérifier avant de pousser

```bash
./vendor/bin/pint                                    # style
./vendor/bin/phpstan analyse --memory-limit=1G       # niveau max, zéro erreur tolérée
./vendor/bin/pest                                    # suite complète
```

Les aides de test Pest sont des **fonctions globales** : un nom dupliqué entre
deux fichiers est une erreur fatale, invisible en exécution isolée.

```bash
grep -rhoE "^function [a-zA-Z_][a-zA-Z0-9_]*" tests/ | sort | uniq -d   # doit être vide
```

## Documentation

| Document | Ce qu'il couvre |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Règles métier absolues, conventions, stack |
| [`.memory-bank/activeContext.md`](.memory-bank/activeContext.md) | Où en est le projet, décisions prises, dette assumée |
| [`docs/api/integration-client.md`](docs/api/integration-client.md) | **Intégrer un client** — par parcours, avec les contrats qui ne se devinent pas |
| [`docs/infrastructure/deploiement.md`](docs/infrastructure/deploiement.md) | **Déployer** — procédure, pièges de l'hébergement, retour en arrière |
| [`docs/infrastructure/exploitation.md`](docs/infrastructure/exploitation.md) | Supervision, sauvegardes, restauration, réconciliation |
| [`docs/PREUVE_Backlog_v1.1.md`](docs/PREUVE_Backlog_v1.1.md) | 59 stories, jalons de livraison |

## Une convention de commentaires, et sa raison

Les classes de ce dépôt portent en en-tête **pourquoi** elles sont écrites ainsi,
pas ce qu'elles font. Un lecteur qui voit qu'une catégorie se désactive au lieu
de se supprimer doit trouver, sur place, que des biens y sont rattachés et
deviendraient inaffichables — sans quoi quelqu'un « simplifiera » un jour en
ajoutant une suppression.

Le code est en anglais, les commentaires et la langue de travail en français.
