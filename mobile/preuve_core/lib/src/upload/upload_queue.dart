import '../api/exceptions.dart';
import '../api/transport.dart';

/// File d'envoi différée, avec reprise (ST-0206, CT-05).
///
/// CE QUI DOIT TENIR N'EST PAS L'ENVOI — IL ÉCHOUERA — MAIS LA POSSIBILITÉ DE
/// LE REPRENDRE. Une pièce justificative pèse plusieurs mégaoctets, et le
/// réseau d'un bord de route ne les transporte pas d'un trait. Un client qui
/// recommencerait depuis le début à chaque coupure ne finirait jamais.
///
/// L'IDENTIFIANT EST TIRÉ PAR LE CLIENT, et c'est ce qui rend le réessai
/// inoffensif : un `POST /uploads` rejoué après une coupure retrouve la session
/// existante au lieu d'en ouvrir une seconde. Sans cela, une reprise créerait
/// autant de sessions orphelines que de tentatives, chacune occupant le disque
/// d'un hébergement mutualisé.
///
/// LA POSITION FAIT AUTORITÉ CÔTÉ SERVEUR. Sur conflit, il rend la position
/// qu'il connaît réellement : c'est elle qui décide, jamais le compteur local.
/// Le client peut avoir cru envoyer un morceau que le serveur n'a jamais reçu.
///
/// LA LECTURE DU FICHIER EST INJECTÉE. Cette classe ne connaît ni système de
/// fichiers ni plateforme : c'est ce qui permet de l'éprouver entièrement dans
/// une console, sans appareil.
class UploadQueue {
  UploadQueue({
    required PreuveTransport transport,
    required this.readChunk,
    this.chunkSize = 256 * 1024,
  }) : _api = transport;

  final PreuveTransport _api;

  /// Lit [length] octets à partir de [offset]. Rend moins d'octets en fin de
  /// fichier ; jamais plus.
  final Future<List<int>> Function(String localPath, int offset, int length) readChunk;

  /// 256 Kio : assez grand pour ne pas multiplier les allers-retours, assez
  /// petit pour qu'une coupure ne fasse pas reperdre une minute d'envoi sur une
  /// 3G à 50 Kio/s.
  final int chunkSize;

  /// Ouvre une session, ou retrouve celle qui existe déjà pour cet identifiant.
  Future<UploadState> open(PendingUpload upload) async {
    final body = await _api.post('/uploads', body: <String, Object?>{
      'uuid': upload.uuid,
      'asset_id': upload.assetId,
      'doc_type': upload.docType,
      'filename': upload.filename,
      'byte_size': upload.byteSize,
      'checksum': upload.checksum,
    });

    return UploadState.fromJson(body);
  }

  /// Où en est une session, du point de vue du serveur.
  ///
  /// À appeler au redémarrage de l'application pour chaque envoi en attente :
  /// c'est la seule source de vérité sur ce qui est réellement arrivé.
  Future<UploadState> state(String uuid) async {
    return UploadState.fromJson(await _api.get('/uploads/$uuid'));
  }

  /// Pousse la session jusqu'au bout, morceau par morceau.
  ///
  /// Rend l'état final. Ne relance PAS d'elle-même après un échec réseau :
  /// décider quand réessayer appartient à l'appelant, qui seul sait si l'écran
  /// est encore ouvert, si la batterie tient et si le réseau est revenu.
  Future<UploadState> push(PendingUpload upload, {UploadState? known}) async {
    var state = known ?? await open(upload);

    while (!state.isComplete && !state.hasFailed) {
      final chunk = await readChunk(upload.localPath, state.receivedBytes, chunkSize);

      if (chunk.isEmpty) {
        // Le serveur attend des octets que le fichier local n'a plus : il a
        // été tronqué ou remplacé depuis l'ouverture de la session. Poursuivre
        // produirait une pièce dont l'empreinte ne tomberait jamais juste.
        throw const LocalFileChanged(
          'Le fichier a changé depuis le début de l\'envoi. Reprends la photo.',
        );
      }

      try {
        state = UploadState.fromJson(
          await _api.patchBytes('/uploads/${upload.uuid}', chunk, offset: state.receivedBytes),
        );
      } on UploadOffsetMismatch catch (e) {
        // Le serveur rend la position qu'il connaît : on s'y range plutôt que
        // d'abandonner ou de tout renvoyer depuis le début. Le client peut
        // avoir cru envoyer un morceau qui n'est jamais arrivé.
        state = state.at(e.receivedBytes);
      }
    }

    return state;
  }
}

/// Une pièce en attente d'envoi, telle que la file locale la conserve.
class PendingUpload {
  const PendingUpload({
    required this.uuid,
    required this.assetId,
    required this.docType,
    required this.filename,
    required this.localPath,
    required this.byteSize,
    required this.checksum,
  });

  /// Tiré par le CLIENT, à la mise en file. C'est lui qui rend le réessai
  /// idempotent : le regénérer à chaque tentative ouvrirait une session par
  /// tentative.
  final String uuid;

  final int assetId;
  final String docType;
  final String filename;
  final String localPath;
  final int byteSize;

  /// Empreinte du fichier COMPLET, annoncée avant le premier octet : c'est elle
  /// qui permettra de constater, à l'assemblage, que rien n'a été altéré.
  final String checksum;
}

/// Ce que le serveur dit d'une session d'envoi.
class UploadState {
  const UploadState({
    required this.uuid,
    required this.status,
    required this.receivedBytes,
    required this.byteSize,
    this.documentId,
    this.failureReason,
  });

  factory UploadState.fromJson(Map<String, Object?> json) {
    return UploadState(
      uuid: json['uuid'] is String ? json['uuid']! as String : '',
      status: json['status'] is String ? json['status']! as String : 'pending',
      receivedBytes: _int(json['received_bytes']),
      byteSize: _int(json['byte_size']),
      documentId: json['document_id'] is int ? json['document_id']! as int : null,
      failureReason:
          json['failure_reason'] is String ? json['failure_reason']! as String : null,
    );
  }

  final String uuid;
  final String status;

  /// Position réelle, telle que le serveur la connaît. C'est elle qui décide
  /// d'où reprendre — jamais un compteur local.
  final int receivedBytes;
  final int byteSize;
  final int? documentId;
  final String? failureReason;

  bool get isComplete => status == 'completed' || (byteSize > 0 && receivedBytes >= byteSize);

  bool get hasFailed => status == 'failed';

  /// Part de l'envoi accomplie, pour une jauge. Jamais utilisée comme vérité :
  /// seule `receivedBytes` l'est.
  double get progress => byteSize == 0 ? 0 : (receivedBytes / byteSize).clamp(0, 1).toDouble();

  UploadState at(int offset) => UploadState(
        uuid: uuid,
        status: status,
        receivedBytes: offset,
        byteSize: byteSize,
        documentId: documentId,
        failureReason: failureReason,
      );

  static int _int(Object? value) => value is int ? value : 0;
}
