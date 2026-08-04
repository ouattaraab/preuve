import 'transport.dart';

/// Pré-remplissage par photo de la carte grise (ST-0202).
///
/// LE SCAN PROPOSE, IL N'ENREGISTRE JAMAIS. La valeur lue doit être présentée
/// dans un champ MODIFIABLE, jamais validée en silence : un identifiant mal lu
/// est pire qu'un identifiant non lu, parce que personne ne relit dix-sept
/// caractères — et l'erreur ne se découvrirait qu'au moment où le bien compte.
///
/// LA CATÉGORIE N'EST PAS DEVINÉE. Une carte grise ne dit pas si le catalogue
/// range l'engin en « voiture » ou en « moto », et pré-choisir change tout le
/// formulaire.
///
/// [ScanResult.scanId] SE RENVOIE À L'ENREGISTREMENT : c'est lui qui permet de
/// mesurer combien de propositions survivent intactes jusqu'à la soumission.
/// Sans cette mesure, on ne saurait pas si la fonction sert.
class ScanService {
  const ScanService(this._api);

  final PreuveTransport _api;

  Future<ScanResult> read(MultipartFile fichier, {String docType = 'registration_card'}) async {
    final body = await _api.postMultipart(
      '/assets/scan',
      fields: <String, String>{'doc_type': docType},
      files: <MultipartFile>[
        MultipartFile(
          field: 'file',
          filename: fichier.filename,
          bytes: fichier.bytes,
          contentType: fichier.contentType,
        ),
      ],
    );

    return ScanResult.fromJson(body);
  }
}

class ScanResult {
  const ScanResult({
    required this.scanId,
    this.identifier,
    this.attributes = const <String, Object?>{},
    this.confidence,
  });

  factory ScanResult.fromJson(Map<String, Object?> json) {
    final attributs = json['attributes'];

    return ScanResult(
      scanId: json['scan_id'] is int ? json['scan_id']! as int : 0,
      // `null` sans détour quand la lecture a échoué : pas de valeur approchée,
      // qui serait recopiée sans être vérifiée.
      identifier: json['identifier'] is String ? json['identifier']! as String : null,
      attributes: attributs is Map<String, Object?> ? attributs : const <String, Object?>{},
      confidence: json['confidence'] is num ? (json['confidence']! as num).toDouble() : null,
    );
  }

  final int scanId;
  final String? identifier;
  final Map<String, Object?> attributes;
  final double? confidence;

  bool get hasIdentifier => identifier != null && identifier!.isNotEmpty;
}
