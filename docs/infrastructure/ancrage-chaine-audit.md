# Ancrage de la chaîne d'audit — procédure d'exploitation

## Pourquoi cet ancrage existe

La chaîne d'audit de PREUVE lie chaque action à la précédente par une empreinte
SHA-256. Cette construction détecte une **retouche** — une ligne modifiée en
base — mais **pas une reconstruction**.

L'algorithme est public et ne comporte aucun secret. Quiconque dispose du droit
`INSERT` sur la base peut vider la table et la reconstruire de bout en bout,
sans l'action qui le gêne, avec des empreintes parfaitement cohérentes.
`preuve:verify-audit-chain` validerait cette chaîne reconstruite sans broncher.

> **Tant qu'aucun ancrage externe n'existe, la chaîne n'est pas opposable.**
> Sa cohérence interne ne prouve rien devant un tiers.

Ce qui rend la reconstruction détectable, c'est qu'une empreinte de la tête a
été **publiée ailleurs, à une date**, hors de portée de qui contrôle la base.
Falsifier l'histoire supposerait alors de compromettre aussi le tiers qui
conserve cette empreinte.

## Configurer les canaux

Deux canaux, indépendants. **Un seul qui aboutit suffit** à rendre l'ancrage
opposable, mais les configurer tous les deux protège d'une panne de l'un.

```http
PUT /api/v1/admin/audit-anchor
{
  "mail_recipient": "archives@exemple-externe.ci",
  "storage_disk": "anchors"
}
```

Réservé aux administrateurs.

### Courrier électronique

Le message est horodaté et conservé par un serveur de messagerie tiers.

**L'adresse doit être hors de l'infrastructure de la plateforme.** Une boîte
hébergée chez le même prestataire que la base, ou administrée par les mêmes
personnes, ne prouve pas grand-chose : privilégiez un domaine et un fournisseur
distincts, sur une boîte d'archivage dédiée dont personne ne purge le contenu.

### Stockage objet séparé

Le disque est configuré **à part** de celui des justificatifs, et c'est
volontaire : le pointer sur le même bucket annulerait l'intérêt du canal.
Idéalement un bucket en écriture unique (WORM), ou chez un autre fournisseur.

Le fichier porte la date dans son nom et n'est jamais écrasé — deux ancrages du
même jour cohabitent plutôt que le second n'efface le premier.

## Passage quotidien

```
Schedule::command('preuve:anchor-audit-head')->dailyAt('02:40');
```

La commande **sort en échec quand aucun canal externe n'a abouti**, ce que le
planificateur remonte. C'est délibéré : une commande qui réussirait
silencieusement sans rien avoir publié entretiendrait exactement l'illusion
contre laquelle l'ancrage existe.

L'ancrage a lieu **même si rien n'a été ajouté** depuis la veille : un ancrage
identique au précédent atteste qu'aucune entrée n'a été insérée entre les deux
dates.

## Vérifier

```sh
php artisan preuve:verify-audit-chain
```

Deux contrôles, et il faut les deux :

| Contrôle | Ce qu'il attrape | Ce qu'il ne voit pas |
|---|---|---|
| Cohérence interne | Une ligne retouchée en base | Une chaîne reconstruite de zéro |
| Confrontation aux ancrages | Une reconstruction, une suppression | Une falsification antérieure au premier ancrage |

En API : `GET /api/v1/admin/audit-anchor/verify`.

`GET /api/v1/admin/audit-anchor` donne l'état : depuis quand la chaîne est
opposable, et combien d'ancrages ont échoué depuis le dernier succès.

## Que faire d'un constat d'ancrage

Le message reçu est **la pièce de comparaison**. Il doit être conservé sans
modification et jamais supprimé. Il ne contient aucune donnée personnelle —
seulement des empreintes, un compteur et une date — et peut donc être conservé
indéfiniment, y compris chez un tiers, sans conflit avec le droit à l'effacement
(Loi 2013-450).

En cas de contestation, comparer l'empreinte de tête du constat à celle que
porte aujourd'hui l'entrée correspondante. Une divergence signale une réécriture
postérieure à la date du constat.

## Limites connues

- **Rien n'est opposable avant le premier ancrage.** Les entrées écrites avant
  lui ne sont couvertes par aucune publication externe.
- **La fenêtre entre deux ancrages reste falsifiable** : une reconstruction
  survenue puis corrigée dans la même journée ne laisserait pas de trace.
  Augmenter la fréquence réduit cette fenêtre.
- **`TRUNCATE` n'est pas bloqué** par les déclencheurs `BEFORE UPDATE` /
  `BEFORE DELETE` de `audit_log` : c'est précisément le trou que l'ancrage
  couvre. Retirer le privilège `DROP` au compte applicatif en production reste
  recommandé (voir `deploy/privileges-audit-log.md`).
