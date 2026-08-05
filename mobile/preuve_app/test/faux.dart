import 'package:preuve_app/data/session.dart';
import 'package:preuve_core/preuve_core.dart';

/// Transport factice : rejoue des réponses écrites à la main, et enregistre ce
/// qui a été demandé.
///
/// LES TESTS NE SORTENT PAS SUR LE RÉSEAU. Un test qui joindrait `preuve.click`
/// échouerait un jour pour une raison qui n'a rien à voir avec le code, et on
/// prendrait l'habitude d'ignorer ses échecs — y compris celui qui, un jour,
/// désignerait un vrai défaut.
class FauxTransport implements PreuveTransport {
  final List<Object> _reponses = <Object>[];

  final List<String> appels = <String>[];

  void enfile(Object reponse) => _reponses.add(reponse);

  Future<Map<String, Object?>> _prochaine(String trace) async {
    appels.add(trace);

    if (_reponses.isEmpty) {
      return const <String, Object?>{};
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
  Future<Map<String, Object?>> post(String path, {Map<String, Object?>? body}) =>
      _prochaine('POST $path');

  @override
  Future<Map<String, Object?>> put(String path, {Map<String, Object?>? body}) =>
      _prochaine('PUT $path');

  @override
  Future<Map<String, Object?>> delete(String path, {Map<String, Object?>? body}) =>
      _prochaine('DELETE $path');

  @override
  Future<Map<String, Object?>> patchBytes(
    String path,
    List<int> bytes, {
    required int offset,
  }) =>
      _prochaine('PATCH $path');

  @override
  Future<Map<String, Object?>> postMultipart(
    String path, {
    required Map<String, String> fields,
    List<MultipartFile> files = const <MultipartFile>[],
    bool anonymous = false,
  }) =>
      _prochaine('POST(multipart) $path');

  @override
  void setToken(String? token) {}

  @override
  bool get isAuthenticated => true;
}

/// Coffre en mémoire : un test ne touche pas au trousseau de la machine.
class FauxCoffre implements TokenStore {
  @override
  Future<String?> read() async => null;

  @override
  Future<void> write(String token) async {}

  @override
  Future<void> clear() async {}
}

class FauxMagasin implements PendingUploadStore {
  @override
  Future<List<Map<String, Object?>>> read() async => const <Map<String, Object?>>[];

  @override
  Future<void> write(List<Map<String, Object?>> entries) async {}
}

PreuveSession fauxSession(FauxTransport transport) {
  return PreuveSession(
    api: PreuveApi(baseUrl: 'https://exemple.invalide', appVersion: '0.1.0'),
    auth: AuthService(transport, FauxCoffre()),
    lookups: LookupService(transport),
    assets: AssetService(transport),
    lifecycle: LifecycleService(transport),
    transfers: TransferService(transport),
    claims: ClaimService(transport),
    notifications: NotificationService(transport),
    reports: ReportService(transport),
    fleet: FleetService(transport),
    scans: ScanService(transport),
    kyc: KycService(transport),
    envois: UploadManager(
      queue: UploadQueue(
        transport: transport,
        readChunk: (String path, int offset, int longueur) async => const <int>[],
      ),
      store: FauxMagasin(),
    ),
  );
}
