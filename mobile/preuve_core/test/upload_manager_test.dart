import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// Magasin de file en mémoire — ce qu'un vrai magasin local ferait sur disque.
class MemoryUploadStore implements PendingUploadStore {
  MemoryUploadStore([this.entries = const <Map<String, Object?>>[]]);

  List<Map<String, Object?>> entries;

  @override
  Future<List<Map<String, Object?>>> read() async => entries;

  @override
  Future<void> write(List<Map<String, Object?>> valeurs) async => entries = valeurs;
}

void main() {
  PendingUpload piece(String uuid, {int taille = 4}) => PendingUpload(
        uuid: uuid,
        assetId: 12,
        docType: 'registration_card',
        filename: 'carte-grise.jpg',
        localPath: '/tmp/$uuid.jpg',
        byteSize: taille,
        checksum: 'a' * 64,
      );

  /// Une file dont la lecture de fichier est injectée, comme sur l'appareil.
  UploadManager manager(
    FakeTransport transport,
    MemoryUploadStore magasin, {
    List<int> Function(int offset, int length)? lecture,
  }) {
    return UploadManager(
      queue: UploadQueue(
        transport: transport,
        chunkSize: 4,
        readChunk: (String _, int offset, int length) async =>
            lecture?.call(offset, length) ?? List<int>.filled(4, 1),
      ),
      store: magasin,
    );
  }

  Map<String, Object?> etat(String uuid, {required int recus, int taille = 4}) =>
      <String, Object?>{
        'uuid': uuid,
        'status': recus >= taille ? 'completed' : 'in_progress',
        'received_bytes': recus,
        'byte_size': taille,
      };

  test('survit à la fermeture de l\'application', () async {
    // C'EST TOUT L'OBJET DE CETTE CLASSE. Quelqu'un photographie sa carte grise
    // au bord d'une route, perd le réseau, range son téléphone et rouvre deux
    // heures plus tard : sans persistance, sa pièce n'était jamais partie et
    // rien ne le lui aurait dit.
    final magasin = MemoryUploadStore();

    await manager(FakeTransport(), magasin).enqueue(piece('u-1'));

    final apresRedemarrage = manager(FakeTransport(), magasin);
    await apresRedemarrage.restore();

    expect(apresRedemarrage.pending.single.uuid, equals('u-1'));
    expect(apresRedemarrage.pending.single.localPath, equals('/tmp/u-1.jpg'));
  });

  test('ne met pas deux fois la même pièce en file', () async {
    // L'identifiant est tiré par le client : c'est ce qui rend le réessai
    // inoffensif, et deux entrées ouvriraient deux sessions sur le disque du
    // serveur.
    final magasin = MemoryUploadStore();
    final file = manager(FakeTransport(), magasin);

    await file.enqueue(piece('u-1'));
    await file.enqueue(piece('u-1'));

    expect(file.pending, hasLength(1));
  });

  test('retire de la file ce qui est réellement arrivé', () async {
    final magasin = MemoryUploadStore();
    final transport = FakeTransport()
      ..enfile(etat('u-1', recus: 0))
      ..enfile(etat('u-1', recus: 4));

    final file = manager(transport, magasin);
    await file.enqueue(piece('u-1'));

    final rapport = await file.drain();

    expect(rapport.completed, hasLength(1));
    expect(file.pending, isEmpty);
    // Et la file persistée est vide, sinon le prochain lancement renverrait une
    // pièce déjà arrivée.
    expect(magasin.entries, isEmpty);
  });

  test('garde en file ce que le réseau a interrompu', () async {
    // Rien n'est perdu : c'est la promesse de CT-05. Une pièce qui disparaîtrait
    // sur une coupure ferait croire le dossier complet.
    final magasin = MemoryUploadStore();
    final transport = FakeTransport()..enfile(const NetworkFailure('Pas de connexion.'));

    final file = manager(transport, magasin);
    await file.enqueue(piece('u-1'));

    final rapport = await file.drain();

    expect(rapport.networkInterrupted, isTrue);
    expect(file.pending, hasLength(1));
    expect(magasin.entries, hasLength(1));
  });

  test('arrête la passe dès que le réseau tombe', () async {
    // Chaque tentative coûte une attente de vingt secondes : les enchaîner
    // ferait paraître l'application bloquée, pour un résultat connu d'avance.
    final magasin = MemoryUploadStore();
    final transport = FakeTransport()..enfile(const NetworkFailure('Pas de connexion.'));

    final file = manager(transport, magasin);
    await file.enqueue(piece('u-1'));
    await file.enqueue(piece('u-2'));

    await file.drain();

    // Une seule tentative, alors que deux pièces attendent.
    expect(transport.appels, hasLength(1));
    expect(file.pending, hasLength(2));
  });

  test('abandonne une pièce dont le fichier local a changé', () async {
    // Distincte d'un échec réseau : celui-ci se réessaie, celui-là non. Le
    // fichier a été tronqué ou remplacé, et poursuivre produirait une pièce dont
    // l'empreinte ne tomberait jamais juste — refus constaté seulement à
    // l'assemblage, après avoir consommé tout le forfait.
    final magasin = MemoryUploadStore();
    final transport = FakeTransport()..enfile(etat('u-1', recus: 0));

    final file = manager(
      transport,
      magasin,
      lecture: (int offset, int length) => const <int>[],
    );
    await file.enqueue(piece('u-1'));

    final rapport = await file.drain();

    expect(rapport.hasFailures, isTrue);
    expect(file.pending, isEmpty);
    expect(rapport.abandoned.values.single, contains('Reprends la photo'));
  });

  test('ne rejoue pas indéfiniment un envoi que le serveur refuse', () async {
    // Une pièce trop lourde ou d'un type non accepté ne se répare pas en
    // réessayant : la garder en file la ferait rejouer à chaque passe, pour
    // toujours.
    final magasin = MemoryUploadStore();
    final transport = FakeTransport()
      ..enfile(const InvalidRequest('Ce format de fichier n\'est pas accepté.'));

    final file = manager(transport, magasin);
    await file.enqueue(piece('u-1'));

    final rapport = await file.drain();

    expect(file.pending, isEmpty);
    expect(rapport.abandoned.values.single, contains('format'));
  });

  test('n\'insiste pas quand la session est tombée', () async {
    // Insister ferait échouer tous les envois et épuiserait le compteur
    // d'anti-brute-force du serveur, au détriment de la reconnexion.
    final magasin = MemoryUploadStore();
    final transport = FakeTransport()..enfile(const NotAuthenticated('Session expirée.'));

    final file = manager(transport, magasin);
    await file.enqueue(piece('u-1'));
    await file.enqueue(piece('u-2'));

    await file.drain();

    expect(transport.appels, hasLength(1));
    // RIEN N'EST PERDU : une session expirée n'est pas une pièce invalide.
    expect(file.pending, hasLength(2));
  });

  test('écarte une entrée illisible plutôt que d\'empêcher le démarrage', () async {
    // Magasin corrompu, ou format d'une version antérieure : perdre un envoi
    // vaut mieux que perdre l'application.
    final magasin = MemoryUploadStore(<Map<String, Object?>>[
      <String, Object?>{'uuid': 'u-1', 'asset_id': 'douze'},
      piece('u-2').toJson(),
    ]);

    final file = manager(FakeTransport(), magasin);
    await file.restore();

    expect(file.pending.single.uuid, equals('u-2'));
  });
}
