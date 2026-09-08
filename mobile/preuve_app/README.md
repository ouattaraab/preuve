# preuve_app — client mobile PREUVE

Application Flutter (iOS/Android). **Elle n'habille que le cœur** : les règles
métier vivent dans [`../preuve_core`](../preuve_core), en Dart pur, pour rester
vérifiables sans appareil ni émulateur.

> Ne jamais faire remonter une règle métier ici. Si une décision dépend de ce
> qu'un acheteur a le droit de voir, elle appartient à `preuve_core`.

## Lancer

```bash
flutter pub get
flutter analyze          # doit être vert
flutter test             # tests d'écran
flutter run              # simulateur iOS ou émulateur Android
```

Le SDK n'est pas versionné avec le projet. Éprouvé avec **Flutter 3.44.8 /
Dart 3.12**.

## Ce qui vit ici

| Dossier | Contenu |
|---|---|
| `lib/data/` | Plomberie de plateforme : trousseau, lecture de fichiers, appareil photo |
| `lib/screens/` | Les écrans, un par parcours |
| `lib/ui/` | Direction DJASSA (thème, briques partagées) |

## Deux dépendances tierces, et seulement deux

- **`flutter_secure_storage`** — trousseau iOS et Keystore Android. Le jeton de
  session ouvre l'accès aux biens d'une personne : il n'a rien à faire dans un
  fichier de préférences en clair. La file d'envoi y est rangée aussi — elle ne
  porte pas les images, mais leurs chemins, ce qui est déjà un renseignement.
- **`image_picker`** — appareil photo et galerie. Sans elle, aucun bien ne peut
  dépasser « Déclaré » : ni justificatif, ni KYC, ni pièce de réclamation.

Écrire soi-même le pont vers ces API natives serait plus risqué que d'en
dépendre. **Toute troisième dépendance doit être arbitrée**, pas ajoutée.

## Pièges de plateforme déjà rencontrés

- **`ThemeData.textTheme` ne porte que les couleurs.** Les tailles sont
  fusionnées plus tard, par locale : y appliquer un `fontSizeFactor` lève une
  assertion et faisait planter l'application au démarrage, sur tous les écrans.
  Voir `ui/theme.dart`, verrouillé par un test.
- **Les autorisations d'images sont obligatoires** (`NSCameraUsageDescription`,
  `NSPhotoLibraryUsageDescription`). Sans elles : plantage à l'ouverture de
  l'appareil photo, et rejet par l'App Store.
- **`INTERNET` doit être déclaré dans le manifeste Android.** Le gabarit de
  Flutter ne l'ajoute qu'en débogage ; l'absence ne se voit qu'après publication.

## À faire avant publication

- Déposer les **polices DJASSA** sous `assets/fonts/` et les déclarer dans
  `pubspec.yaml`. Déclarer une police sans son fichier fait échouer la
  construction : c'est pour cela qu'elles n'y sont pas encore.
- Icônes d'application et écran de lancement.
- `versionInstallee` dans `lib/main.dart` doit suivre `pubspec.yaml` : c'est
  elle que le serveur compare à la version minimale exigée.
