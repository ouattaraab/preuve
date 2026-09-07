#!/usr/bin/env python3
"""Génère tous les visages de la marque PREUVE à partir d'UNE seule source.

POURQUOI UN GÉNÉRATEUR PLUTÔT QUE DES FICHIERS DESSINÉS À LA MAIN.

Le symbole doit être identique sur le site, sur l'icône Android, sur l'icône
iOS et sur l'écran de démarrage. Dessinés séparément, ces quatre visages
divergent au premier retouche — et une marque qui n'est pas exactement la même
d'un support à l'autre n'est pas une marque. Ici, tout descend des contours
réels du glyphe « P » et du point de `BricolageGrotesque-ExtraBold.ttf`, la
police déjà embarquée dans l'application : la géométrie ne peut pas dériver.

CE N'EST PAS UN `<text>` SVG. Un fichier qui nommerait la police dépendrait
d'une police que l'appareil n'a pas : le navigateur, le lanceur Android ou iOS
substituerait un autre dessin, et le mot-marque cesserait d'être une marque.
Les contours sont donc convertis en tracés une fois pour toutes.

Sorties :
    public/favicon.svg                              le site
    docs/design/marque-preuve.svg                   la source lisible, 192 px
    android/…/drawable/ic_launcher_foreground.xml   avant-plan adaptatif
    android/…/drawable/marque_demarrage.xml         marque du démarrage (≤ Android 11)
    android/…/drawable/splash_icon.xml              icône du démarrage (Android 12+)
    android/…/mipmap-*/ic_launcher.png              icône héritée (Android < 8)
    ios/…/AppIcon.appiconset/*.png                  jeu complet iOS
    ios/…/LaunchImage.imageset/*.png                marque de démarrage iOS

Usage : python3 tools/marque_preuve.py
"""

from __future__ import annotations

import json
import os
import sys

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image, ImageDraw, ImageFont

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
POLICE = os.path.join(RACINE, 'mobile/preuve_app/assets/fonts/BricolageGrotesque-ExtraBold.ttf')
ANDROID = os.path.join(RACINE, 'mobile/preuve_app/android/app/src/main/res')
IOS = os.path.join(RACINE, 'mobile/preuve_app/ios/Runner/Assets.xcassets')

# Les jetons DJASSA, recopiés de `mobile/preuve_app/lib/ui/theme.dart`.
ENCRE = '#2B1D12'
CREME = '#FFF6E8'
ACCENT = '#D97706'

# Proportions arrêtées dans « Identité PREUVE.dc.html ».
#
# LARGEUR_CARRE : la marque occupe 48 % du côté d'une icône carrée. C'est la
# proportion de la maquette (116 px de corps sur un canevas de 192), et elle y
# est constante d'une vignette à l'autre.
LARGEUR_CARRE = 0.48

# LARGEUR_ADAPTATIVE : 32 % du canevas de 108 dp, et non 48 %.
#
# Le masque d'Android ne laisse voir que le carré central de 72 dp : une marque
# à 48 % du CANEVAS y paraîtrait une fois et demie trop grosse à côté de
# l'icône héritée. 48 % × 48/72 = 32 % rend les deux optiquement identiques.
# La demi-diagonale vaut alors 22,7 dp, très en deçà des 33 dp de la zone sûre —
# aucun masque constructeur (rond, écusson, goutte) ne peut amputer la lettre.
LARGEUR_ADAPTATIVE = 0.32

# Rayon des angles : 18,75 % du côté. C'est `rx 12` sur les 64 de l'ancien
# favicon, et les 36 sur 192 de la maquette. La même valeur des deux côtés.
RAYON = 0.1875

# Chasse resserrée, comme le mot-marque : `letterSpacing: -0.02 × taille`
# dans `Djassa.affiche()`.
CHASSE = -0.02


def metriques() -> dict:
    """Les contours et les avances, lus dans la police elle-même."""
    police = TTFont(POLICE)
    upm = police['head'].unitsPerEm
    glyphes = police.getGlyphSet()
    cmap = police.getBestCmap()

    releve = {}
    for caractere in 'P.':
        nom = cmap[ord(caractere)]
        releve[caractere] = {'nom': nom, 'avance': glyphes[nom].width}

    # Position du point : après l'avance du P, resserrée par la chasse.
    origine_point = releve['P']['avance'] + CHASSE * upm

    return {
        'upm': upm,
        'glyphes': glyphes,
        'noms': {c: releve[c]['nom'] for c in releve},
        'origine_point': origine_point,
        # Encombrement de la marque composée, en unités de la police. Relevé
        # une fois ici et jamais réécrit à la main : ces quatre nombres
        # commandent tous les cadrages.
        'x_min': 69,
        'x_max': origine_point + 230,
        'y_min': -13,
        'y_max': 660,
    }


