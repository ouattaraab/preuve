import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

/// Le forçage de mise à jour est décidé par le serveur (426). Ce code ne sert
/// qu'à afficher l'écran AU DÉMARRAGE, plutôt que de laisser l'utilisateur
/// saisir un bien pendant quatre-vingt-dix secondes pour se heurter au refus.
///
/// EN CAS DE DOUTE, ON LAISSE PASSER : refuser sur une chaîne qu'on n'a pas su
/// lire punirait l'utilisateur pour un défaut de la plateforme.
void main() {
  _capacitesAnnoncees();

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

/// Capacités annoncées par `GET /config/app`.
///
/// PERSONNE NE LES LISAIT. La route existe depuis le 04/08, `release()` aussi ;
/// aucun écran ne les appelait. La plateforme pouvait exiger une mise à jour
/// sans qu'aucune application ne l'apprenne, et proposer un scan sans
/// fournisseur d'extraction branché.
void _capacitesAnnoncees() {
  group('AppRelease', () {
    test('lit la disponibilité du scan telle que le serveur la rend', () {
      final AppRelease a = AppRelease.fromJson(<String, Object?>{
        'minimum_version': null,
        'latest_version': null,
        'update_required_for_writes': false,
        'lookup_always_available': true,
        'scan_available': true,
      });

      expect(a.scanAvailable, isTrue);
    });

    test('PRUDENT quand le serveur ne dit rien', () {
      // Un serveur plus ancien ne connaît pas ce champ. Supposer que le scan
      // marche ferait prendre une photo, l'enverrait, et rendrait un échec que
      // l'utilisateur attribuerait à sa photo — il recommencerait.
      expect(AppRelease.fromJson(const <String, Object?>{}).scanAvailable, isFalse);
    });

    test('la consultation ne dépend d\'aucune annonce', () {
      expect(AppRelease.lookupAlwaysAvailable, isTrue);
    });
  });
}
