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
    return ScanResult.fromJson(await _api.postMultipart(
      '/assets/scan',
      fields: <String, String>{'doc_type': docType},
      files: <MultipartFile>[_piece(fichier)],
    ));
  }

  /// Lit une carte grise POUR CONSULTER, sans compte.
  ///
  /// ROUTE DISTINCTE, ET SANS JETON MÊME SI L'ON EN A UN. Celui à qui l'on
  /// propose une moto sur un parking n'a pas de compte, et c'est lui à qui
  /// recopier dix-sept caractères de châssis coûte le plus — l'erreur de
  /// recopie est le premier motif de « bien introuvable ». Joindre le jeton
  /// d'un utilisateur par ailleurs connecté ferait porter au registre la trace
  /// de QUI a photographié quelle carte grise, sur le parcours dont l'anonymat
  /// est justement la promesse (règle métier n° 1).
  ///
  /// ELLE NE REND PAS LE STATUT DU BIEN, et c'est voulu : ce serait une
  /// consultation échappant au journal, aux compteurs de trente jours et au
  /// plafond horaire. L'appelant met le numéro lu dans un champ MODIFIABLE et
  /// laisse l'utilisateur lancer la vérification lui-même.
  Future<ScanResult> readForLookup(
    MultipartFile fichier, {
    String docType = 'registration_card',
    String? captchaToken,
  }) async {
    return ScanResult.fromJson(await _api.postMultipart(
      '/lookup/scan',
      fields: <String, String>{
        'doc_type': docType,
        if (captchaToken != null && captchaToken.isNotEmpty) 'captcha_token': captchaToken,
      },
      files: <MultipartFile>[_piece(fichier)],
      anonymous: true,
    ));
  }

  /// Sélection à partir de mots DÉJÀ LUS SUR L'APPAREIL, sans compte.
  ///
  /// AUCUNE IMAGE NE CIRCULE : une carte grise porte le nom et l'adresse de son
  /// propriétaire, et il n'a jamais fallu l'envoyer pour en extraire dix-sept
  /// caractères. Ne partent que des mots — quelques centaines d'octets, ce qui
  /// change tout sur une 3G de bord de route (CT-05).
  ///
  /// LA SÉLECTION RESTE AU SERVEUR. Le téléphone lit ; le chiffre de contrôle
  /// du VIN, le Luhn de l'IMEI et l'ordre de priorité sont des règles métier,
  /// et les embarquer ici les ferait se périmer sur des téléphones qui ne se
  /// mettent pas à jour.
  Future<ScanResult> readWordsForLookup(
    List<String> mots, {
    String docType = 'registration_card',
  }) async {
    return ScanResult.fromJson(await _api.postAnonymous(
      '/lookup/scan/text',
      body: <String, Object?>{'doc_type': docType, 'words': mots},
    ));
  }

  /// Le même, pour l'enregistrement : la trace est alors rattachée au compte,
  /// parce que c'est elle qui mesure si le pré-remplissage tient jusqu'à la
  /// soumission.
  Future<ScanResult> readWords(
    List<String> mots, {
    String docType = 'registration_card',
  }) async {
    return ScanResult.fromJson(await _api.post(
      '/assets/scan/text',
      body: <String, Object?>{'doc_type': docType, 'words': mots},
    ));
  }

  static MultipartFile _piece(MultipartFile fichier) => MultipartFile(
        field: 'file',
        filename: fichier.filename,
        bytes: fichier.bytes,
        contentType: fichier.contentType,
      );
}

class ScanResult {
  const ScanResult({
    required this.scanId,
    this.identifier,
    this.identifierType,
    this.attributes = const <String, Object?>{},
    this.confidence,
    this.message,
    this.available = true,
  });

  factory ScanResult.fromJson(Map<String, Object?> json) {
    final Object? attributs = json['attributes'];

    // LE SERVEUR REND UN OBJET `{value, type}`, PAS UNE CHAÎNE. Lu comme une
    // chaîne, `identifier` valait TOUJOURS null : le pré-remplissage entier ne
    // remplissait rien, en silence, et le formulaire s'ouvrait vide comme si
    // le document avait été illisible. Relevé sur le contrôleur, pas deviné.
    final Object? lu = json['identifier'];
    final Map<String, Object?> propose = lu is Map<String, Object?> ? lu : const <String, Object?>{};

    return ScanResult(
      scanId: json['scan_id'] is int ? json['scan_id']! as int : 0,
      // `null` sans détour quand la lecture a échoué : pas de valeur approchée,
      // qui serait recopiée sans être vérifiée.
      identifier: propose['value'] is String ? propose['value']! as String : null,
      identifierType: propose['type'] is String ? propose['type']! as String : null,
      attributes: attributs is Map<String, Object?> ? attributs : const <String, Object?>{},
      confidence: json['confidence'] is num ? (json['confidence']! as num).toDouble() : null,
      message: json['message'] is String ? json['message']! as String : null,
      // Absent = disponible : seule l'indisponibilité est annoncée, et un
      // serveur plus ancien ne connaît pas ce champ.
      available: json['scan_available'] != false,
    );
  }

  final int scanId;
  final String? identifier;

  /// `vin`, `imei`, `plate`… Le serveur le déduit du chiffre de contrôle ou du
  /// format ; le client s'en sert pour PROPOSER une catégorie, jamais pour en
  /// choisir une — une carte grise ne dit pas si le catalogue range l'engin en
  /// « voiture » ou en « moto », et pré-choisir change tout le formulaire.
  final String? identifierType;
  final Map<String, Object?> attributes;
  final double? confidence;

  /// Ce que le serveur veut qu'on dise. TOUJOURS AFFICHÉ, réussite comme échec :
  /// un scan qui ne remplit rien sans rien dire laisse croire à une panne, et
  /// un scan réussi doit demander une relecture — un numéro mal lu est pire
  /// qu'un numéro non lu, parce que personne ne recompte dix-sept caractères
  /// qu'une machine a proposés.
  final String? message;

  /// Faux quand aucun fournisseur d'extraction n'est branché.
  ///
  /// À DISTINGUER D'UNE LECTURE RATÉE. « Illisible » invite à refaire la photo ;
  /// ici, la refaire ne servirait à rien, et l'écran doit renvoyer à la saisie
  /// manuelle plutôt qu'à un second essai.
  final bool available;

  bool get hasIdentifier => identifier != null && identifier!.isNotEmpty;
}
