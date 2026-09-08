import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Centre de notifications (ST-1001) et préférences (ST-0107).
void main() {
  Map<String, Object?> fil(List<Object?> lignes, {int nonLus = 0}) => <String, Object?>{
        'unread_count': nonLus,
        'notifications': lignes,
        'meta': <String, Object?>{'current_page': 1, 'last_page': 1, 'total': lignes.length},
      };

  test('rend le fil et son badge', () async {
    final transport = FakeTransport()
      ..enfile(fil(<Object?>[
        <String, Object?>{
          'id': 3,
          'type': 'asset_lookup',
          'title': 'Ton bien a été consulté',
          'body': 'Consulté 3 fois aujourd\'hui.',
          'read': false,
          'created_at': '2026-08-04T09:00:00+00:00',
        },
      ], nonLus: 1));

    final resultat = await NotificationService(transport).feed();

    expect(resultat.notifications.single.title, equals('Ton bien a été consulté'));
    expect(resultat.unreadCount, equals(1));
    expect(transport.appels.single, equals('GET /notifications'));
  });

  test('prend le badge du SERVEUR, pas le compte de la page', () async {
    // Le fil est paginé : recompter les non-lus visibles annoncerait « 20 » à
    // quelqu'un qui en a deux cents.
    final transport = FakeTransport()
      ..enfile(fil(<Object?>[
        <String, Object?>{'id': 1, 'type': 'asset_lookup', 'read': false},
      ], nonLus: 214));

    expect((await NotificationService(transport).feed()).unreadCount, equals(214));
  });

  test('n\'offre aucune place à l\'identité du consultant', () async {
    // L'anonymat est SYMÉTRIQUE : le consultant y a autant droit que le
    // détenteur. Un modèle qui prévoirait la place d'un « consulté par »
    // finirait par la faire remplir.
    final champs = UserNotification.fromJson(<String, Object?>{
      'id': 1,
      'type': 'asset_lookup',
      'title': 'Consulté',
      'body': 'Consulté 3 fois aujourd\'hui.',
      'payload': <String, Object?>{'count': 3},
    });

    expect(champs.payload.containsKey('ip'), isFalse);
    expect(champs.payload['count'], equals(3));
    expect(champs.body, contains('3 fois'));
  });

  test('reconnaît les alertes qui appellent un geste', () async {
    // Elles ne sont pas désactivables et ne doivent pas se noyer dans le fil :
    // quelqu'un a tenté d'enregistrer un bien déjà à vous.
    UserNotification de(String type) =>
        UserNotification.fromJson(<String, Object?>{'id': 1, 'type': type});

    expect(de('duplicate_attempt').isCritical, isTrue);
    expect(de('lookup_spike').isCritical, isTrue);
    expect(de('claim_opened').isCritical, isTrue);
    expect(de('asset_lookup').isCritical, isFalse);
  });

  test('marque comme lu sans supposer la réponse', () async {
    final transport = FakeTransport()..enfile(<String, Object?>{});

    await NotificationService(transport).markAsRead(3);

    expect(transport.appels.single, equals('POST /notifications/3/read'));
  });

  test('ne propose à couper que ce que le serveur déclare désactivable', () async {
    // Afficher une case à cocher qui ne coupe rien serait pire que de ne pas la
    // proposer : les événements critiques ne figurent pas dans `available`.
    final transport = FakeTransport()
      ..enfile(<String, Object?>{
        'preferences': <String, Object?>{'asset_lookup': false},
        'available': <Object?>[
          <String, Object?>{'type': 'asset_lookup', 'label': 'Consultations de mes biens'},
        ],
      });

    final preferences = await NotificationService(transport).preferences();

    expect(preferences.available.single.type, equals('asset_lookup'));
    expect(preferences.enabled('asset_lookup'), isFalse);
  });

  test('considère qu\'une préférence jamais réglée veut dire « préviens-moi »', () async {
    // Le contraire ferait taire silencieusement des alertes que personne n'a
    // demandé à couper.
    const preferences = NotificationPreferences(
      values: <String, bool>{},
      available: <OptionalNotification>[],
    );

    expect(preferences.enabled('asset_lookup'), isTrue);
  });

  test('transmet les préférences sous la clé attendue par le serveur', () async {
    final transport = FakeTransport()
      ..enfile(<String, Object?>{'preferences': <String, Object?>{'asset_lookup': false}});

    await NotificationService(transport).updatePreferences(<String, bool>{'asset_lookup': false});

    expect(transport.appels.single, equals('PUT /notification-preferences'));
    expect(transport.dernierCorps, equals(<String, Object?>{
      'preferences': <String, bool>{'asset_lookup': false},
    }));
  });
}
