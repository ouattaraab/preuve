# Brief — Identité visuelle mobile PREUVE : icône, écran de démarrage, splash

Dépôt : `Preuve` · paquet Flutter : `mobile/preuve_app` · branche `feat/lot1-socle`
Cible : Android (minSdk 24) et iOS · `applicationId` / bundle : `ci.bookmi.preuve`
Version en cours : `1.3.1+7`

---

## 1. Ce que tu dois produire

1. **L'icône d'application** — Android (legacy + **adaptative**) et iOS (jeu complet `AppIcon.appiconset`).
2. **L'écran de démarrage natif** — Android (`launch_background`, clair **et** sombre, + API 31+) et iOS (`LaunchScreen.storyboard` + `LaunchImage.imageset`).
3. **Le raccord avec le premier écran Flutter**, pour que le passage du natif à l'application ne se voie pas.
4. La mise à jour de `public/favicon.svg` **si et seulement si** le symbole change (voir §3).

Livre du code compilable dans le dépôt, pas des maquettes.

---

## 2. La règle qui prime sur toutes les autres

**L'identité de PREUVE existe déjà. Tu l'étends, tu ne la réinventes pas.**

Le produit est un registre déclaratif de propriété de biens en Côte d'Ivoire (motos, véhicules).
On s'en sert **debout, dans un marché ou sur un parking, en plein soleil, souvent d'une seule
main, sur un téléphone d'entrée de gamme**. Ce n'est pas une contrainte esthétique : c'est la
condition d'usage qui a produit toute la direction actuelle — traits de 3 px, ombres dures sans
flou, cibles de 64 px, police dessinée pour distinguer le 0 du O.

Une icône « propre et moderne » au dégradé doux serait un échec : elle disparaîtrait sur l'écran
où elle doit être trouvée.

---

## 3. La marque telle qu'elle existe aujourd'hui — relevée dans le code, pas supposée

### Le mot-marque
`mobile/preuve_app/lib/ui/widgets.dart` → `EnteteMarque` :

> **Preuve** suivi d'un **point orange**, en Bricolage Grotesque ExtraBold (w800),
> `letterSpacing: -0.02 × taille`.

Le commentaire du code dit : *« Le point orange après "Preuve" est la marque. Il ne s'omet pas. »*
Le web fait exactement pareil (`resources/views/public/layout.blade.php`, classes `.marque` / `.point`).

### Le symbole
`public/favicon.svg` — la seule forme graphique de marque qui existe :

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="12" fill="#2B1D12"/>
  <path d="M17 33.5 L27.5 44 L47 22" fill="none" stroke="#D97706" stroke-width="9"
        stroke-linecap="round" stroke-linejoin="round"/>
</svg>
```

Un carré arrondi couleur encre, une **coche** orange à bouts ronds. C'est le point de départ.

**Tu peux proposer de le faire évoluer** — il n'a jamais été travaillé pour une icône
d'application, et une coche seule est un signe très commun. Mais alors :
- expose le raisonnement (que gagne-t-on, que perd-on) ;
- **mets `public/favicon.svg` à jour dans le même lot.** Deux symboles différents entre le site
  et l'application, c'est une marque qui n'existe pas.

### Les jetons — `mobile/preuve_app/lib/ui/theme.dart`, classe `Djassa`

| Jeton | Valeur | Rôle |
|---|---|---|
| `creme` | `#FFF6E8` | fond de l'application |
| `sable` | `#E8DFCE` | bandeaux, zones en retrait |
| `encre` | `#2B1D12` | texte, contours, fonds sombres |
| `accent` | `#D97706` | orange de marque |
| `ambre` | `#FFB84D` | ambre clair, **uniquement sur fond encre** |
| `sourdine` | `#5A4632` | texte secondaire |
| `etiquette` | `#8A7358` | intitulés de section en capitales |
| `alerte` | `#C62F21` | refus, danger |

