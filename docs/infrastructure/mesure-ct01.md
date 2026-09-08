# Mesure de CT-01 — verdict en moins d'une seconde

## Résultat (02/08/2026)

Mesure faite sur le poste de développement (MariaDB 12.3 locale, port 3307),
avec la commande `preuve:benchmark-lookup`.

| Volume | Consultations mesurées | Médiane | 95e centile | 99e centile | Seuil |
|---|---|---|---|---|---|
| 200 000 biens, journal vide | 500 | 1 ms | 1 ms | 1 ms | 1000 ms |
| 200 000 biens, 1 000 000 de consultations journalisées | 500 | 1 ms | 1 ms | 1 ms | 1000 ms |

**CT-01 est tenu avec trois ordres de grandeur de marge** côté serveur.

Le second scénario est le seul qui vaille : le journal des consultations est la
table qui grossit le plus vite — une ligne par consultation, conservée douze
mois — et c'est elle que le plafond anti-profilage interroge **à chaque appel**.
Mesurer sur un journal vide aurait donné un chiffre flatteur et faux.

## Pourquoi c'est rapide, et ce qui le ferait cesser

Les deux requêtes du chemin critique passent par un index dédié :

```
assets   ref   idx_lookups (identifier_normalized, life_status, trust_level)   rows=1
lookups  range idx_lookups_rate (ip_hash, created_at)                          rows=1  Using index
```

`Using index` sur la seconde signifie que le comptage du plafond ne touche
jamais les lignes elles-mêmes : il se résout entièrement dans l'index. C'est ce
qui tient la latence à volume, et c'est ce qui s'effondrerait si l'un de ces
index venait à être retiré ou réordonné.

**Relancer la mesure après tout changement de schéma ou d'index sur `assets` ou
`lookups`.** Un index mal choisi passe inaperçu sur mille lignes et s'effondre
sur deux cent mille.

## Reproduire

```sh
# Génère le volume puis mesure (destructif : refuse la production)
php -d memory_limit=512M artisan preuve:benchmark-lookup \
    --seed=200000 --seed-lookups=1000000 --runs=500

# Remesure sur l'existant, sans rien générer
php artisan preuve:benchmark-lookup --runs=1000
```

La commande sort en échec si le 95e centile dépasse le seuil : elle peut donc
être branchée sur une chaîne d'intégration.

## Ce que cette mesure ne dit pas

- **La latence réseau 3G n'est pas couverte.** CT-01 s'entend du point de vue de
  l'utilisateur ; la mesure ne porte que sur la part serveur, seule que la
  plateforme maîtrise. Un verdict rendu en 1 ms peut mettre deux secondes à
  atteindre un téléphone en bord de réseau. La mesure de bout en bout demande
  une remontée depuis le client mobile.
- **L'hébergement mutualisé cible est plus lent** que ce poste : moins de
  mémoire, disque partagé, base voisine d'autres clients. La marge de trois
  ordres de grandeur laisse de la place, mais le chiffre devra être repris sur
  l'environnement réel avant le lancement.
- **Aucune charge concurrente** n'était appliquée. Ces mesures sont
  séquentielles ; elles ne disent rien du comportement sous cent consultations
  simultanées.
