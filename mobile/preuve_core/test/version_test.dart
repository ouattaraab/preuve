import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

/// Le forçage de mise à jour est décidé par le serveur (426). Ce code ne sert
/// qu'à afficher l'écran AU DÉMARRAGE, plutôt que de laisser l'utilisateur
/// saisir un bien pendant quatre-vingt-dix secondes pour se heurter au refus.
///
/// EN CAS DE DOUTE, ON LAISSE PASSER : refuser sur une chaîne qu'on n'a pas su
/// lire punirait l'utilisateur pour un défaut de la plateforme.
void main() {
  group('comparaison', () {
    test('reconnaît une version antérieure', () {
      expect(AppVersion.isOutdated(installed: '1.9.9', minimum: '2.0.0'), isTrue);
    });

    test('compare des nombres, pas des chaînes', () {
      // « 1.10.0 » est POSTÉRIEURE à « 1.9.0 » : une comparaison lexicale
      // conclurait l'inverse et mettrait hors service tout le parc à jour.
      expect(AppVersion.isOutdated(installed: '1.10.0', minimum: '1.9.0'), isFalse);
      expect(AppVersion.isOutdated(installed: '1.9.0', minimum: '1.10.0'), isTrue);
    });

    test('traite « 2.0 » et « 2.0.0 » comme la même version', () {
      expect(AppVersion.isOutdated(installed: '2.0', minimum: '2.0.0'), isFalse);
      expect(AppVersion.isOutdated(installed: '2.0.0', minimum: '2.0'), isFalse);
    });
  });

  group('ce qui ne doit jamais bloquer', () {
    test('une version illisible passe', () {
      expect(AppVersion.isOutdated(installed: '1.4-beta', minimum: '2.0.0'), isFalse);
    });

    test('une exigence absente ne bloque rien', () {
      expect(AppVersion.isOutdated(installed: '0.0.1'), isFalse);
      expect(const AppRelease().blocksWritesFor('0.0.1'), isFalse);
    });

    test('la consultation reste ouverte quelle que soit la version', () {
      // La règle métier absolue n° 1 ne dit pas « sauf si votre téléphone est
      // vieux ». La constante existe pour que ce soit lisible dans le code.
      expect(AppRelease.lookupAlwaysAvailable, isTrue);
    });
  });

  test('lit l\'état des versions rendu par le serveur', () {
    final release = AppRelease.fromJson(const <String, Object?>{
      'minimum_version': '1.4.0',
      'latest_version': '1.6.0',
      'update_required_for_writes': true,
      'lookup_always_available': true,
    });

    expect(release.minimumVersion, equals('1.4.0'));
    expect(release.blocksWritesFor('1.3.9'), isTrue);
    expect(release.blocksWritesFor('1.4.0'), isFalse);
  });
}
