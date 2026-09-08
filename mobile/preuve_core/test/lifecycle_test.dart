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
      await service.propose(12, buyerPhone: '+2250101181686');
      await service.confirm(5, code: '654321', role: TransferRole.buyer);

      expect(
        transport.appels,
        equals(<String>['POST /assets/12/transfer', 'POST /transfers/5/confirm']),
      );
    });

    test('nomme les champs comme le serveur les attend', () async {
      // CE TEST EXISTE PARCE QUE L'ERREUR A ÉTÉ COMMISE. Le cœur envoyait
      // `recipient_phone` là où le serveur valide `buyer_phone`, et un code
      // dont l'initiation n'a que faire : le transfert échouait en 422 à chaque
      // appel, sans qu'aucun test ne s'en aperçoive — le transport factice ne
      // regardait que le chemin.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{'transfer': <String, Object?>{'id': 5}});

      await TransferService(transport).propose(12, buyerPhone: '+2250101181686');

      expect(transport.dernierCorps, equals(<String, Object?>{
        'buyer_phone': '+2250101181686',
      }));
    });

    test('porte l\'adresse de l\'acheteur quand elle est donnée', () async {
      // C'EST ELLE QUI FAIT ARRIVER L'INVITATION. Tant qu'aucune passerelle SMS
      // n'est branchée, un code adressé à un numéro ne part nulle part :
      // l'acheteur n'est jamais prévenu, et la cession expire au bout de sept
      // jours pendant que le vendeur croit sa vente enregistrée.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{'transfer': <String, Object?>{'id': 5}});

      await TransferService(transport).propose(
        12,
        buyerPhone: '+2250101181686',
        buyerEmail: 'acheteur@exemple.ci',
      );

      expect(transport.dernierCorps, equals(<String, Object?>{
        'buyer_phone': '+2250101181686',
        'buyer_email': 'acheteur@exemple.ci',
      }));
    });

    test('n\'envoie pas de clé vide quand aucune adresse n\'est saisie', () async {
      // Le serveur valide `buyer_email` en `email` : une chaîne vide le ferait
      // refuser en 422, et le numéro seul doit rester accepté comme avant.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{'transfer': <String, Object?>{'id': 5}});

      await TransferService(transport).propose(12, buyerPhone: '+2250101181686', buyerEmail: '');

      expect(transport.dernierCorps.containsKey('buyer_email'), isFalse);
    });

    test('annonce toujours son camp au serveur', () async {
      // Le serveur refuse une confirmation sans `role`, et pour cause : une
      // erreur de camp ferait confirmer une vente à qui croyait accepter.
      final transport = FakeTransport()..enfile(<String, Object?>{});

      await TransferService(transport).confirm(5, code: '654321', role: TransferRole.seller);

      expect(transport.dernierCorps, equals(<String, Object?>{
        'code': '654321',
        'role': 'seller',
      }));
    });

    test('rend à chacun le camp que le SERVEUR lui donne', () async {
      // Jamais déduit côté client : l'acheteur n'a aucun moyen de savoir, de
      // lui-même, quel transfert lui est destiné.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{
          'transfers': <Object?>[
            <String, Object?>{
              'id': 5,
              'status': 'initiated',
              'status_label': 'En attente de confirmation',
              'role': 'buyer',
              'seller_confirmed': false,
              'buyer_confirmed': false,
              'expires_at': '2026-08-11T10:00:00+00:00',
              'asset': <String, Object?>{'public_ref': 'PRV-2H4K9MNP', 'category': 'moto'},
            },
          ],
        });

      final attente = await TransferService(transport).mine();

      expect(attente.single.role, equals(TransferRole.buyer));
      expect(attente.single.awaitsMe, isTrue);
      expect(transport.appels.single, equals('GET /transfers'));
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
      await service.open(12);
      await service.addEvidence(
        9,
        evidenceType: EvidenceKind.invoice,
        file: const MultipartFile(
          field: 'file',
          filename: 'facture.jpg',
          bytes: <int>[1, 2, 3],
        ),
      );

      // Aucun refus de paiement sur ces deux étapes : constituer un dossier
      // doit rester libre, y compris pour qui n'a pas la somme.
      expect(transport.appels.length, equals(2));
    });

    test('s\'ouvre par la référence publique, seul chemin d\'une victime', () async {
      // Elle ne connaît pas l'identifiant interne du bien qu'on lui a pris : la
      // consultation publique le tait, pour qu'on ne puisse pas balayer le
      // registre.
      final transport = FakeTransport()
        ..enfile(<String, Object?>{
          'claim': <String, Object?>{'id': 9, 'status': 'draft', 'status_label': 'Brouillon'},
        });

      final dossier = await ClaimService(transport).openByReference('PRV-2H4K9MNP');

      expect(dossier.id, equals(9));
      expect(transport.appels.single, equals('POST /claims'));
      expect(transport.dernierCorps, equals(<String, Object?>{'public_ref': 'PRV-2H4K9MNP'}));
    });

    test('verse la pièce en multipart, comme le serveur l\'attend', () async {
      // Le cœur envoyait un `document_id` en JSON : le serveur, lui, attend le
      // FICHIER lui-même dans un formulaire. L'appel ne pouvait pas aboutir.
      final transport = FakeTransport()..enfile(<String, Object?>{});

      await ClaimService(transport).addEvidence(
        9,
        evidenceType: EvidenceKind.officialNamedDoc,
        documentDate: '2024-03-12',
        file: const MultipartFile(
          field: 'file',
          filename: 'carte-grise.jpg',
          bytes: <int>[1, 2, 3],
        ),
      );

      expect(transport.appels.single, equals('POST(multipart) /claims/9/evidences'));
      expect(transport.dernierCorps, equals(<String, Object?>{
        'evidence_type': 'official_named_doc',
        'document_date': '2024-03-12',
      }));
      expect(transport.fichiersEnvoyes.single.filename, equals('carte-grise.jpg'));
    });

    test('accepte une pièce sans fichier', () async {
      // L'ancienneté d'un compte ou une antériorité documentaire se déclarent
      // sans document joint : exiger un fichier fermerait ces natures de preuve.
      final transport = FakeTransport()..enfile(<String, Object?>{});

      await ClaimService(transport).addEvidence(9, evidenceType: EvidenceKind.accountHistory);

      expect(transport.fichiersEnvoyes, isEmpty);
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
