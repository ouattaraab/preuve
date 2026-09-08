import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Scan de carte grise (ST-0202), pour enregistrer ET pour consulter.
///
/// LES CHARGES UTILES SONT CELLES DU SERVEUR, relevées sur le contrôleur et non
/// devinées : `scan_id`, `identifier`, `attributes`, `confidence`.
void main() {
  MultipartFile carte() => const MultipartFile(
        field: 'file',
        filename: 'carte-grise.jpg',
        bytes: <int>[1, 2, 3],
        contentType: 'image/jpeg',
      );

  Map<String, Object?> lu() => <String, Object?>{
        'scan_id': 12,
        'identifier': <String, Object?>{'value': '1M8GDM9AXKP042788', 'type': 'vin'},
        'attributes': <String, Object?>{'marque': 'Yamaha'},
        'confidence': 92,
        'rate_limited': false,
        'message': 'Vérifiez l\'identifiant lu avant de valider.',
      };

  test('le scan d\'enregistrement passe par la route authentifiée', () async {
    final transport = FakeTransport()..enfile(lu());

    final ScanResult r = await ScanService(transport).read(carte());

    expect(transport.appels.single, equals('POST(multipart) /assets/scan'));
    expect(transport.dernierEnvoiAnonyme, isFalse);
    expect(r.identifier, equals('1M8GDM9AXKP042788'));
    expect(r.scanId, equals(12));
  });

  test('LE SCAN DE CONSULTATION NE PORTE JAMAIS LE JETON', () async {
    // Même quand une session est ouverte. Y joindre le jeton ferait porter au
    // registre la trace de QUI a photographié quelle carte grise, sur le
    // parcours dont l'anonymat est justement la promesse (règle n° 1).
    final transport = FakeTransport()..enfile(lu());

    await ScanService(transport).readForLookup(carte());

    expect(transport.appels.single, equals('POST(multipart) /lookup/scan'));
    expect(transport.dernierEnvoiAnonyme, isTrue);
  });

  test('présente le défi seulement quand on en a un', () async {
    final transport = FakeTransport()..enfile(lu());

    await ScanService(transport).readForLookup(carte(), captchaToken: '0x4AAA');

    expect(transport.dernierCorps['captcha_token'], equals('0x4AAA'));

    final propre = FakeTransport()..enfile(lu());
    await ScanService(propre).readForLookup(carte());

    // Un jeton vide envoyé sur le chemin nominal brûlerait un défi à usage
    // unique que personne n'a demandé.
    expect(propre.dernierCorps.containsKey('captcha_token'), isFalse);
  });

  test('LES MOTS PARTENT SANS JETON, et sans image', () async {
    // Toute la raison d'être du chemin embarqué : la photo d'une carte grise
    // porte le nom et l'adresse de son propriétaire. Ne partent que des mots —
    // et sans session, sur un parcours dont l'anonymat est la promesse.
    final transport = FakeTransport()..enfile(lu());

    await ScanService(transport).readWordsForLookup(<String>['1M8GDM9AXKP042788', 'YAMAHA']);

    expect(transport.appels.single, equals('POST(anonyme) /lookup/scan/text'));
    expect(transport.dernierCorps['words'], equals(<String>['1M8GDM9AXKP042788', 'YAMAHA']));
    // Aucun fichier n'a été joint : c'est ce qui distingue ce chemin.
    expect(transport.fichiersEnvoyes, isEmpty);
  });

  test('le chemin d\'enregistrement, lui, porte la session', () async {
    // La trace y est rattachée au compte : c'est elle qui mesure si le
    // pré-remplissage tient jusqu'à la soumission.
    final transport = FakeTransport()..enfile(lu());

    await ScanService(transport).readWords(<String>['1M8GDM9AXKP042788']);

    expect(transport.appels.single, equals('POST /assets/scan/text'));
  });

  test('NE PROPOSE RIEN plutôt qu\'une valeur approchée', () async {
    // Un identifiant mal lu est pire qu'un identifiant non lu : personne ne
    // recompte dix-sept caractères, et l'erreur ne se découvre qu'au moment où
    // le bien compte.
    final transport = FakeTransport()
      ..enfile(<String, Object?>{
        'scan_id': 13,
        'identifier': null,
        'attributes': <String, Object?>{},
        'confidence': null,
        'message': 'Le document n\'a pas pu être lu.',
      });

    final ScanResult r = await ScanService(transport).readForLookup(carte());

    expect(r.identifier, isNull);
    expect(r.hasIdentifier, isFalse);
  });
}
