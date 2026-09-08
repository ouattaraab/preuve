# Brief — Identité visuelle mobile PREUVE : icône, écran de démarrage, splash

Dépôt : `Preuve` · paquet Flutter : `mobile/preuve_app` · branche `feat/lot1-socle`
Cible : Android (minSdk 24) et iOS · `applicationId` / bundle : `ci.bookmi.preuve`
Version en cours : `1.3.1+7`

---

## 1. Ce que tu dois produire

1. **L'icône d'application** — Android (legacy + **adaptative**) et iOS (jeu complet `AppIcon.appiconset`).
2. **L'écran de démarrage natif** — Android (`launch_background`, clair **et** sombre, + API 31+) et iOS (`LaunchScreen.storyboard` + `LaunchImage.imageset`).
3. **Le raccord avec le premier écran Flutter**, pour que le passage du natif à l'application ne se voie pas.
4. **La mise à jour de `public/favicon.svg`** — le symbole change, la décision est prise (voir §8).

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

Un carré arrondi couleur encre, une **coche** orange à bouts ronds.

**LA COCHE EST ÉCARTÉE, ET CE N'EST PAS UNE QUESTION DE GOÛT — voir §8.** Ce qui reste acquis
de ce fichier : le **carré arrondi encre** (`rx 12` sur 64, soit 18,75 % du côté), l'**orange
`#D97706`**, les **bouts ronds** et l'**épaisseur généreuse** du tracé. C'est la matière ; le
signe, lui, change.

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

1. **Le raisonnement d'abord, en trois paragraphes maximum.** La direction est arrêtée (§8) :
   ce que j'attends ici, c'est le raisonnement de TON exécution — cadrage dans la zone sûre,
   proportion de la lettre et du point, tenue à 48 px — en termes d'usage (lisibilité à deux
   mètres, au soleil, parmi cinquante autres icônes), pas de goût. Si tu penses que la décision
   du §8 est mauvaise, dis-le **en une fois et avec ton argument**, puis exécute-la quand même :
   c'est une décision produit, pas une préférence.
2. **Le code**, fichier par fichier, avec les chemins exacts.
3. **Les commentaires dans le style du dépôt** : ils expliquent *pourquoi*, en français, en
   nommant ce qui casserait si la décision était prise autrement. Regarde `theme.dart` ou
   `widgets.dart` pour le ton — ce n'est pas une convention décorative, c'est la norme du projet.
4. **Un aperçu de l'icône aux tailles réelles** — 48 px et 192 px — et non seulement en grand.
   Une icône ne se juge pas à 512 px : elle se juge à la taille où on la cherche.
5. **Ce que tu n'as pas fait** et pourquoi, s'il reste quelque chose.

---

## 8. La décision de marque — prise, motivée, à exécuter

Cette section remplace une question qui était ouverte. Elle a été tranchée le 07/09/2026.

### La coche est écartée

**1. Elle annonce le mauvais verdict.** L'acte central du produit, c'est quelqu'un debout devant
une moto qui tape un numéro. La réponse est parfois « Actif », mais aussi **« Volé déclaré —
n'achetez pas ce bien »** en rouge, ou « En location — une vente est frauduleuse » en ambre. Une
coche affiche « tout va bien » avant même que la question soit posée — et elle l'affiche
précisément dans le cas où le produit sert le plus.

**2. Elle promet une certification que le produit refuse de vendre.** Le registre est
**déclaratif** : il enregistre ce que quelqu'un a déclaré, il n'authentifie pas. `.memory-bank/
productContext.md` le range explicitement dans « ce qu'on refuse de faire » — *« Se présenter
comme registre officiel ou délivrer un "titre" »* — et impose d'inspirer l'autorité d'un document
officiel **sans imiter un sceau ou un emblème d'État (risque juridique)**. Une coche dit
« vérifié par nous ». Le jour où une moto volée porte un enregistrement récent, l'écart entre le
signe et la réalité cesse d'être une question de design.

**3. Elle est introuvable.** La coche est la forme la plus répandue des magasins d'applications,
et le critère retenu ici est de se reconnaître parmi cinquante icônes, à deux mètres, au soleil.

### Ce qui la remplace

**Un monogramme « P. » — la lettre en Bricolage Grotesque ExtraBold, suivie du point orange, sur
le carré arrondi encre.**

- **C'est déjà la marque.** Le point orange est le seul élément que le site et l'application
  partagent, et le code dit de lui : *« Il ne s'omet pas. »* L'icône et le mot-marque deviennent
  le même objet, au lieu de deux signes sans rapport.
- **Il ne promet rien.** Une lettre n'affirme aucun verdict : le registre peut afficher « volé »
  sans que l'icône le contredise.
- **Il tient à 48 px.** L'aperture très fermée du Bricolage ExtraBold garde sa masse quand tout
  le reste se referme. Le contraste `#D97706` sur `#2B1D12` est de **5,1:1**, au-dessus des 3:1
  exigés pour un élément graphique.
- **Il ne coûte presque rien.** Un tracé vectoriel plutôt qu'un jeu de PNG multi-densités : le
  budget de 300 Kio du §5.3 reste intact.

**Repli autorisé, si le « P » te paraît plat** : le **point orange seul, surdimensionné**, dans le
carré encre. Une seule forme à très fort contraste — c'est ce qui se lit de plus loin. Dans ce
cas, dis pourquoi tu as écarté le monogramme.

### Ce qui est interdit

- **Le tampon et le sceau.** `productContext.md` mentionne encore trois directions en arbitrage
  (Tampon / Sceau / Feu Vert) : cette ligne est **périmée** — `docs/superpowers/specs/
  2026-08-01-preuve-mvp-design.md` acte que **DJASSA grand public fait foi**. Et un tampon ramène
  exactement l'imitation d'emblème officiel que le document interdit pour raison juridique.
