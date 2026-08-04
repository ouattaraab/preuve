import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Inventaire du détenteur — le point d'entrée de toute action.
///
/// Sans lui, l'application ne connaît l'identifiant interne d'aucun bien, et
/// ni le vol, ni le transfert, ni la réclamation ne sont atteignables.
void main() {
  Map<String, Object?> reponse(List<Object?> biens) => <String, Object?>{
        'assets': biens,
        'pagination': <String, Object?>{
          'page': 1,
          'per_page': 25,
          'total': biens.length,
          'last_page': 1,
        },
        'quota': <String, Object?>{'used': 1, 'free_slots': 3},
      };

  Map<String, Object?> moto({
    String statut = 'V-ACT',
    String identifiant = '1M8GDM9AXKP042788',
  }) =>
      <String, Object?>{
        'id': 12,
        'public_ref': 'PRV-2H4K9MNP',
        'identifier': identifiant,
        'identifier_type': 'vin',
        'category': 'moto',
        'attributes': <String, Object?>{'brand_model': 'Yamaha Crux'},
        'life_status': <String, Object?>{
          'code': statut,
          'label': 'Actif',
          'color': '#2B1D12',
          'warning': false,
        },
        'trust_level': <String, Object?>{'code': 'F1', 'label': 'Déclaré', 'color': '#5C4A33'},
        'registered_at': '2026-06-04T10:00:00+00:00',
      };

  test('rend l\'identifiant interne, sans quoi aucune action n\'est possible', () async {
    final transport = FakeTransport()..enfile(reponse(<Object?>[moto()]));

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.assets.single.id, equals(12));
    expect(transport.appels.single, equals('GET /assets'));
  });

  test('rend le numéro complet à son propre détenteur', () async {
    // Le verdict public le tait pour empêcher le balayage ; ici, la personne
    // relit ce qu'elle a saisi. Le lui cacher l'empêcherait de reconnaître son
    // véhicule dans une liste qui en compte douze.
    final transport = FakeTransport()..enfile(reponse(<Object?>[moto()]));

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.assets.single.identifier, equals('1M8GDM9AXKP042788'));
  });

  test('préfère un libellé lisible au numéro dans une liste', () async {
    final transport = FakeTransport()..enfile(reponse(<Object?>[moto()]));

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.assets.single.label, equals('Yamaha Crux'));
  });

  test('retombe sur le numéro quand la catégorie n\'a pas de libellé', () async {
    // Une catégorie servie par configuration distante peut n'avoir aucun champ
    // « marque et modèle » : mieux vaut un numéro qu'une ligne vide.
    final sansMarque = moto()..['attributes'] = <String, Object?>{};
    final transport = FakeTransport()..enfile(reponse(<Object?>[sansMarque]));

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.assets.single.label, equals('1M8GDM9AXKP042788'));
  });

  test('reconnaît un bien volé et un bien gelé', () async {
    // Ce qui décide des actions proposées : offrir « céder » sur un bien en
    // litige ferait promettre à l'écran ce que le serveur refusera.
    final transport = FakeTransport()
      ..enfile(reponse(<Object?>[
        moto(statut: 'V-VOL'),
        moto(statut: 'V-LIT', identifiant: 'JH4KA7561PC008269'),
      ]));

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.assets.first.isStolen, isTrue);
    expect(inventaire.assets.last.isFrozen, isTrue);
    expect(inventaire.assets.first.isFrozen, isFalse);
  });

  test('rend le quota avec l\'inventaire, avant tout formulaire', () async {
    // L'utilisateur voit ce qu'il lui reste AVANT d'ouvrir un formulaire,
    // plutôt que de l'apprendre au refus après quatre-vingt-dix secondes.
    final transport = FakeTransport()..enfile(reponse(<Object?>[moto()]));

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.quota?['free_slots'], equals(3));
  });

  test('demande la page suivante seulement quand il y en a une', () async {
    final transport = FakeTransport()
      ..enfile(<String, Object?>{
        'assets': <Object?>[moto()],
        'pagination': <String, Object?>{'page': 1, 'last_page': 3, 'total': 60},
      });

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.hasMore, isTrue);
  });

  test('survit à une réponse vide plutôt que de tomber', () async {
    // Un inventaire qui ferait tomber l'écran au premier champ manquant
    // priverait quelqu'un de la déclaration de vol qu'il vient ouvrir.
    final transport = FakeTransport()..enfile(<String, Object?>{});

    final inventaire = await AssetService(transport).mine();

    expect(inventaire.assets, isEmpty);
    expect(inventaire.page, equals(1));
  });
}
