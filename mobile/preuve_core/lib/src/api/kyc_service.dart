import 'transport.dart';

/// Vérification d'identité (ST-0103) : pièce recto, verso, et un selfie.
///
/// LA FRICTION EST MAXIMALE ICI, ET C'EST VOULU (CT-06). Consulter ne demande
/// rien ; vérifier son identité est ce qui ouvre le transfert de propriété et
/// la réclamation. Elle n'est donc JAMAIS exigée pour enregistrer un bien, ni
/// pour déclarer un vol — une victime ne doit pas avoir à prouver qui elle est
/// avant de pouvoir signaler qu'on lui a pris sa moto.
///
/// LE NUMÉRO DE LA PIÈCE N'EST JAMAIS CONSERVÉ EN CLAIR par la plateforme : le
/// serveur n'en garde qu'une empreinte, et ne peut donc pas le restituer — y
/// compris sur réquisition. Le client n'a aucune raison de le saisir ni de le
/// garder : il n'envoie que les images.
///
/// LES TROIS FICHIERS PARTENT ENSEMBLE, sans reprise. C'est le contrat du
/// serveur ; sur une 3G, une coupure oblige à tout renvoyer. Le prévenir avant
/// de lancer l'envoi vaut mieux que de le laisser découvrir trois fois.
class KycService {
  const KycService(this._api);

  final PreuveTransport _api;

  /// Où en est le dossier : jamais soumis, en attente, accepté, refusé.
  Future<KycStatus> status() async {
    return KycStatus.fromJson(await _api.get('/kyc'));
  }

  /// Dépose le dossier.
  ///
  /// Le selfie ne peut pas être un document : c'est une prise de vue, et
  /// accepter un PDF permettrait de soumettre une photo de photo.
  Future<KycStatus> submit({
    required MultipartFile idFront,
    required MultipartFile idBack,
    required MultipartFile selfie,
  }) async {
    return KycStatus.fromJson(
      await _api.postMultipart(
        '/kyc',
        fields: const <String, String>{},
        files: <MultipartFile>[
          MultipartFile(
            field: 'id_front',
            filename: idFront.filename,
            bytes: idFront.bytes,
            contentType: idFront.contentType,
          ),
          MultipartFile(
            field: 'id_back',
            filename: idBack.filename,
            bytes: idBack.bytes,
            contentType: idBack.contentType,
          ),
          MultipartFile(
            field: 'selfie',
            filename: selfie.filename,
            bytes: selfie.bytes,
            contentType: selfie.contentType,
          ),
        ],
      ),
    );
  }
}

/// État du dossier d'identité.
class KycStatus {
  const KycStatus({required this.status, this.label, this.rejectionReason});

  factory KycStatus.fromJson(Map<String, Object?> json) {
    final dossier = json['kyc'] is Map<String, Object?>
        ? json['kyc']! as Map<String, Object?>
        : json;

    return KycStatus(
      status: dossier['status'] is String ? dossier['status']! as String : 'none',
      label: dossier['status_label'] is String ? dossier['status_label']! as String : null,
      // LE MOTIF DE REFUS EST RENDU À L'INTÉRESSÉ. Un dossier refusé sans
      // raison se redépose à l'identique, et se fait refuser à l'identique.
      rejectionReason: dossier['rejection_reason'] is String
          ? dossier['rejection_reason']! as String
          : null,
    );
  }

  final String status;
  final String? label;
  final String? rejectionReason;

  bool get isVerified => status == 'verified';

  bool get isPending => status == 'pending' || status == 'submitted';

  /// Vrai quand il reste quelque chose à faire à l'utilisateur.
  bool get needsAction => !isVerified && !isPending;
}