def trace(m: dict, caractere: str, echelle: float, dx: float, dy: float) -> str:
    """Le contour d'un glyphe en données de tracé, mis à l'échelle et placé.

    L'axe Y de la police monte, celui du SVG et du VectorDrawable descend :
    l'échelle verticale est donc négative. L'oublier retourne la lettre, ce qui
    se voit — mais seulement après avoir recompilé.
    """
    stylo = SVGPathPen(m['glyphes'])
    transforme = TransformPen(stylo, (echelle, 0, 0, -echelle, dx, dy))
    m['glyphes'][m['noms'][caractere]].draw(transforme)

    return stylo.getCommands()


def cadrage(m: dict, cote: float, largeur_relative: float) -> tuple[float, float, float]:
    """Échelle et origine pour centrer la marque sur un canevas carré.

    LE CENTRAGE PORTE SUR L'ENCRE, pas sur la ligne d'écriture. Centrer sur la
    ligne de base laisserait la marque flotter vers le haut : le point descend
    sous la ligne, et le P n'a pas de jambage. C'est ce que la maquette
    corrigeait par un `translateY(-5px)` — ici, il n'y a rien à corriger.
    """
    largeur = m['x_max'] - m['x_min']
    echelle = cote * largeur_relative / largeur

    dx = cote / 2 - (m['x_min'] + m['x_max']) / 2 * echelle
    dy = cote / 2 + (m['y_min'] + m['y_max']) / 2 * echelle

    return echelle, dx, dy


def marque_svg(m: dict, cote: int, largeur_relative: float = LARGEUR_CARRE) -> tuple[str, str]:
    """Les deux tracés — le P et le point — pour un canevas de `cote`."""
    echelle, dx, dy = cadrage(m, cote, largeur_relative)

    return (
        trace(m, 'P', echelle, dx, dy),
        trace(m, '.', echelle, dx + m['origine_point'] * echelle, dy),
    )


def ecrire(chemin: str, contenu: str) -> None:
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    with open(chemin, 'w', encoding='utf-8') as fichier:
        fichier.write(contenu)
    print(f'  {os.path.relpath(chemin, RACINE)}')


# ---------------------------------------------------------------- vectoriels


def favicon(m: dict) -> None:
    """Le symbole du site. Même fichier, même nom : les caches suivent."""
    p, point = marque_svg(m, 64)

    ecrire(os.path.join(RACINE, 'public/favicon.svg'), f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <!-- LE MONOGRAMME « P. », ET NON UNE COCHE. Le registre est déclaratif : il
       enregistre ce que quelqu'un a déclaré, il n'authentifie pas. Une coche
       promettrait « vérifié » sur un bien qui peut être déclaré volé.
       Décision du 07/09/2026, motivée dans
       docs/design/brief-identite-visuelle-mobile.md §8.
       Tracés issus de BricolageGrotesque-ExtraBold : un <text> dépendrait
       d'une police que le visiteur n'a pas. -->
  <rect width="64" height="64" rx="12" fill="{ENCRE}"/>
  <path d="{p}" fill="{CREME}"/>
  <path d="{point}" fill="{ACCENT}"/>
</svg>
''')


def source_lisible(m: dict) -> None:
    """La marque seule, sans fond : la source dont tout le reste descend."""
    p, point = marque_svg(m, 192)

    ecrire(os.path.join(RACINE, 'docs/design/marque-preuve.svg'), f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192 192">
  <!-- Généré par tools/marque_preuve.py — ne pas retoucher à la main.
       Toute correction se fait dans le générateur, sinon les huit visages de
       la marque divergent. -->
  <rect width="192" height="192" rx="36" fill="{ENCRE}"/>
  <path d="{p}" fill="{CREME}"/>
  <path d="{point}" fill="{ACCENT}"/>
</svg>
''')


