import 'package:flutter/material.dart';

/// Direction DJASSA — les jetons de la maquette `docs/Preuve - Mobile.html`.
///
/// CHALEUREUSE, TUTOYANTE, LISIBLE À DEUX MÈTRES EN PLEIN SOLEIL. Cette
/// application se tient debout, dans un marché ou sur un parking, souvent d'une
/// seule main. Les tailles, les contrastes et les bordures épaisses ne sont donc
/// pas des préférences esthétiques : ce sont des conditions d'usage.
///
/// LES VALEURS SONT RECOPIÉES DE LA MAQUETTE, PAS RÉINVENTÉES. Une teinte
/// approchante ou un rayon « à peu près » se voit immédiatement quand on pose
/// les deux écrans côte à côte, et se paie en allers-retours.
///
/// LES COULEURS DE STATUT NE SONT PAS ICI. Elles viennent du serveur avec le
/// verdict (CT-04) : une table locale se périmerait au premier statut ajouté,
/// sur des téléphones qui ne se mettent pas à jour.
class Djassa {
  const Djassa._();

  /// Fond de l'application.
  static const Color creme = Color(0xFFFFF6E8);

  /// Fond de page, plus soutenu — bandeaux et zones en retrait.
  static const Color sable = Color(0xFFE8DFCE);

  static const Color encre = Color(0xFF2B1D12);
  static const Color accent = Color(0xFFD97706);

  /// Ambre clair, sur fond encre : le seul endroit où un chiffre doit ressortir.
  static const Color ambre = Color(0xFFFFB84D);

  static const Color sourdine = Color(0xFF5A4632);

  /// Intitulés de section, en capitales. Volontairement plus pâle que
  /// [sourdine] : ils nomment, ils ne se lisent pas.
  static const Color etiquette = Color(0xFF8A7358);

  static const Color alerte = Color(0xFFC62F21);

  /// Police d'affichage : titres, boutons, chiffres qui tranchent.
  static const String titre = 'Bricolage';

  /// Police de lecture, dessinée pour distinguer 0/O et 1/I — ce que l'on lit
  /// ici, ce sont des numéros de châssis de dix-sept caractères.
  static const String texte = 'Atkinson';

  /// Hauteur d'une cible principale. Plus généreuse que les 48 dp habituels :
  /// on saisit debout, parfois avec des gants de mécanicien.
  static const double cible = 64;

  /// Cible secondaire.
  static const double cibleSecondaire = 56;

  static const double rayon = 16;
  static const double rayonPanneau = 20;

  /// Épaisseur des contours. C'est la signature de la direction : un trait fin
  /// disparaît au soleil.
  static const double trait = 3;

  /// L'ombre DURE, décalée et sans flou, qui donne son relief à la maquette.
  ///
  /// Un flou gaussien rendrait un aspect « matériel » générique ; ce décalage
  /// net est ce qui fait reconnaître l'application d'un coup d'œil.
  static List<BoxShadow> relief([Color couleur = encre]) => <BoxShadow>[
        BoxShadow(color: couleur, offset: const Offset(0, 4)),
      ];

  /// Traduit une couleur rendue par le serveur (« #C62F21 ») en couleur Flutter.
  ///
  /// Une valeur inattendue retombe sur l'encre plutôt que de faire tomber
  /// l'écran : un verdict doit s'afficher même si le serveur a introduit une
  /// teinte que cette version ne connaît pas.
  static Color depuisServeur(String valeur) {
    final nettoyee = valeur.replaceFirst('#', '');

    if (nettoyee.length != 6) {
      return encre;
    }

    final entier = int.tryParse(nettoyee, radix: 16);

    return entier == null ? encre : Color(0xFF000000 | entier);
  }

  /// Une couleur lisible POSÉE SUR [fond].
  ///
  /// Le serveur décide de la couleur d'un statut, pas de ce qu'on écrit dessus.
  /// Choisir ici, à partir de la luminance, évite qu'un statut ajouté plus tard
  /// rende un verdict illisible — c'est-à-dire inutilisable au moment qui
  /// compte.
  static Color surFond(Color fond) =>
      fond.computeLuminance() > 0.55 ? encre : creme;

