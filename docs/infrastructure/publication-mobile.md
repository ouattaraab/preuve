# Publier l'application Preuve

> Éditeur : BookMi · Identifiant définitif : `ci.bookmi.preuve`

## Ce qu'il ne faut jamais perdre

Deux fichiers, **absents du dépôt et sauvegardés nulle part ailleurs que chez toi** :

```
mobile/preuve_app/android/keystore/preuve-release.jks
mobile/preuve_app/android/key.properties
```

Ils sont la **seule preuve** que les mises à jour de Preuve viennent de BookMi.

- **Perdus** : plus aucune mise à jour ne peut être publiée. Google Play refuse un
  binaire signé par une autre clé. Il faudrait republier sous un nouvel
  identifiant et **abandonner tous les utilisateurs déjà installés**.
- **Divulgués** : n'importe qui peut publier une application qui se fait passer
  pour la nôtre.

Sauvegarde-les **chiffrés, hors de la machine de développement**, avant toute
autre chose. Le mot de passe est dans `key.properties` ; sauvegarde les deux
ensemble, ils ne servent qu'ensemble.

Empreinte du certificat, à vérifier après toute compilation :

```
SHA-256 : 2D:07:D5:39:16:21:5A:3D:C3:AE:7E:DA:57:0C:5C:F1:8F:7E:35:A6:39:5B:52:6D:54:37:A4:39:B1:C9:4A:C2
```

## Compiler

```bash
cd mobile/preuve_app

# Pour Google Play — c'est CE format qu'il faut déposer.
flutter build appbundle --release

# Pour une installation directe (test, distribution hors magasin).
flutter build apk --release --split-per-abi
```

**L'App Bundle, pas l'APK, pour le magasin.** Google n'y livre que
l'architecture de l'appareil : un APK universel pèse 106 Mo, dont 99 de
bibliothèques natives pour trois architectures, alors qu'un téléphone n'en
utilise qu'une. Sur un parc où la donnée et le stockage sont comptés, cela
décide de l'installation ou de son abandon.

| Artefact | Poids | Usage |
|---|---|---|
| `app-release.aab` | 76 Mo | dépôt Google Play |
| `app-arm64-v8a-release.apk` | 42 Mo | téléphones récents |
| `app-armeabi-v7a-release.apk` | 32 Mo | entrée de gamme, encore majoritaires |
| `app-x86_64-release.apk` | 45 Mo | **émulateurs seulement** — ne pas distribuer |
| `app-release.apk` | 106 Mo | universel — à éviter |

Poids inchangés en 1.2.2+5, relevés sur le disque. **Flutter annonce autre
chose** — « 44.1MB » pour l'arm64 : il compte en méga**octets décimaux**, le
Finder et ce tableau en mébioctets. Recopier le chiffre de la console ferait
croire à une inflation à chaque version.

Sur les 42 Mo d'`arm64`, **19 Mo sont les modèles ML Kit** embarqués : lecture de
texte (10,6 Mo) et détection de visage (8,1 Mo). C'est le prix de la lecture de
carte grise et de la séquence de vivacité **sur l'appareil** — donc sans que la
photo parte chez un tiers, et sans réseau.

## Vérifier avant de déposer

**Ne jamais déposer un binaire qu'on n'a pas vu démarrer.** Une compilation de
publication passe par R8, qui retire le code inutilisé : une application qui
fonctionne en débogage peut planter à l'ouverture une fois minifiée, et cela ne
se voit sur aucun test.

```bash
# 1. La signature est bien celle de BookMi, pas celle de débogage.
apksigner verify --print-certs build/app/outputs/flutter-apk/app-arm64-v8a-release.apk

# 2. L'identifiant et la version sont ceux attendus.
aapt2 dump badging build/app/outputs/flutter-apk/app-arm64-v8a-release.apk | head -1

# 3. ELLE DÉMARRE.
adb install build/app/outputs/flutter-apk/app-arm64-v8a-release.apk
adb shell monkey -p ci.bookmi.preuve -c android.intent.category.LAUNCHER 1
adb shell pidof ci.bookmi.preuve     # doit rendre un identifiant de processus
```

**Un émulateur plein fait échouer l'installation en silence** : `adb install`
imprime une trace d'erreur, puis « Performing Streamed Install » sans
« Success », et tout ce qui suit échoue avec des messages trompeurs — « Activity
class does not exist ». Vérifier `adb shell df /data` avant d'accuser l'APK.
Une heure a été perdue à cela le 06/08/2026.

**L'APK `x86_64` ne s'installe pas sur un émulateur `arm64`** — c'est le cas des
émulateurs sur Mac Apple Silicon. `INSTALL_FAILED_NO_MATCHING_ABIS` le dit sans
détour, mais on cherche d'abord ailleurs : vérifier
`adb shell getprop ro.product.cpu.abi` et prendre l'APK correspondant.

## Le compteur de version

`pubspec.yaml` porte `version: 1.2.2+5`. Le nombre après le `+` est le
`versionCode` Android : **Google Play refuse deux fois le même**. L'incrémenter
à chaque dépôt, sans exception.

Avec `--split-per-abi`, Flutter y ajoute un préfixe par architecture — `2001`
pour `arm64-v8a`, `1001` pour `armeabi-v7a`. C'est voulu : les deux APK d'une
même version doivent porter des `versionCode` distincts et ordonnés.

## Ce qui reste à faire avant le premier dépôt

- **Trancher le domaine définitif.** L'identifiant `ci.bookmi.preuve` a été
  choisi pour ne PAS dépendre du domaine : il survit au passage de
  `preuve.click` à `preuve.ci`. Mais il devient définitif au premier dépôt.
- **Comptes développeur** : Google Play (frais uniques) et Apple (abonnement
  annuel). Délai d'ouverture non nul, vérification d'identité de l'éditeur.
- **Fiches boutique** : description, captures, icône, classification de contenu,
  et **l'adresse de la politique de confidentialité** — <https://preuve.click/confidentialite>
  et <https://preuve.click/conditions> sont en ligne et indexables.
- **Déclaration de collecte de données** (Data safety chez Google, App Privacy
  chez Apple). Preuve collecte : numéro de téléphone, pièces d'identité, photos.
  Les décrire honnêtement — une déclaration inexacte fait retirer l'application.
- **iOS** : cible relevée à 15.5 pour ML Kit ; aucun appareil n'est perdu, mais
  un utilisateur resté sur iOS 13/14 devra mettre à jour son système. La
  compilation de publication iOS demande un certificat de distribution et un
  profil d'approvisionnement, qui n'existent pas encore.
- **Retirer `EXCLUDED_ARCHS` du Podfile** dès que Google publiera la tranche
  arm64-simulateur de MLImage. Cela ne concerne que le simulateur.
