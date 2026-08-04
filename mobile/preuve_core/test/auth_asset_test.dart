import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Coffre factice, qui peut aussi refuser d'écrire — c'est le cas qui compte.
class MemoryStore implements TokenStore {
  String? _token;
  bool refuseEcriture = false;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token) async {
    if (refuseEcriture) {
      throw StateError('coffre indisponible');
    }

    _token = token;
  }

  @override
  Future<void> clear() async => _token = null;
}

void main() {
  group('connexion', () {
    test('ouvre la session et range le jeton', () async {
      final transport = FakeTransport()
        ..enfile(<String, Object?>{
          'token': 'jeton-123',
          'user': <String, Object?>{'id': 7, 'phone': '+2250101181686', 'full_name': 'Awa'},
        });
      final coffre = MemoryStore();

      final compte = await AuthService(transport, coffre).verify(
        '+2250101181686',
        '123456',
        OtpPurpose.login,
      );

      expect(compte.id, equals(7));
      expect(transport.token, equals('jeton-123'));
      expect(await coffre.read(), equals('jeton-123'));
    });

    test('n\'ouvre pas la session si le coffre refuse d\'écrire', () async {
      // L'utilisateur croirait être connecté et se retrouverait dehors au
      // prochain lancement, sans comprendre pourquoi.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{'token': 'jeton-123', 'user': <String, Object?>{}});
      final coffre = MemoryStore()..refuseEcriture = true;

      await expectLater(
        AuthService(transport, coffre).verify('+225', '123456', OtpPurpose.login),
        throwsA(isA<StateError>()),
      );

      expect(transport.token, isNull);
    });

    test('efface le jeton local même si la déconnexion échoue', () async {
      // Une déconnexion qui échouerait faute de réseau laisserait le jeton sur
      // l'appareil — l'inverse exact de ce que l'utilisateur vient de demander,
      // et souvent parce qu'il prête son téléphone.
      final transport = FakeTransport()
        ..enfile(const NetworkFailure('Pas de connexion.'));
      final coffre = MemoryStore().._token = 'jeton-123';
      transport.setToken('jeton-123');

      await AuthService(transport, coffre).logout();

      expect(await coffre.read(), isNull);
      expect(transport.token, isNull);
    });

    test('restaure une session au lancement', () async {
      final transport = FakeTransport();
      final coffre = MemoryStore().._token = 'jeton-persistant';

      expect(await AuthService(transport, coffre).restore(), isTrue);
      expect(transport.isAuthenticated, isTrue);
    });

    test('transporte le motif du code, qui n\'est pas décoratif', () async {
      // Un code demandé pour se connecter ne doit pas pouvoir autoriser un
      // transfert de propriété : le serveur le vérifie, encore faut-il le lui
      // dire.
      expect(OtpPurpose.transfer.wire, equals('transfer'));
      expect(OtpPurpose.sensitiveAction.wire, equals('sensitive_action'));
    });
  });

  group('enregistrement', () {
    test('rend le bien et ce qu\'il reste au quota', () async {
      final transport = FakeTransport()
        ..enfile(<String, Object?>{
          'asset': <String, Object?>{
            'public_ref': 'PRV-2H4K9MNP',
            'category': 'moto',
            'life_status': <String, Object?>{'code': 'V-PRV', 'label': 'Enregistrement récent'},
            'trust_level': <String, Object?>{'code': 'F1', 'label': 'Déclaré'},
          },
          'quota': <String, Object?>{'used': 1, 'free': 3},
        });

      final resultat = await AssetService(transport).register(
        category: 'moto',
        attributes: <String, Object?>{'vin': '1M8GDM9AXKP042788'},
        elapsed: const Duration(seconds: 61),
      );

      expect(resultat.asset.publicRef, equals('PRV-2H4K9MNP'));
      // L'utilisateur voit ce qu'il lui reste AVANT d'être arrêté, plutôt que
      // de le découvrir au refus.
      expect(resultat.quota, isNotNull);
    });

    test('remonte le doublon actif sans jamais réessayer', () async {
      final transport = FakeTransport()
        ..enfile(const AlreadyRegistered(
          'Ce bien est déjà enregistré.',
          claimUrl: '/api/v1/claims?public_ref=PRV-2H4K9MNP',
        ));

      await expectLater(
        AssetService(transport).register(
          category: 'moto',
          attributes: <String, Object?>{'vin': '1M8GDM9AXKP042788'},
        ),
        throwsA(isA<AlreadyRegistered>()),
      );

      // Un seul appel : réessayer ne créerait jamais un second enregistrement
      // actif, et masquerait à l'utilisateur la seule issue — la réclamation.
      expect(transport.appels.length, equals(1));
    });

    test('n\'envoie pas de chronomètre quand il ne veut rien dire', () async {
      final transport = FakeTransport()..enfile(<String, Object?>{'asset': <String, Object?>{}});

      await AssetService(transport).register(
        category: 'moto',
        attributes: <String, Object?>{},
      );

      // Rejouer une file différée mesurerait une durée sans rapport avec le
      // parcours : mieux vaut ne rien dire que fausser CT-02.
      expect(transport.appels.single, equals('POST /assets'));
    });
  });

  group('catalogue', () {
    test('lit les champs déclarés et repère l\'identifiant canonique', () async {
      final transport = FakeTransport()
        ..enfile(<String, Object?>{
          'version': 'v1754301234567',
          'categories': <Object?>[
            <String, Object?>{
              'key': 'voiture',
              'name': 'Voiture',
              'icon': '🚗',
              'fields': <Object?>[
                <String, Object?>{
                  'key': 'vin',
                  'label': 'Numéro de châssis',
                  'type': 'identifier',
                  'required': true,
                  'canonical': true,
                },
                <String, Object?>{
                  'key': 'marque',
                  'label': 'Marque',
                  'type': 'text',
                  'required': false,
                  'canonical': false,
                },
              ],
            },
          ],
        });

      final catalogue = await AssetService(transport).catalog();

      expect(catalogue!.version, equals('v1754301234567'));
      // C'est le champ que le formulaire doit mettre en avant : le seul dont
      // une faute de frappe change l'identité du bien.
      expect(catalogue.byKey('voiture')!.canonical!.key, equals('vin'));
    });

    test('rend null quand le serveur dit « rien de neuf »', () async {
      // Réponse 304, corps vide : ne pas remplacer le catalogue local par du
      // vide, ce qui viderait l'application de tous ses types de biens.
      final transport = FakeTransport()..enfile(<String, Object?>{});

      expect(
        await AssetService(transport).catalog(knownVersion: 'v1754301234567'),
        isNull,
      );
    });
  });
}
