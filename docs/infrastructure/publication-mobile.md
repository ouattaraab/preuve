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

Poids inchangés en 1.4.1+9, relevés sur le disque. **Flutter annonce autre
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

`pubspec.yaml` porte `version: 1.4.1+9`. Le nombre après le `+` est le
`versionCode` Android : **Google Play refuse deux fois le même**. L'incrémenter
à chaque dépôt, sans exception.

Avec `--split-per-abi`, Flutter y ajoute un préfixe par architecture. En 1.4.1+9
cela donne `1009` pour `armeabi-v7a`, `2009` pour `arm64-v8a` et `4009` pour
`x86_64`. C'est voulu : les APK d'une même version doivent porter des
`versionCode` distincts et ordonnés.

**LA VERSION ANNONCÉE AU SERVEUR EST UNE AUTRE CHOSE**, et elle a divergé.
`main.dart` porte `versionInstallee`, envoyée en `X-App-Version` à chaque
écriture ; elle est restée à `0.1.0` de la 0.1 à la 1.3.0. Tout le parc
annonçait donc le même numéro périmé : poser une version minimale au-dessus de
`0.1.0` depuis la console n'aurait pas arrêté les vieilles applications, il les
aurait **toutes** arrêtées, la plus récente comprise. Depuis 1.3.1, un test
(`test/version_test.dart`) refuse tout écart avec le `pubspec`.

Conséquence pratique : les binaires **1.3.0 et antérieurs annoncent `0.1.0`**.
Une version minimale de `1.3.1` les bloquera bien en écriture — c'est le
comportement attendu — mais aucune valeur intermédiaire ne les distingue entre
eux.

## Le push n'existe pas côté client, et c'est une décision à prendre

Tout le nécessaire est en place **côté serveur** : transport FCM, table
`device_tokens`, `POST /api/v1/devices`, coût des SMS critiques tracé par type.
Rien ne l'appelle. L'application n'embarque ni `firebase_core` ni
`firebase_messaging`, ne s'annonce jamais, et **aucune notification ne part donc
vers un téléphone fermé**.

Ce n'est pas un oubli sans conséquence, mais ce n'est pas non plus un blocage :
le centre de notifications in-app fonctionne, et depuis le 06/08/2026 la cloche
de l'accueil porte enfin son point rouge, rafraîchi au retour en avant-plan.
Un utilisateur qui ouvre l'application apprend donc ce qui s'est passé.

**Ce que coûterait le vrai push, pour décider en connaissance de cause :**

- un **projet Firebase** et un `google-services.json` (Android) — sans ce
  fichier, la compilation échoue ;
- un certificat **APNs** côté Apple pour iOS ;
- deux dépendances de plus dans un binaire qui en compte cinq, chacune
  justifiée une à une dans `pubspec.yaml` — et Firebase pèse ;
- une déclaration de collecte de données à mettre à jour dans les fiches
  boutique : un jeton d'appareil est une donnée personnelle.

**Ce que cela apporterait :** une alerte de vol ou une cession en attente qui
atteint son destinataire dans la minute, sans qu'il ouvre l'application. Sur
une cession qui expire en sept jours, c'est le facteur qui décide entre une
vente enregistrée et une vente perdue.

**Tant que la décision n'est pas prise**, le code serveur reste en place : il
ne coûte rien à l'exécution, et le jour où un `google-services.json` existe,
seul le client est à écrire. L'écran `/admin/reglages` affiche le nombre
d'appareils enregistrés — zéro aujourd'hui, ce qui dit la vérité.

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