def vectordrawable(m: dict) -> None:
    """Avant-plan de l'icône adaptative, et marque de l'écran de démarrage."""
    # Avant-plan : canevas de 108 dp, marque à 32 % (voir LARGEUR_ADAPTATIVE).
    p, point = marque_svg(m, 108, LARGEUR_ADAPTATIVE)

    ecrire(os.path.join(ANDROID, 'drawable/ic_launcher_foreground.xml'), f'''<?xml version="1.0" encoding="utf-8"?>
<!--
    Avant-plan de l'icône adaptative (Android 8+).

    LA MARQUE OCCUPE 32 % DU CANEVAS, ET NON 48 % comme sur une icône carrée.
    Le masque du lanceur ne laisse voir que le carré central de 72 dp : à 48 %
    du canevas, la lettre paraîtrait une fois et demie trop grosse à côté de
    l'icône héritée. Sa demi-diagonale vaut ici 22,7 dp, très en deçà des
    33 dp de la zone sûre — aucun masque (rond, écusson, goutte) ne l'ampute.

    Généré par tools/marque_preuve.py — ne pas retoucher à la main.
-->
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="108dp"
    android:height="108dp"
    android:viewportWidth="108"
    android:viewportHeight="108">
    <path android:fillColor="{CREME}" android:pathData="{p}"/>
    <path android:fillColor="{ACCENT}" android:pathData="{point}"/>
</vector>
''')

    # Icône du démarrage Android 12+, aux règles bien à elle.
    #
    # LE SYSTÈME IMPOSE SA GÉOMÉTRIE : canevas de 288 dp, dont il ne montre
    # qu'un disque. Avec une couleur de fond d'icône déclarée — la nôtre, encre
    # — ce disque tombe à 160 dp. La marque y occupe 30 % du diamètre, la même
    # proportion que dans le masque rond de l'icône adaptative : le démarrage
    # et le lanceur montrent alors exactement le même objet.
    #
    # ELLE PORTE LA MARQUE SEULE, SANS FOND. Le fond encre vient de
    # `windowSplashScreenIconBackgroundColor` : le dessiner ici aussi
    # afficherait un carré dans un disque.
    p, point = marque_svg(m, 288, 48 / 288)

    ecrire(os.path.join(ANDROID, 'drawable/splash_icon.xml'), f'''<?xml version="1.0" encoding="utf-8"?>
<!--
    Icône de l'écran de démarrage d'Android 12 et suivants.

    À PARTIR DE L'API 31, LE SYSTÈME IGNORE `launch_background`. Il impose son
    propre écran : une couleur de fond, un disque coloré, et cette icône
    rognée au centre. Ne corriger que `values/styles.xml` donnerait un
    démarrage juste sur un vieux téléphone et faux sur un neuf — le cas le plus
    difficile à voir.

    Canevas de 288 dp imposé ; avec une couleur de fond d'icône déclarée, seul
    un disque de 160 dp reste visible. La marque y tient à 30 % du diamètre,
    demi-diagonale de 31 dp contre 80 dp de rayon.

    Généré par tools/marque_preuve.py — ne pas retoucher à la main.
-->
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="288dp"
    android:height="288dp"
    android:viewportWidth="288"
    android:viewportHeight="288">
    <path android:fillColor="{CREME}" android:pathData="{p}"/>
    <path android:fillColor="{ACCENT}" android:pathData="{point}"/>
</vector>
''')

    # Marque de démarrage : le carré arrondi complet, posé sur le fond crème.
    p, point = marque_svg(m, 88)
    rayon = 88 * RAYON

    ecrire(os.path.join(ANDROID, 'drawable/marque_demarrage.xml'), f'''<?xml version="1.0" encoding="utf-8"?>
<!--
    La marque de l'écran de démarrage (Android 11 et antérieurs).

    ELLE PORTE SON PROPRE FOND ENCRE : l'écran de démarrage est crème, et une
    lettre crème sur du crème ne se verrait pas. C'est le même carré arrondi
    que l'icône, au même rayon de 18,75 %.

    Généré par tools/marque_preuve.py — ne pas retoucher à la main.
-->
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="88dp"
    android:height="88dp"
    android:viewportWidth="88"
    android:viewportHeight="88">
    <path android:fillColor="{ENCRE}"
        android:pathData="M{rayon:.3f},0 L{88 - rayon:.3f},0 A{rayon:.3f},{rayon:.3f} 0 0 1 88,{rayon:.3f} L88,{88 - rayon:.3f} A{rayon:.3f},{rayon:.3f} 0 0 1 {88 - rayon:.3f},88 L{rayon:.3f},88 A{rayon:.3f},{rayon:.3f} 0 0 1 0,{88 - rayon:.3f} L0,{rayon:.3f} A{rayon:.3f},{rayon:.3f} 0 0 1 {rayon:.3f},0 Z"/>
    <path android:fillColor="{CREME}" android:pathData="{p}"/>
    <path android:fillColor="{ACCENT}" android:pathData="{point}"/>
</vector>
''')


# ------------------------------------------------------------------ rasters


def teinte(valeur: str) -> tuple[int, int, int, int]:
    valeur = valeur.lstrip('#')

    return (int(valeur[0:2], 16), int(valeur[2:4], 16), int(valeur[4:6], 16), 255)