  static TextStyle affiche(double taille, {Color couleur = encre, double? hauteur}) {
    return TextStyle(
      fontFamily: titre,
      fontWeight: FontWeight.w800,
      fontSize: taille,
      height: hauteur,
      letterSpacing: -0.02 * taille,
      color: couleur,
    );
  }

  static ThemeData build() {
    final base = ThemeData.light(useMaterial3: true);

    return base.copyWith(
      scaffoldBackgroundColor: creme,
      colorScheme: base.colorScheme.copyWith(
        primary: encre,
        secondary: accent,
        surface: creme,
        error: alerte,
      ),
      // LA GÉOMÉTRIE EST FOURNIE ICI, ET C'EST OBLIGATOIRE.
      //
      // `ThemeData.textTheme` ne porte que les COULEURS : les tailles sont
      // fusionnées plus tard, par locale, à partir de la typographie. Y
      // appliquer un facteur d'agrandissement lève une assertion
      // (`fontSize != null || fontSizeFactor == 1.0`) et faisait planter
      // l'application au démarrage — sur tous les écrans, y compris la
      // consultation, qui ne doit jamais tomber.
      textTheme: _typographie(base).apply(
        bodyColor: encre,
        displayColor: encre,
        fontFamily: texte,
        // La basse littératie et la lecture en plein soleil coûtent plus qu'un
        // pouce d'écran. S'ajoute au réglage d'accessibilité du téléphone, il
        // ne le remplace pas.
        fontSizeFactor: 1.15,
      ),
      inputDecorationTheme: const InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: EdgeInsets.symmetric(horizontal: 18, vertical: 18),
        hintStyle: TextStyle(color: etiquette, fontWeight: FontWeight.w700),
        border: OutlineInputBorder(
          borderSide: BorderSide(color: encre, width: trait),
          borderRadius: BorderRadius.all(Radius.circular(rayon)),
        ),
        enabledBorder: OutlineInputBorder(
          borderSide: BorderSide(color: encre, width: trait),
          borderRadius: BorderRadius.all(Radius.circular(rayon)),
        ),
        focusedBorder: OutlineInputBorder(
          borderSide: BorderSide(color: accent, width: trait),
          borderRadius: BorderRadius.all(Radius.circular(rayon)),
        ),
        errorBorder: OutlineInputBorder(
          borderSide: BorderSide(color: alerte, width: trait),
          borderRadius: BorderRadius.all(Radius.circular(rayon)),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderSide: BorderSide(color: alerte, width: trait),
          borderRadius: BorderRadius.all(Radius.circular(rayon)),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: accent,
          foregroundColor: creme,
          minimumSize: const Size.fromHeight(cible),
          textStyle: const TextStyle(
            fontFamily: titre,
            fontSize: 22,
            fontWeight: FontWeight.w800,
          ),
          shape: const RoundedRectangleBorder(
            side: BorderSide(color: encre, width: trait),
            borderRadius: BorderRadius.all(Radius.circular(rayon)),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          backgroundColor: Colors.white,
          foregroundColor: encre,
          minimumSize: const Size.fromHeight(cibleSecondaire),
          textStyle: const TextStyle(
            fontFamily: titre,
            fontSize: 17,
            fontWeight: FontWeight.w800,
          ),
          side: const BorderSide(color: encre, width: trait),
          shape: const RoundedRectangleBorder(
            borderRadius: BorderRadius.all(Radius.circular(rayon)),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: encre,
          textStyle: const TextStyle(
            fontFamily: texte,
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }

  /// Un thème de texte COMPLET : géométrie (les tailles) et couleurs réunies.
  ///
  /// C'est ce que `ThemeData` fait lui-même au moment de bâtir l'écran, en
  /// fonction de la langue. Le faire ici nous permet d'agrandir les tailles —
  /// impossible sur un thème qui n'en porte aucune.
  static TextTheme _typographie(ThemeData base) {
    final typographie = Typography.material2021(platform: base.platform);

    return typographie.englishLike.merge(typographie.black);
  }
}
