import 'package:flutter/material.dart';

/// Direction DJASSA — grand public.
///
/// CHALEUREUSE, TUTOYANTE, LISIBLE À DEUX MÈTRES EN PLEIN SOLEIL. Cette
/// application se tient debout, dans un marché ou sur un parking, souvent d'une
/// seule main. Les tailles et les contrastes ne sont donc pas des préférences
/// esthétiques : ce sont des conditions d'usage.
///
/// LES COULEURS DE STATUT NE SONT PAS ICI. Elles viennent du serveur avec le
/// verdict (CT-04) : une table locale se périmerait au premier statut ajouté,
/// sur des téléphones qui ne se mettent pas à jour.
class Djassa {
  const Djassa._();

  static const Color creme = Color(0xFFFAF6EE);
  static const Color encre = Color(0xFF2B1D12);
  static const Color accent = Color(0xFFD97706);
  static const Color sourdine = Color(0xFF5C4A33);
  static const Color alerte = Color(0xFFC62F21);

  /// Hauteur minimale d'une cible tactile. Plus généreuse que les 48 dp
  /// habituels : on saisit debout, parfois avec des gants de mécanicien.
  static const double cible = 56;

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
      textTheme: base.textTheme.apply(
        bodyColor: encre,
        displayColor: encre,
        // Une taille de base plus grande que le défaut : la basse littératie et
        // la lecture en plein soleil coûtent plus qu'un pouce d'écran.
        fontSizeFactor: 1.15,
      ),
      inputDecorationTheme: const InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: EdgeInsets.symmetric(horizontal: 18, vertical: 18),
        border: OutlineInputBorder(
          borderSide: BorderSide(color: encre, width: 3),
          borderRadius: BorderRadius.all(Radius.circular(12)),
        ),
        enabledBorder: OutlineInputBorder(
          borderSide: BorderSide(color: encre, width: 3),
          borderRadius: BorderRadius.all(Radius.circular(12)),
        ),
        focusedBorder: OutlineInputBorder(
          borderSide: BorderSide(color: accent, width: 3),
          borderRadius: BorderRadius.all(Radius.circular(12)),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: accent,
          foregroundColor: encre,
          minimumSize: const Size.fromHeight(cible),
          textStyle: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
          shape: const RoundedRectangleBorder(
            side: BorderSide(color: encre, width: 3),
            borderRadius: BorderRadius.all(Radius.circular(12)),
          ),
        ),
      ),
    );
  }
}
