import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Tableau de bord d'un loueur (EP-07), la verticale de lancement.
///
/// LA CHARGE UTILE EST CELLE DE LA PRODUCTION, relevée sur le serveur et non
/// devinée : `fleet_size` et `by_status`. Un nom deviné aurait affiché ZÉRO
/// véhicule sur un parc de quarante, et aucun test ne s'en serait aperçu.
void main() {
  /// RELEVÉE SUR LE SERVEUR DE PRODUCTION, recopiée sans retouche. C'est ce
  /// qui donne sa valeur au test : une fixture inventée aurait confirmé mes
  /// noms inventés.
  Map<String, Object?> tableau() => <String, Object?>{
        'dashboard': <String, Object?>{
          'fleet_size': 4,
          'by_status': <Object?>[
            <String, Object?>{'code': 'V-ACT', 'label': 'Actif', 'count': 2, 'warning': false},
            <String, Object?>{'code': 'V-LOC', 'label': 'En location', 'count': 1, 'warning': true},
            <String, Object?>{'code': 'V-VOL', 'label': 'Volé déclaré', 'count': 1, 'warning': true},
          ],
          'needs_attention': <Object?>[
            <String, Object?>{
              'asset_id': 4,
              'public_ref': 'PRV-A1D08C05',
              'status': 'V-LOC',
              'status_label': 'En location',
            },
            <String, Object?>{
              'asset_id': 5,
              'public_ref': 'PRV-F4F27AF0',
              'status': 'V-VOL',
              'status_label': 'Volé déclaré',
            },
          ],
          'lookups_30d': <String, Object?>{
            'total': 12,
            'most_viewed': <Object?>[
              <String, Object?>{'asset_id': 4, 'public_ref': 'PRV-A1D08C05', 'lookups': 9},
            ],
          },
          'recent_alerts': <Object?>[],
        },
        'billing': <String, Object?>{'fleet_size': 4, 'billable': 0, 'monthly_fcfa': 0},
      };

  test('lit la taille du parc sous le nom que le serveur emploie', () async {
    final transport = FakeTransport()..enfile(tableau());

    final d = await FleetService(transport).dashboard(1);

    expect(d.total, equals(4));
    expect(transport.appels.single, equals('GET /fleet/1/dashboard'));
  });

  test('les consultations sont un OBJET côté serveur, pas un entier', () async {
    // `lookups_30d` vaut `{total, most_viewed}`. Le lire comme un entier
    // rendait 0 en silence : un parc très consulté paraissait ignoré.
    final transport = FakeTransport()..enfile(tableau());

    final d = await FleetService(transport).dashboard(1);

    expect(d.lookups30d, equals(12));
  });

  test('NOMME LES VÉHICULES à traiter, au lieu de les compter', () async {
    // « Volé déclaré : 1 » ne dit pas LEQUEL. Le serveur les nomme déjà.
    final transport = FakeTransport()..enfile(tableau());

    final d = await FleetService(transport).dashboard(1);

    expect(d.needsAttention.map((FleetAlert a) => a.publicRef),
        equals(<String>['PRV-A1D08C05', 'PRV-F4F27AF0']));
    expect(d.needsAttention.last.statusLabel, equals('Volé déclaré'));
  });

  test('ne rend que la référence publique, jamais l\'immatriculation', () async {
    // Cet écran se consulte au comptoir : il se lit par-dessus l'épaule.
    final transport = FakeTransport()..enfile(tableau());

    final d = await FleetService(transport).dashboard(1);

    for (final FleetAlert a in d.needsAttention) {
      expect(a.publicRef, startsWith('PRV-'));
    }
  });

  test('REMONTE CE QUI ALARME, et rien d\'autre', () async {
    // Un loueur ouvre cet écran pour savoir ce qui ne va pas. Trier par volume
    // mettrait « Actif » en tête et enterrerait le vol.
    final transport = FakeTransport()..enfile(tableau());

    final d = await FleetService(transport).dashboard(1);

    expect(d.alerts.map((FleetStatusCount s) => s.code), containsAll(<String>['V-LOC', 'V-VOL']));
    expect(d.alerts.any((FleetStatusCount s) => s.code == 'V-ACT'), isFalse);
  });

  test('marque en masse, et nomme les champs comme le serveur les attend', () async {
    final transport = FakeTransport()..enfile(<String, Object?>{'marked': 2});

    await FleetService(transport).markRented(
      7,
      identifiers: <String>['AA-123-BC', 'DD-456-EF'],
      rented: true,
    );

    expect(transport.appels.single, equals('POST /fleet/7/rented'));
    expect(transport.dernierCorps, equals(<String, Object?>{
      'identifiers': <String>['AA-123-BC', 'DD-456-EF'],
      'rented': true,
    }));
  });

  test('survit à un tableau vide plutôt que de tomber', () async {
    final transport = FakeTransport()..enfile(<String, Object?>{});

    final d = await FleetService(transport).dashboard(1);

    expect(d.total, equals(0));
    expect(d.statuses, isEmpty);
    expect(d.needsAttention, isEmpty);
    expect(d.lookups30d, equals(0));
  });
}
