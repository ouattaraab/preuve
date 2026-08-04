import 'package:preuve_core/preuve_core.dart';

/// Transport factice : rejoue des réponses écrites à la main.
///
/// LES TESTS NE SORTENT PAS SUR LE RÉSEAU. Un test qui joindrait
/// `preuve.click` échouerait un jour pour une raison qui n'a rien à voir avec
/// le code, et on prendrait l'habitude d'ignorer ses échecs — y compris celui
/// qui, un jour, désignerait un vrai défaut. La sonde de production existe
/// séparément, et se lance à la main.
class FakeTransport implements PreuveTransport {
  FakeTransport();

  /// Réponses à rendre, dans l'ordre, par méthode et chemin.
  final List<Object> _reponses = <Object>[];

  /// Journal de ce qui a été demandé : c'est lui qui permet de vérifier qu'un
  /// réessai n'a pas ouvert une seconde session.
  final List<String> appels = <String>[];

  final List<int> offsetsEnvoyes = <int>[];

  /// Corps réellement transmis, dans l'ordre.
  ///
  /// SANS LUI, TROIS APPELS ONT ÉTÉ ÉCRITS AVEC DE MAUVAIS NOMS DE CHAMPS et
  /// leurs tests passaient : le transport factice ne regardait que le chemin.
  /// Un harnais qui ne peut pas constater un contrat rompu ne prouve rien de ce
  /// qu'il prétend prouver.
  final List<Map<String, Object?>> corpsEnvoyes = <Map<String, Object?>>[];

  /// Pièces jointes transmises, pour les envois `multipart`.
  final List<MultipartFile?> fichiersEnvoyes = <MultipartFile?>[];

  String? token;

  /// Dernier corps transmis, à défaut une carte vide.
  Map<String, Object?> get dernierCorps =>
      corpsEnvoyes.isEmpty ? const <String, Object?>{} : corpsEnvoyes.last;

  /// Empile une réponse (map) ou une exception à lever.
  void enfile(Object reponse) => _reponses.add(reponse);

  Future<Map<String, Object?>> _prochaine(String trace) async {
    appels.add(trace);

    if (_reponses.isEmpty) {
      throw StateError('Aucune réponse en file pour $trace');
    }

    final reponse = _reponses.removeAt(0);

    if (reponse is PreuveException) {
      throw reponse;
    }

    return reponse as Map<String, Object?>;
  }

  @override
  Future<Map<String, Object?>> getAnonymous(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) =>
      _prochaine('GET(anonyme) $path');

  @override
  Future<Map<String, Object?>> get(String path, {Map<String, String>? query}) =>
      _prochaine('GET $path');

  @override
  Future<Map<String, Object?>> post(String path, {Map<String, Object?>? body}) {
    corpsEnvoyes.add(body ?? const <String, Object?>{});

    return _prochaine('POST $path');
  }

  @override
  Future<Map<String, Object?>> put(String path, {Map<String, Object?>? body}) {
    corpsEnvoyes.add(body ?? const <String, Object?>{});

    return _prochaine('PUT $path');
  }

  @override
  Future<Map<String, Object?>> delete(String path, {Map<String, Object?>? body}) {
    corpsEnvoyes.add(body ?? const <String, Object?>{});

    return _prochaine('DELETE $path');
  }

  @override
  Future<Map<String, Object?>> postMultipart(
    String path, {
    required Map<String, String> fields,
    MultipartFile? file,
  }) {
    corpsEnvoyes.add(Map<String, Object?>.from(fields));
    fichiersEnvoyes.add(file);

    return _prochaine('POST(multipart) $path');
  }

  @override
  Future<Map<String, Object?>> patchBytes(
    String path,
    List<int> bytes, {
    required int offset,
  }) {
    offsetsEnvoyes.add(offset);

    return _prochaine('PATCH $path @$offset (${bytes.length} o)');
  }

  @override
  void setToken(String? value) => token = value;

  @override
  bool get isAuthenticated => token != null;
}
