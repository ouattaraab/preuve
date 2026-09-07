import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/main.dart';

/// LA VERSION ANNONCÉE AU SERVEUR EST CELLE DU BINAIRE.
///
/// LE DÉFAUT QUE CE TEST FERME. `versionInstallee` portait un commentaire
/// demandant de la tenir à jour avec le `pubspec` ; elle est restée à `0.1.0`
/// pendant que le binaire passait en 1.3.x. Un commentaire ne tient pas une
/// invariant — un test si.
///
/// CE QUE CET ÉCART COÛTAIT. Toutes les applications du parc annonçaient le même
/// numéro périmé, quelle que soit leur version réelle. Poser une version
/// minimale au-dessus de `0.1.0` depuis la console n'aurait donc pas arrêté les
/// vieilles applications : il les aurait TOUTES arrêtées d'un coup, la plus
/// récente comprise. Le levier prévu pour retirer une version dangereuse
/// n'aurait servi qu'à couper les écritures de tout le monde — et le seul moyen
/// de s'en apercevoir aurait été de le tirer.
///
/// LE FORÇAGE NE TOUCHE QUE LES ÉCRITURES : la consultation d'un identifiant
/// reste gratuite, anonyme et sans condition, y compris depuis un vieux
/// téléphone (règle métier absolue n° 1).
void main() {
  test('LA CONSTANTE ANNONCÉE SUIT LE PUBSPEC', () {
    final String ligne = File('pubspec.yaml').readAsLinesSync().firstWhere(
          (String l) => l.startsWith('version:'),
          orElse: () => '',
        );

    // `version: 1.3.1+7` → on ne retient que la partie sémantique : le numéro
    // de build est propre aux magasins et n'a aucun sens pour le serveur, qui
    // compare des versions entre elles.
    final String declaree = ligne.split(':').last.trim().split('+').first;

    expect(declaree, isNotEmpty, reason: 'Le pubspec doit porter une version lisible.');
    expect(
      versionInstallee,
      equals(declaree),
      reason: 'Le serveur compare CETTE constante à la version minimale exigée. '
          'Un écart retourne le forçage de mise à jour contre tout le parc.',
    );
  });
}
