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

  test('ENVOIE LA SÉQUENCE sous les noms que le serveur reconnaît', () async {
    // Le serveur n'accepte que `left` et `right`. Une étiquette libre le
    // laisserait recevoir quatre fois la même photo sous quatre noms inventés,
    // et l'agent croirait voir une séquence.
    const MultipartFile image = MultipartFile(
      field: 'x',
      filename: 'v.jpg',
      bytes: <int>[1],
      contentType: 'image/jpeg',
    );

    final transport = FakeTransport()..enfile(<String, Object?>{'status': 'pending'});

    await KycService(transport).submit(
      idFront: image,
      idBack: image,
      selfie: image,
      livenessLeft: image,
      livenessRight: image,
    );

    expect(
      transport.fichiersEnvoyes.map((MultipartFile f) => f.field),
      equals(<String>['id_front', 'id_back', 'selfie', 'liveness[left]', 'liveness[right]']),
    );
  });

  test('N\'ENVOIE AUCUN SCORE avec la séquence', () async {
    // Ce que l'appareil calcule sur lui-même n'est pas vérifiable : une
    // application modifiée enverrait cent, et l'agent cesserait de regarder.
    const MultipartFile image = MultipartFile(
      field: 'x',
      filename: 'v.jpg',
      bytes: <int>[1],
      contentType: 'image/jpeg',
    );

    final transport = FakeTransport()..enfile(<String, Object?>{'status': 'pending'});

    await KycService(transport).submit(
      idFront: image,
      idBack: image,
      selfie: image,
      livenessLeft: image,
      livenessRight: image,
    );

    final String envoye = transport.dernierCorps.keys.join(' ');

    expect(envoye, isNot(contains('score')));
    expect(envoye, isNot(contains('liveness_score')));
  });

  test('un dossier SANS séquence part quand même', () async {
    // Un appareil qui ne sait pas les produire ne doit pas se voir refuser une
    // vérification d'identité.
    const MultipartFile image = MultipartFile(
      field: 'x',
      filename: 'v.jpg',
      bytes: <int>[1],
      contentType: 'image/jpeg',
    );

    final transport = FakeTransport()..enfile(<String, Object?>{'status': 'pending'});

    await KycService(transport).submit(idFront: image, idBack: image, selfie: image);

    expect(transport.fichiersEnvoyes, hasLength(3));
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