- **Tout signe qui affirme un jugement** : coche, bouclier, cadenas, badge, sceau, pouce levé,
  feu vert. Le produit montre un état, il ne le garantit pas.
- **Perdre le point orange.** Il est obligatoire dans le symbole retenu, quel qu'il soit.

### Ce que la décision t'impose

`public/favicon.svg` change **dans le même lot** que les icônes mobiles, avec le même symbole.
Deux signes différents entre le site et l'application, c'est une marque qui n'existe pas.

---

## 9. Livré le 07/09/2026 — état d'exécution

Implémenté depuis `Identité PREUVE.dc.html` (projet Claude Design
`3d219624-d4e2-44b5-b548-436ca8be9d0b`). Binaire **1.4.0+8**, `versionCode`
1008 / 2008 / 4008, signé par le keystore habituel (`2d07d539…b1c94ac2`).

**TOUT DESCEND D'UN GÉNÉRATEUR**, `tools/marque_preuve.py`, qui lit les contours
réels du « P » et du point dans `BricolageGrotesque-ExtraBold.ttf`. Les huit
visages de la marque — favicon, avant-plan adaptatif, icône héritée aux cinq
densités, icône du démarrage Android 12+, marque du démarrage, jeu iOS complet,
image de démarrage iOS — sont donc la MÊME géométrie. Dessinés séparément, ils
auraient divergé à la première retouche. Aucun `<text>` : un fichier qui
nommerait la police dépendrait d'une police que l'appareil n'a pas.

| Fichier | État |
|---|---|
| `public/favicon.svg` | monogramme, remplace la coche |
| `docs/design/marque-preuve.svg` | source lisible, 192 px |
| `drawable/ic_launcher_foreground.xml` | avant-plan adaptatif, marque à 32 % |
| `mipmap-anydpi-v26/ic_launcher{,_round}.xml` | icône adaptative — **elle n'existait pas** |
| `mipmap-*/ic_launcher.png` | icône héritée, 48 → 192 px |
| `drawable/splash_icon.xml` | icône du démarrage Android 12+, canevas 288 dp |
| `drawable/marque_demarrage.xml` | marque du démarrage, ≤ Android 11 |
| `drawable{,-v21}/launch_background.xml` | crème + marque centrée |
| `values/colors.xml` | `preuve_creme`, `preuve_encre` |
| `values/styles.xml`, `values-night/styles.xml` | crème des deux côtés |
| `values-v31/styles.xml`, `values-night-v31/styles.xml` | écran de démarrage Android 12+ |
| `ios/…/LaunchScreen.storyboard` | fond crème, image 88 pt |
| `ios/…/AppIcon.appiconset/*.png` | jeu complet, sans couche alpha |
| `ios/…/LaunchImage.imageset/*.png` | 88 / 176 / 264 px |

### Trois écarts avec la maquette, et pourquoi

**1. La marque occupe 32 % du canevas adaptatif, non 53 %.** Le texte de la
maquette annonçait 53 %, sa géométrie disait 30 % (36 px de corps sur 96) — j'ai
suivi la géométrie. Le masque d'Android ne laisse voir que le carré central de
72 dp : à 48 % du canevas, la lettre paraîtrait une fois et demie trop grosse à
côté de l'icône héritée. 48 % × 48/72 = 32 % rend les deux optiquement
identiques, demi-diagonale de 22,7 dp contre 33 dp de zone sûre.

**2. L'image de démarrage iOS est en PNG, pas en PDF vectoriel.** Un PDF écrit à
la main ne se vérifie qu'en compilant sur un Mac avec Xcode, ce qui n'a pas pu
être fait ici : un fichier mal formé n'aurait échoué qu'au premier build iOS.
Les trois PNG pèsent 6,5 Kio au total — l'argument du poids ne tenait pas.

**3. Aucune section `assets:` n'a été ajoutée au `pubspec`.** Rien côté Flutter
n'a besoin d'une image : l'en-tête de marque est composé en texte à partir des
polices déjà embarquées, et l'écran de démarrage est entièrement natif. Ajouter
une ressource inutilisée aurait alourdi le binaire sans rien afficher.

**Le fond de `NormalTheme` est passé au crème**, en plus de la demande : il
valait `?android:colorBackground`, c'est-à-dire blanc, et transparaissait le
temps d'une image entre l'écran de démarrage et la première image de Flutter.

### Vérifié sur le binaire, pas sur l'intention

- `application-icon` de toutes les densités → un unique `res/BW.xml`, dont
  l'arbre XML est bien un `<adaptive-icon>` avec `background`, `foreground` et
  `monochrome` ;
- `style/LaunchTheme` porte une variante `(v31)` renvoyant à `preuve_creme` et
  `preuve_encre` ;
- cadrage mesuré sur les PNG rendus : largeur **47,9 %**, marges symétriques au
  pixel sur les deux axes, à 48 comme à 192 px ;
- **+49,5 Kio** de ressources embarquées, sur les 300 Kio autorisés ;
- 1020 Pest · 126 `preuve_core` · 70 `preuve_app` · `dart analyze` inchangé à
  4 « info » préexistantes · APK compilés et signés.

### Ce qui reste

Le fichier `Identité PREUVE.dc.html` propose deux réglages non retenus, tous
deux à leur valeur par défaut : `pointSeul` (le point orange seul, repli si le
monogramme paraît plat) et `sombreEncre` (raccord franc en encre au démarrage
sombre). Les rétablir ne demande qu'un changement de constante dans
`tools/marque_preuve.py` et une régénération.
