import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

import 'fake_transport.dart';

/// CE QUI DOIT TENIR N'EST PAS L'ENVOI — IL ÉCHOUERA — MAIS LA POSSIBILITÉ DE
/// LE REPRENDRE. Ces tests éprouvent exactement les situations que le réseau
/// d'un bord de route produit : une coupure au milieu, un morceau que le
/// serveur n'a jamais reçu, une reprise après redémarrage de l'application.
void main() {
  /// Fichier factice : le contenu importe peu, seule la taille compte.
  Future<List<int>> lecteur(String path, int offset, int length) async {
    const total = 1000;

    if (offset >= total) {
      return const <int>[];
    }

    final fin = (offset + length) > total ? total : offset + length;

    return List<int>.filled(fin - offset, 7);
  }

  Map<String, Object?> etat(int recus, {String statut = 'receiving'}) =>
      <String, Object?>{
        'uuid': 'u-1',
        'status': statut,
        'received_bytes': recus,
        'byte_size': 1000,
      };

  const piece = PendingUpload(
    uuid: 'u-1',
    assetId: 12,
    docType: 'registration_card',
    filename: 'carte-grise.jpg',
    localPath: '/tmp/carte-grise.jpg',
    byteSize: 1000,
    checksum:
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  );

  test('envoie le fichier en morceaux jusqu\'au bout', () async {
    final transport = FakeTransport()
      ..enfile(etat(0))
      ..enfile(etat(400))
      ..enfile(etat(800))
      ..enfile(etat(1000, statut: 'completed'));

    final queue = UploadQueue(
      transport: transport,
      readChunk: lecteur,
      chunkSize: 400,
    );

    final resultat = await queue.push(piece);

    expect(resultat.isComplete, isTrue);
    expect(transport.offsetsEnvoyes, equals(<int>[0, 400, 800]));
  });

  test('se range sur la position que le serveur annonce', () async {
    // LE CAS QUI COMPTE : le client croit avoir envoyé jusqu'à 400, le serveur
    // n'a reçu que 200. C'est lui qui fait autorité — reprendre depuis le
    // compteur local perdrait deux cents octets pour toujours.
    final transport = FakeTransport()
      ..enfile(etat(400))
      ..enfile(const UploadOffsetMismatch('Position inattendue.', receivedBytes: 200))
      ..enfile(etat(600))
      ..enfile(etat(1000, statut: 'completed'));

    final queue = UploadQueue(
      transport: transport,
      readChunk: lecteur,
      chunkSize: 400,
    );

    final resultat = await queue.push(piece);

    expect(resultat.isComplete, isTrue);
    // Le deuxième envoi repart de 200, la position réelle, et non de 400.
    expect(transport.offsetsEnvoyes, equals(<int>[400, 200, 600]));
  });

  test('reprend après redémarrage sans rouvrir de session', () async {
    // Au redémarrage, l'application demande l'état plutôt que de rouvrir : un
    // `POST` rejoué retrouverait la même session grâce à l'uuid, mais
    // l'interroger coûte moins et dit où reprendre.
    final transport = FakeTransport()
      ..enfile(etat(600))
      ..enfile(etat(1000, statut: 'completed'));

    final queue = UploadQueue(
      transport: transport,
      readChunk: lecteur,
      chunkSize: 400,
    );

    final connu = await queue.state('u-1');
    final resultat = await queue.push(piece, known: connu);

    expect(resultat.isComplete, isTrue);
    expect(transport.offsetsEnvoyes, equals(<int>[600]));
    // Aucune ouverture : la session existait déjà.
    expect(transport.appels.where((a) => a.startsWith('POST')), isEmpty);
  });

  test('l\'identifiant tiré par le client rend le réessai inoffensif', () async {
    final transport = FakeTransport()
      ..enfile(etat(0))
      ..enfile(etat(0));

    final queue = UploadQueue(transport: transport, readChunk: lecteur);

    await queue.open(piece);
    await queue.open(piece);

    // Deux ouvertures, un seul uuid : le serveur retrouve la session au lieu
    // d'en créer une seconde. Regénérer l'uuid à chaque tentative laisserait
    // autant de sessions orphelines que de coupures.
    expect(transport.appels, equals(<String>['POST /uploads', 'POST /uploads']));
  });

  test('arrête l\'envoi quand le fichier local a changé', () async {
    // Le serveur attend des octets que le fichier n'a plus. Poursuivre
    // produirait une pièce dont l'empreinte ne tomberait jamais juste — et le
    // refus n'arriverait qu'à l'assemblage, après avoir consommé le forfait.
    final transport = FakeTransport()..enfile(etat(1000, statut: 'receiving'));

    final queue = UploadQueue(
      transport: transport,
      readChunk: (_, offset, length) async => const <int>[],
      chunkSize: 400,
    );

    // `received_bytes` égal à `byte_size` marque déjà la complétion : on force
    // un cas où le serveur en attend davantage.
    final incomplet = UploadState.fromJson(<String, Object?>{
      'uuid': 'u-1',
      'status': 'receiving',
      'received_bytes': 400,
      'byte_size': 1000,
    });

    expect(
      () => queue.push(piece, known: incomplet),
      throwsA(isA<LocalFileChanged>()),
    );
  });

  test('un échec réseau remonte tel quel, à l\'appelant de décider', () async {
    // La file ne réessaie pas d'elle-même : seul l'appelant sait si l'écran est
    // encore ouvert, si la batterie tient et si le réseau est revenu.
    final transport = FakeTransport()
      ..enfile(etat(0))
      ..enfile(const NetworkFailure('Pas de connexion.'));

    final queue = UploadQueue(
      transport: transport,
      readChunk: lecteur,
      chunkSize: 400,
    );

    expect(() => queue.push(piece), throwsA(isA<NetworkFailure>()));
  });

  test('rend une progression bornée, jamais la vérité', () {
    final state = UploadState.fromJson(etat(250));

    expect(state.progress, closeTo(0.25, 0.001));
    // Un serveur qui annoncerait plus que la taille ne doit pas produire une
    // jauge au-delà de 100 %.
    expect(UploadState.fromJson(etat(5000)).progress, equals(1));
  });
}
