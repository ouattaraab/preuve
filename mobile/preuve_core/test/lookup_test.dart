import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Le verdict a TROIS issues qu'il ne faut jamais confondre à l'écran. Un
/// identifiant inconnu n'est ni un bon ni un mauvais signe : le présenter
/// comme rassurant ferait acheter un bien volé que personne n'a déclaré.
void main() {
  _cleDuDefi();

  group('verdict', () {
    test('lit un bien connu et son statut en langage courant', () {
      final result = LookupResult.fromJson(const <String, Object?>{
        'verdict': 'known',
        'found': true,
        'message': 'Volé déclaré — n\'achetez pas ce bien.',
        'asset': <String, Object?>{
          'public_ref': 'PRV-2H4K9MNP',
          'category': 'voiture',
          'life_status': <String, Object?>{
            'code': 'V-VOL',
            'label': 'Volé déclaré',
            'message': 'Volé déclaré — n\'achetez pas ce bien.',
            'color': '#C62F21',
            'warning': true,
          },
          'trust_level': <String, Object?>{
            'code': 'F1',
            'label': 'Déclaré',
            'message': '',
            'color': '#5C6470',
            'warning': false,
          },
          'registered_at': '2026-05-02T10:00:00+00:00',
        },
      });

      expect(result.isKnown, isTrue);
      expect(result.isWarning, isTrue);
      // CT-04 : c'est le LIBELLÉ qui s'affiche, jamais le code.
      expect(result.asset!.lifeStatus.label, equals('Volé déclaré'));
      expect(result.asset!.lifeStatus.code, equals('V-VOL'));
    });

    test('un bien inconnu n\'alerte pas et ne rassure pas', () {
      final result = LookupResult.fromJson(const <String, Object?>{
        'verdict': 'unknown',
        'found': false,
        'message': 'Ce bien n\'est pas enregistré sur PREUVE.',
        'asset': null,
      });

      expect(result.outcome, equals(LookupOutcome.unknown));
      expect(result.isKnown, isFalse);
      // Pas d'alerte : il n'y a rien à alerter. Mais l'écran ne doit pas pour
      // autant afficher un feu vert — c'est le message qui porte la nuance.
      expect(result.isWarning, isFalse);
      expect(result.message, contains('pas enregistré'));
    });

    test('la couleur vient du serveur, pas d\'une table locale', () {
      // Une correspondance embarquée se périmerait au premier statut ajouté,
      // sur des téléphones qui ne se mettent pas à jour.
      final status = StatusView.fromJson(const <String, Object?>{
        'code': 'V-LOC',
        'label': 'Bien de location',
        'message': 'Bien de location — une vente est frauduleuse.',
        'color': '#C77700',
        'warning': true,
      });

      expect(status.color, equals('#C77700'));
      expect(status.warning, isTrue);
    });

    test('survit à une charge utile incomplète', () {
      // Une version future du serveur peut ajouter des champs ; elle ne doit
      // pas faire tomber une application déjà installée.
      final result = LookupResult.fromJson(const <String, Object?>{'verdict': 'known'});

      expect(result.outcome, equals(LookupOutcome.known));
      expect(result.asset, isNull);
      expect(result.isKnown, isFalse);
    });
  });

  test('le lien de partage ne porte que la référence opaque', () {
    final asset = PublicAsset.fromJson(const <String, Object?>{
      'public_ref': 'PRV-2H4K9MNP',
      'category': 'moto',
      'life_status': <String, Object?>{},
      'trust_level': <String, Object?>{},
      'registered_at': '2026-05-02T10:00:00+00:00',
    });

    final uri = asset.shareUri('https://preuve.click');

    expect(uri.toString(), equals('https://preuve.click/b/PRV-2H4K9MNP'));
  });
}

/// La clé du défi ne doit pas se perdre entre le serveur et l'écran.
///
/// `check()` avale le refus 429 pour le rendre comme un VERDICT — c'est juste :
/// un plafond atteint n'est pas une panne. Mais il jetait la clé du défi au
/// passage, et l'écran affichait « PATIENTE » sans issue alors que le serveur
/// venait d'indiquer par où passer.
void _cleDuDefi() {
  group('plafond atteint', () {
    test('CONSERVE la clé du défi jusqu\'au verdict', () async {
      final transport = FakeTransport()
        ..enfile(const RateLimited('Trop de vérifications.', captchaSiteKey: '0x4AAA'));

      final r = await LookupService(transport).check('1M8GDM9AXKP042788');

      expect(r.outcome, equals(LookupOutcome.rateLimited));
      expect(r.captchaSiteKey, equals('0x4AAA'));
      expect(r.challengeAvailable, isTrue);
    });

    test('n\'invente aucune porte quand le serveur n\'en offre pas', () async {
      final transport = FakeTransport()..enfile(const RateLimited('Réessaie plus tard.'));

      final r = await LookupService(transport).check('1M8GDM9AXKP042788');

      expect(r.captchaSiteKey, isNull);
      expect(r.challengeAvailable, isFalse);
    });

    test('aucun autre verdict n\'annonce de défi', () {
      const LookupResult inconnu =
          LookupResult(outcome: LookupOutcome.unknown, message: 'Pas enregistré.');

      expect(inconnu.challengeAvailable, isFalse);
    });
  });
}