def rendu(m: dict, cote: int, *, arrondi: bool, fond: str = ENCRE, sursouille: int = 4) -> Image.Image:
    """Un carré de `cote` px portant la marque.

    LE SUR-ÉCHANTILLONNAGE N'EST PAS UN LUXE. Une icône de 48 px dessinée
    directement à 48 px porte des angles arrondis en escalier, visibles là où
    elle est réellement regardée. On dessine à quatre fois la taille, puis on
    réduit.
    """
    grand = cote * sursouille
    image = Image.new('RGBA', (grand, grand), (0, 0, 0, 0))
    dessin = ImageDraw.Draw(image)

    if arrondi:
        dessin.rounded_rectangle([0, 0, grand - 1, grand - 1], radius=grand * RAYON, fill=teinte(fond))
    else:
        # iOS APPLIQUE SON PROPRE MASQUE, et refuse la transparence : l'icône
        # doit être un carré plein, bord à bord. Y ajouter nos propres angles
        # laisserait un liseré sombre à l'intérieur de l'arrondi du système.
        dessin.rectangle([0, 0, grand - 1, grand - 1], fill=teinte(fond))

    echelle, dx, dy = cadrage(m, grand, LARGEUR_CARRE)
    corps = echelle * m['upm']
    police = ImageFont.truetype(POLICE, int(round(corps)))

    # Même cadrage que les tracés vectoriels : `dx`/`dy` sont l'origine et la
    # ligne de base, et l'ancrage « ls » les prend telles quelles.
    dessin.text((dx, dy), 'P', font=police, fill=teinte(CREME), anchor='ls')
    dessin.text((dx + m['origine_point'] * echelle, dy), '.', font=police, fill=teinte(ACCENT), anchor='ls')

    return image.resize((cote, cote), Image.LANCZOS)


def icones_android(m: dict) -> None:
    """Icône héritée : Android 7 et antérieurs ne connaissent pas l'adaptative."""
    for dossier, cote in (
        ('mipmap-mdpi', 48), ('mipmap-hdpi', 72), ('mipmap-xhdpi', 96),
        ('mipmap-xxhdpi', 144), ('mipmap-xxxhdpi', 192),
    ):
        chemin = os.path.join(ANDROID, dossier, 'ic_launcher.png')
        os.makedirs(os.path.dirname(chemin), exist_ok=True)
        rendu(m, cote, arrondi=True).save(chemin, optimize=True)
        print(f'  {os.path.relpath(chemin, RACINE)}  {cote}×{cote}')


def icones_ios(m: dict) -> None:
    """Jeu complet iOS, aux tailles que réclame `Contents.json`."""
    dossier = os.path.join(IOS, 'AppIcon.appiconset')

    with open(os.path.join(dossier, 'Contents.json'), encoding='utf-8') as fichier:
        contenu = json.load(fichier)

    for image in contenu['images']:
        taille = float(image['size'].split('x')[0])
        facteur = float(image['scale'].rstrip('x'))
        cote = int(round(taille * facteur))
        chemin = os.path.join(dossier, image['filename'])
        # SANS COUCHE ALPHA : l'App Store refuse une icône transparente, et le
        # rejet n'arrive qu'au dépôt — des semaines après la décision.
        rendu(m, cote, arrondi=False).convert('RGB').save(chemin, optimize=True)
        print(f'  {os.path.relpath(chemin, RACINE)}  {cote}×{cote}')


def demarrage_ios(m: dict) -> None:
    """La marque du storyboard iOS, aux trois densités."""
    dossier = os.path.join(IOS, 'LaunchImage.imageset')

    for nom, facteur in (('LaunchImage.png', 1), ('LaunchImage@2x.png', 2), ('LaunchImage@3x.png', 3)):
        chemin = os.path.join(dossier, nom)
        # 88 points de côté, comme la marque de démarrage Android : le même
        # objet à la même taille sur les deux plateformes.
        rendu(m, 88 * facteur, arrondi=True).save(chemin, optimize=True)
        print(f'  {os.path.relpath(chemin, RACINE)}  {88 * facteur}×{88 * facteur}')


def main() -> int:
    if not os.path.exists(POLICE):
        print(f'Police introuvable : {POLICE}', file=sys.stderr)

        return 1

    m = metriques()

    print('Vectoriels')
    favicon(m)
    source_lisible(m)
    vectordrawable(m)

    print('Android — icône héritée')
    icones_android(m)

    print('iOS — icône')
    icones_ios(m)

    print('iOS — démarrage')
    demarrage_ios(m)

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
