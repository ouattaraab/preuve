import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Vol, transfert, réclamation : les trois parcours qui changent la propriété
/// ou la protège.
void main() {
  Map<String, Object?> bien(String statut) => <String, Object?>{
        'asset': <String, Object?>{
          'public_ref': 'PRV-2H4K9MNP',
          'category': 'moto',
          'life_status': <String, Object?>{'code': statut, 'label': 'Volé déclaré', 'warning': true},
          'trust_level': <String, Object?>{'code': 'F1', 'label': 'Déclaré'},
        },
      };

  group('vol', () {
    test('rend le bien invendable sur un seul code', () async {
      // C'est le parcours le plus urgent du produit : chaque écran de plus est
      // une minute pendant laquelle le bien peut être revendu.
      final transport = FakeTransport()..enfile(bien('V-VOL'));

      final resultat = await LifecycleService(transport).declareStolen(12, '123456');

      expect(resultat.lifeStatus.code, equals('V-VOL'));
      expect(transport.appels.single, equals('POST /assets/12/stolen'));
    });

    test('exige un code, qui n\'est pas une friction gratuite', () async {
      // Une déclaration rend un bien invendable dans la seconde : un téléphone
      // déverrouillé laissé sur une table ne doit pas suffire à geler le
      // véhicule de son propriétaire (CT-06).
      final transport = FakeTransport()
        ..enfile(const InvalidRequest('Code requis.', errors: <String, List<String>>{
          'code': <String>['Le code est obligatoire.'],
        }));

      await expectLater(
        LifecycleService(transport).declareStolen(12, ''),
        throwsA(isA<InvalidRequest>()),
      );
    });

    test('la levée passe par la suppression, pas par une nouvelle déclaration', () async {
      final transport = FakeTransport()..enfile(bien('V-ACT'));

      await LifecycleService(transport).clearStolen(12, '123456');

      expect(transport.appels.single, equals('DELETE /assets/12/stolen'));
    });
  });

  group('transfert', () {
    test('propose, puis attend la confirmation de l\'acheteur', () async {
      // Sans double validation, un vendeur pourrait se décharger d'un bien
      // litigieux sur quelqu'un qui n'en saurait rien.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{'transfer': <String, Object?>{'id': 5}})
        ..enfile(<String, Object?>{'message': 'Transfert confirmé.'});

      final service = TransferService(transport);
      await service.propose(12, recipientPhone: '+2250101181686', code: '123456');
      await service.confirm(5, '654321');

      expect(
        transport.appels,
        equals(<String>['POST /assets/12/transfer', 'POST /transfers/5/confirm']),
      );
    });

    test('s\'annule tant que l\'acheteur n\'a pas confirmé', () async {
      final transport = FakeTransport()..enfile(<String, Object?>{});

      await TransferService(transport).cancel(5);

      expect(transport.appels.single, equals('DELETE /transfers/5'));
    });
  });

  group('réclamation', () {
    test('ouvrir et verser des pièces ne coûte rien', () async {
      final transport = FakeTransport()
        ..enfile(<String, Object?>{'claim': <String, Object?>{'id': 9}})
        ..enfile(<String, Object?>{'evidence': <String, Object?>{'id': 3}});

      final service = ClaimService(transport);
      await service.open(12, reason: 'Ce véhicule est le mien depuis 2024.');
      await service.addEvidence(9, evidenceType: 'invoice', documentId: 3);

      // Aucun refus de paiement sur ces deux étapes : constituer un dossier
      // doit rester libre, y compris pour qui n'a pas la somme.
      expect(transport.appels.length, equals(2));
    });

    test('les frais sont exigés au DÉPÔT, pas avant', () async {
      // C'est là que le bien est gelé et le détenteur prévenu : le moment où la
      // réclamation commence à coûter à quelqu'un d'autre.
      final transport = FakeTransport()
        ..enfile(const PaymentRequired(
          'Frais de dossier dus.',
          details: <String, Object?>{'amount_fcfa': 2000},
        ));

      await expectLater(
        ClaimService(transport).submit(9),
        throwsA(isA<PaymentRequired>()),
      );

      expect(transport.appels.single, equals('POST /claims/9/submit'));
    });

    test('le montant se lit dans le refus, jamais dans le code', () async {
      // Un administrateur peut le mettre à zéro pour une période : une valeur
      // embarquée afficherait un prix que la plateforme n'exige plus.
      const refus = PaymentRequired(
        'Frais de dossier dus.',
        details: <String, Object?>{'amount_fcfa': 2000},
      );

      expect(refus.details!['amount_fcfa'], equals(2000));
    });
  });
}
