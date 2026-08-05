import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Dossier d'identité (ST-0103).
///
/// LES CHARGES UTILES SONT CELLES DE LA PRODUCTION, relevées sur le serveur.
/// Le motif de refus était lu sous `rejection_reason`, un nom que le serveur
/// n'envoie pas : il le place dans `last_submission.review_reason`. Le motif
/// existait donc depuis le début, et personne ne l'a jamais vu.
void main() {
  test('MONTRE LE MOTIF DU REFUS, là où le serveur le place', () async {
    // Sans motif, un dossier refusé se redépose à l'identique et se fait
    // refuser à l'identique : deux fois l'attente, deux fois le travail
    // de l'agent, et personne ne comprend.
    final transport = FakeTransport()
      ..enfile(<String, Object?>{
        'status': 'rejected',
        'status_label': 'Refusée',
        'can_submit': true,
        'verified_at': null,
        'last_submission': <String, Object?>{
          'id': 7,
          'status': 'rejected',
          'review_reason': 'Le selfie ne correspond pas à la photo de la pièce.',
          'submitted_at': '2026-08-04T09:12:00+00:00',
          'reviewed_at': '2026-08-04T14:40:00+00:00',
        },
        'unlocks': <Object?>[],
      });

    final KycStatus etat = await KycService(transport).status();

    expect(etat.status, equals('rejected'));
    expect(etat.rejectionReason, contains('selfie'));
    expect(transport.appels.single, equals('GET /kyc'));
  });

  test('SUIT LE SERVEUR sur ce qu\'il autorise à redéposer', () async {
    // Une vérification en cours ne se resoumet pas : deux dossiers concurrents
    // feraient trancher un agent sur une pièce que l'autre a déjà écartée.
    final transport = FakeTransport()
      ..enfile(<String, Object?>{
        'status': 'pending',
        'status_label': 'En cours de vérification',
        'can_submit': false,
        'last_submission': <String, Object?>{'id': 8, 'status': 'pending'},
      });

    final KycStatus etat = await KycService(transport).status();

    expect(etat.canSubmit, isFalse);
    expect(etat.needsAction, isFalse);
  });

  test('un compte vérifié n\'a plus rien à faire', () async {
    final transport = FakeTransport()
      ..enfile(<String, Object?>{
        'status': 'verified',
        'status_label': 'Vérifiée',
        'can_submit': true,
        'verified_at': '2026-08-04T15:00:00+00:00',
        'last_submission': null,
      });

    final KycStatus etat = await KycService(transport).status();

    expect(etat.isVerified, isTrue);
    expect(etat.rejectionReason, isNull);
  });

  test('sans dossier, rien n\'est inventé', () async {
    final transport = FakeTransport()..enfile(<String, Object?>{'status': 'none'});

    final KycStatus etat = await KycService(transport).status();

    expect(etat.status, equals('none'));
    expect(etat.rejectionReason, isNull);
    // Aucun `can_submit` rendu : on retombe sur la déduction locale.
    expect(etat.needsAction, isTrue);
  });
}
