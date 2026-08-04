# Client mobile PREUVE

Deux paquets, et la séparation n'est pas décorative.

| Paquet | Ce qu'il porte | Vérifiable sans appareil |
|---|---|---|
| `preuve_core` | Contrats d'API, modèles, contrôle local des identifiants, comparaison de versions | **Oui** — `dart analyze` et `dart test` |
| `preuve_app` | L'application Flutter : thème, écrans | Non — demande le SDK Flutter |

Les règles qui décident ce qu'un acheteur voit à l'écran vivent dans
`preuve_core`, en Dart pur, **sans aucune dépendance à Flutter ni à quoi que ce
soit d'autre**. Elles s'exécutent donc dans une console, en une seconde, sans
émulateur — ce qui est la différence entre des règles réellement éprouvées et
des règles qu'on croit justes.

## État actuel

**Lot 1 : le parcours de consultation.** C'est la promesse centrale du produit,
et le seul parcours qui ne demande ni compte, ni version à jour, ni rien d'autre
qu'un identifiant.

- ✅ Client HTTP, sans dépendance externe (`dart:io` suffit)
- ✅ Erreurs traduites en **conduites** et non en codes (402 → payer, 409 →
  réclamer, 426 → mettre à jour sans bloquer la consultation, 429 → défi)
- ✅ Contrôle local VIN / IMEI avant l'appel réseau
- ✅ Comparaison de versions pour l'écran de mise à jour au démarrage
- ✅ Thème DJASSA, écran de saisie, écran de verdict
- ⬜ Connexion par code à usage unique
- ⬜ Enregistrement d'un bien, file d'envoi différée avec reprise
- ⬜ Déclaration de vol, transfert, réclamation

## Vérifier le cœur

```bash
cd mobile/preuve_core
dart pub get
dart analyze     # aucune anomalie tolérée
dart test        # 24 tests
```

## Faire tourner l'application

Le SDK Flutter n'était pas installé sur la machine de développement au moment
d'écrire ce lot : **la couche `preuve_app` n'a jamais été compilée.** Elle
n'utilise que des composants du cœur de Flutter, sans paquet tiers, ce qui borne
le risque — mais elle demandera vraisemblablement quelques ajustements au
premier `flutter run`.

```bash
cd mobile/preuve_app
flutter create --platforms=android,ios .   # ajoute les dossiers natifs
flutter pub get
flutter run
```

`flutter create` sur un dossier existant complète l'arborescence native sans
écraser `lib/` ni `pubspec.yaml`.

## Ce qui reste à déposer

**Les polices DJASSA** — Bricolage Grotesque et Atkinson Hyperlegible — ne sont
pas encore dans le dépôt côté mobile. Elles ne sont volontairement **pas
déclarées** dans `pubspec.yaml` : déclarer une police sans son fichier fait
échouer la construction. Les déposer sous `assets/fonts/`, puis les déclarer, et
les référencer dans `Djassa.build()`.

Atkinson Hyperlegible n'est pas un choix esthétique : elle est dessinée pour la
basse vision, et ses caractères sont distinguables deux à deux — le 1 et le I,
le 0 et le O. Sur une application où l'on recopie des numéros de châssis gravés,
c'est une fonction, pas une décoration.

## Deux règles à ne pas franchir

**La consultation ne demande jamais rien.** Ni compte, ni version à jour, ni
défi sur le chemin nominal. Toute condition ajoutée à cet écran trahirait la
règle métier absolue n° 1.

**Le jeton n'accompagne jamais une consultation.** `PreuveApi.getAnonymous()`
existe pour cela. Un porteur de jeton est certes dispensé du plafond horaire,
mais ce confort ne vaut pas de transformer une consultation anonyme en
historique nominatif de ce que quelqu'un a vérifié avant d'acheter.

## Contrats d'API

Voir [`docs/api/integration-client.md`](../docs/api/integration-client.md) :
organisé par parcours, il ne documente que ce qui ne se devine pas.