Géométrie : `rayon` 16, `rayonPanneau` 20, `trait` **3**, `cible` 64.
Relief : `BoxShadow(color: encre, offset: Offset(0, 4))` — **ombre pleine, décalée, sans flou**.
C'est la signature de la direction ; un flou gaussien la banaliserait.

Polices embarquées (`assets/fonts/`) : **Bricolage Grotesque** SemiBold 600 / ExtraBold 800
(titres) et **Atkinson Hyperlegible** Regular 400 / Bold 700 (lecture).

---

## 4. L'état de départ — tout est encore d'usine

Vérifié fichier par fichier :

| Élément | État actuel |
|---|---|
| `android/.../mipmap-*/ic_launcher.png` | **icône Flutter d'usine**, 48/72/96/144/192 px |
| `mipmap-anydpi-v26/` | **absent** → aucune icône adaptative (Android 8+ enferme donc l'icône dans un cercle blanc) |
| `drawable/launch_background.xml` | `@android:color/white` — blanc pur |
| `drawable-v21/launch_background.xml` | `?android:colorBackground` |
| `values-night/styles.xml` | `Theme.Black.NoTitleBar` → fond **noir** au démarrage en mode sombre |
| `ios/.../LaunchImage.imageset/*.png` | **images 1 × 1 pixel** (placeholders Flutter) |
| `ios/.../LaunchScreen.storyboard` | fond **blanc pur** codé en dur (`red=1 green=1 blue=1`) |
| `ios/.../AppIcon.appiconset/` | jeu Flutter d'usine complet |

Conséquence aujourd'hui : l'utilisateur voit **un écran blanc, puis un écran crème**. Le passage
se voit, et l'icône dans le tiroir d'applications est celle de Flutter.

En mode sombre, c'est pire : **noir → crème**.

Dépendances : ni `flutter_launcher_icons` ni `flutter_native_splash` ne sont installés.
Tu peux en ajouter un si tu le justifies — mais des fichiers écrits à la main sont acceptables,
et souvent plus lisibles ici.

**`pubspec.yaml` ne déclare AUCUNE section `assets:`** — seulement `fonts:`. Toute image ajoutée
côté Flutter exige de créer cette section.

---

## 5. Les contraintes de non-régression — à respecter à la lettre

### 5.1 Ne bloque JAMAIS le démarrage
`lib/main.dart` lance délibérément la reprise de session **sans l'attendre** :

```dart
unawaited(session.reprendre());
runApp(PreuveApp(session: session));
```

Le commentaire explique pourquoi : *« Attendre sa réponse ferait fixer un écran blanc à quelqu'un
qui veut seulement vérifier une moto au marché — dans un réseau 3G, plusieurs secondes. »*

**Un splash Flutter animé avec une durée minimale (`Future.delayed`, attente d'un chargement)
serait une régression contre une décision écrite.** L'exigence produit est CT-01 : résultat de
consultation en moins d'une seconde au P95. Si tu ajoutes une transition, elle doit être
**purement décorative, non bloquante, et interruptible** par le premier geste.

### 5.2 L'application ouvre sur la consultation, jamais sur un accueil
Règle métier absolue n° 1 : consulter est gratuit, anonyme, sans compte. Le code le dit :
*« Faire précéder cet écran d'un accueil, d'un tutoriel ou d'une invitation à s'inscrire
ajouterait la friction que CT-06 réserve aux gestes risqués. »*
**N'introduis ni onboarding, ni carrousel, ni écran de bienvenue.**

### 5.3 Le poids compte
CT-05 : l'application doit être utilisable en 3G, sur un parc où la donnée et le stockage sont
comptés. L'APK `armeabi-v7a` pèse 32,4 Mio, l'`arm64-v8a` 42,0 Mio.
**Budget : +300 Kio maximum, toutes ressources confondues.** Préfère un vecteur (`VectorDrawable`
Android, PDF ou SVG côté iOS) à un jeu de PNG multi-densités. Justifie tout dépassement.

### 5.4 Le mode sombre ne doit pas trahir la marque
`values-night/styles.xml` existe. L'application, elle, n'a **pas** de thème sombre : elle est
toujours crème. Décide explicitement — et écris-le en commentaire — si le démarrage sombre doit
rester sombre (raccord franc) ou passer en crème comme le reste (raccord invisible).
Ne laisse pas le noir d'usine par omission.

### 5.5 Android 12+ ignore ton `launch_background`
À partir de l'API 31, le système impose sa propre `SplashScreen` : `windowSplashScreenBackground`,
`windowSplashScreenAnimatedIcon`, et **il rogne l'icône dans un cercle** en n'en gardant que les
deux tiers centraux. Si tu ne traites que `values/styles.xml`, le rendu sera correct sur un vieux
téléphone et faux sur un neuf — le cas le plus difficile à voir.
**Traite explicitement `values-v31/styles.xml`.**

### 5.6 L'icône adaptative rogne, elle aussi
Zone sûre : **66 dp de diamètre sur 108 dp**. Un symbole cadré au plus juste sera amputé par les
masques ronds, en écusson (« squircle »), en goutte selon le constructeur.
Fournis `ic_launcher_background` + `ic_launcher_foreground` avec la marge correcte, et
`mipmap-anydpi-v26/ic_launcher.xml`.

### 5.7 Ne touche pas à la signature
`android/key.properties` et le keystore `preuve-release.jks` sont en place et **doivent le rester**
(empreinte SHA-256 `2D:07:D5:…:4A:C2`). Ne modifie ni `signingConfigs`, ni `applicationId`, ni le
`android:label` (`"Preuve"`).

### 5.8 Fais monter la version
Toute modification sous `mobile/` impose de faire monter `version:` dans `pubspec.yaml`
**et** `versionInstallee` dans `lib/main.dart`. Elles doivent rester identiques sur la partie
sémantique : un test (`test/version_test.dart`) le vérifie et échouera sinon.
Cible pour ce lot : `1.4.0+8`, `versionInstallee = '1.4.0'`.

---

## 6. Ce qui doit rester vert

Lance-les avant de rendre :

```bash
cd mobile/preuve_app && flutter test      # 70 tests
cd mobile/preuve_core && dart test        # 126 tests
cd mobile/preuve_app && dart analyze      # exactement 4 « info » préexistantes (RegExp deprecated), pas une de plus
```

Le rendu doit aussi compiler :

```bash
cd mobile/preuve_app && flutter build apk --release --split-per-abi
```

---

## 7. Ce que j'attends dans ta réponse

1. **Le raisonnement d'abord, en trois paragraphes maximum** : ce que tu gardes du symbole
   existant, ce que tu changes, et pourquoi — en termes d'usage (lisibilité à deux mètres, au
   soleil, parmi cinquante autres icônes), pas de goût.
2. **Le code**, fichier par fichier, avec les chemins exacts.
3. **Les commentaires dans le style du dépôt** : ils expliquent *pourquoi*, en français, en
   nommant ce qui casserait si la décision était prise autrement. Regarde `theme.dart` ou
   `widgets.dart` pour le ton — ce n'est pas une convention décorative, c'est la norme du projet.
4. **Un aperçu de l'icône aux tailles réelles** — 48 px et 192 px — et non seulement en grand.
   Une icône ne se juge pas à 512 px : elle se juge à la taille où on la cherche.
5. **Ce que tu n'as pas fait** et pourquoi, s'il reste quelque chose.

---

## 8. Deux questions auxquelles ta proposition doit répondre

- **La coche est-elle le bon signe ?** Elle dit « vérifié », ce qui est juste pour la consultation.
  Mais le produit dit aussi « ce bien a un détenteur », « ce bien est déclaré volé ». Une coche
  seule pourrait promettre une garantie que le registre ne donne pas : il est **déclaratif**, il
  n'authentifie pas. Tranche, et écris ta réponse.
- **Le point orange du mot-marque a-t-il sa place dans le symbole ?** C'est aujourd'hui le seul
  élément que le site et l'application partagent vraiment. Le perdre dans l'icône, c'est laisser
  la marque tenir sur deux signes sans rapport.
