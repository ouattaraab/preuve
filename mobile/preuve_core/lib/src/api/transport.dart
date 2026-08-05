/// Ce que les services attendent du réseau, et rien de plus.
///
/// LES SERVICES DÉPENDENT DE CETTE INTERFACE, PAS DE `PreuveApi`. C'est ce qui
/// permet de les éprouver sans réseau : un test qui devrait joindre
/// `preuve.click` échouerait un jour pour une raison qui n'a rien à voir avec
/// le code, et on prendrait l'habitude d'ignorer ses échecs — y compris celui
/// qui, un jour, désignerait un vrai défaut.
///
/// Elle ne connaît ni codes de statut ni corps bruts : l'implémentation traduit
/// déjà les refus en exceptions typées (voir `exceptions.dart`), et un service
/// qui inspecterait un code numérique referait ce travail à sa manière.
library;

abstract interface class PreuveTransport {
  /// Appel SANS jeton, quel que soit l'état de la session.
  ///
  /// Réservé à la consultation : c'est ce qui garantit qu'aucun historique
  /// nominatif ne se constitue à l'insu de qui vérifie un bien avant d'acheter.
  Future<Map<String, Object?>> getAnonymous(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  });

  Future<Map<String, Object?>> get(String path, {Map<String, String>? query});

  Future<Map<String, Object?>> post(String path, {Map<String, Object?>? body});

  Future<Map<String, Object?>> put(String path, {Map<String, Object?>? body});

  Future<Map<String, Object?>> delete(String path, {Map<String, Object?>? body});

  /// Envoi d'un morceau binaire, à une position donnée.
  Future<Map<String, Object?>> patchBytes(
    String path,
    List<int> bytes, {
    required int offset,
  });

  /// Envoi d'un formulaire avec pièce jointe, en un seul appel.
  ///
  /// EXISTE PARCE QUE LE SERVEUR L'EXIGE, et non parce que c'est le bon moyen
  /// de transporter huit mégaoctets sur une 3G de bord de route : les pièces
  /// d'une réclamation sont attendues en `multipart`, sans reprise possible.
  /// Tout le reste des envois passe par `UploadQueue`, qui reprend là où la
  /// coupure a eu lieu (ST-0206, CT-05) ; une pièce de réclamation coupée à
  /// 90 % est à renvoyer depuis le début. À corriger côté serveur le jour où
  /// les dossiers porteront des pièces lourdes.
  ///
  /// [anonymous] envoie SANS le jeton de session, même s'il y en a un.
  ///
  /// Le scan de consultation est ouvert à qui n'a pas de compte ; y attacher le
  /// jeton d'un utilisateur par ailleurs connecté ferait porter au registre la
  /// trace de QUI a photographié quelle carte grise, sur le parcours dont
  /// l'anonymat est la promesse (règle métier n° 1).
  Future<Map<String, Object?>> postMultipart(
    String path, {
    required Map<String, String> fields,
    List<MultipartFile> files = const <MultipartFile>[],
    bool anonymous = false,
  });

  /// Envoi JSON SANS le jeton de session, même s'il y en a un.
  ///
  /// Le scan de consultation est ouvert à qui n'a pas de compte ; y attacher le
  /// jeton d'un utilisateur par ailleurs connecté ferait porter au registre la
  /// trace de QUI a photographié quelle carte grise, sur le parcours dont
  /// l'anonymat est la promesse (règle métier n° 1).
  Future<Map<String, Object?>> postAnonymous(String path, {Map<String, Object?>? body});

  /// Jeton de session, ou `null` pour le retirer.
  void setToken(String? token);

  bool get isAuthenticated;
}

/// Une pièce jointe, déjà lue en mémoire.
///
/// LES OCTETS SONT FOURNIS, JAMAIS UN CHEMIN. Ce paquet ne connaît pas de
/// système de fichiers — c'est ce qui permet de l'éprouver entièrement dans une
/// console, sans appareil — et c'est la même discipline que `UploadQueue`, qui
/// se fait injecter sa lecture.
class MultipartFile {
  const MultipartFile({
    required this.field,
    required this.filename,
    required this.bytes,
    this.contentType = 'application/octet-stream',
  });

  /// Nom du champ attendu par le serveur (« file »).
  final String field;

  final String filename;
  final List<int> bytes;
  final String contentType;
}
