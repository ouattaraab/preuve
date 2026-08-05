import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/screens/lookup_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

/// Ce que ces tests éprouvent, ce sont les PROMESSES DU PRODUIT à l'écran.
///
/// Les règles métier sont déjà vérifiées dans `preuve_core`, sans appareil ni
/// émulateur. Ce qui ne peut se vérifier qu'ici, c'est ce que l'interface
/// propose ou refuse — et la première promesse est qu'on vérifie un bien sans
/// compte.
class _TransportMuet implements PreuveTransport {
  @override
  Future<Map<String, Object?>> getAnonymous(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) async =>
      const <String, Object?>{};

  @override
  Future<Map<String, Object?>> get(String path, {Map<String, String>? query}) async =>
      const <String, Object?>{};

  @override
  Future<Map<String, Object?>> post(String path, {Map<String, Object?>? body}) async =>
      const <String, Object?>{};

  @override
  Future<Map<String, Object?>> put(String path, {Map<String, Object?>? body}) async =>
      const <String, Object?>{};

  @override
  Future<Map<String, Object?>> delete(String path, {Map<String, Object?>? body}) async =>
      const <String, Object?>{};

  @override
  Future<Map<String, Object?>> patchBytes(
    String path,
    List<int> bytes, {
    required int offset,
  }) async =>
      const <String, Object?>{};

  @override
  Future<Map<String, Object?>> postMultipart(
    String path, {
    required Map<String, String> fields,
    List<MultipartFile> files = const <MultipartFile>[],
    bool anonymous = false,
  }) async =>
      const <String, Object?>{};

  @override
  void setToken(String? token) {}

  @override
  bool get isAuthenticated => false;
}

/// Coffre en mémoire : un test ne touche pas au trousseau de la machine.
class _CoffreMuet implements TokenStore {
  @override
  Future<String?> read() async => null;

  @override
  Future<void> write(String token) async {}

  @override
  Future<void> clear() async {}
}

class _MagasinMuet implements PendingUploadStore {
  @override
  Future<List<Map<String, Object?>>> read() async => const <Map<String, Object?>>[];

  @override
  Future<void> write(List<Map<String, Object?>> entries) async {}
}

PreuveSession _session() {
  final transport = _TransportMuet();

  return PreuveSession(
    api: PreuveApi(baseUrl: 'https://exemple.invalide', appVersion: '0.1.0'),
    auth: AuthService(transport, _CoffreMuet()),
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
      store: _MagasinMuet(),
    ),
  );
}

Widget _app(Widget ecran) => MaterialApp(theme: Djassa.build(), home: ecran);

void main() {
  test('le thème se construit — il levait une assertion et faisait tout tomber', () {
    // DÉFAUT RÉEL, TROUVÉ À LA PREMIÈRE COMPILATION. `ThemeData.textTheme` ne
    // porte que les couleurs ; y appliquer un facteur d'agrandissement lève une
    // assertion. L'application plantait au démarrage, sur TOUS les écrans — y
    // compris la consultation, qui est la seule chose qui ne doit jamais tomber.
    final theme = Djassa.build();

    expect(theme.textTheme.bodyMedium?.fontSize, isNotNull);
    expect(theme.textTheme.displayLarge?.fontSize, isNotNull);
  });

  test('les tailles sont réellement agrandies', () {
    // Sinon le correctif rendrait un thème valide et une lisibilité perdue : on
    // lit cette application debout, en plein soleil, parfois de loin.
    final ordinaire = Typography.material2021().englishLike.bodyMedium?.fontSize;
    final notre = Djassa.build().textTheme.bodyMedium?.fontSize;

    expect(notre, isNotNull);
    expect(notre! > ordinaire!, isTrue, reason: '$notre ≤ $ordinaire');
  });

  testWidgets('l\'accueil vérifie un bien sans jamais demander de compte', (
    WidgetTester tester,
  ) async {
    // RÈGLE MÉTIER ABSOLUE N° 1, à l'écran. Toute condition ajoutée ici — un
    // accueil, un tutoriel, une invitation à s'inscrire — trahirait la promesse
    // du produit, et c'est sur cet écran qu'elle se verrait d'abord.
    await tester.pumpWidget(_app(LookupScreen(session: _session())));

    expect(find.textContaining('Avant'), findsWidgets);
    expect(find.text('JE VÉRIFIE'), findsOneWidget);
    expect(find.byType(TextField), findsOneWidget);
  });

  testWidgets('refuse sur place un châssis mal recopié, sans aller au réseau', (
    WidgetTester tester,
  ) async {
    // Un aller-retour en 3G coûte plusieurs secondes : refuser sur place ce que
    // le clavier savait déjà évite d'attendre pour l'apprendre. Et le message
    // dit QUOI FAIRE, pas ce qui est faux.
    await tester.pumpWidget(_app(LookupScreen(session: _session())));

    // Un VIN dont le chiffre de contrôle ne tombe pas juste.
    await tester.enterText(find.byType(TextField), '1M8GDM9AXKP042789');
    await tester.tap(find.text('JE VÉRIFIE'));
    await tester.pump();

    expect(find.textContaining('Recompte les 17 caractères'), findsOneWidget);
  });

  testWidgets('dit qu\'un numéro inconnu n\'est pas un feu vert', (
    WidgetTester tester,
  ) async {
    // ST-0303 : présenter une absence d'information comme rassurante ferait
    // acheter un bien volé que personne n'a déclaré.
    await tester.pumpWidget(_app(LookupScreen(session: _session())));

    // Hors de l'écran mais bâti : la mise en garde fait partie de la page, elle
    // n'attend pas un geste pour exister.
    expect(
      find.text('Un numéro inconnu n\'est pas un feu vert', skipOffstage: false),
      findsOneWidget,
    );
  });

  testWidgets('propose la connexion sans jamais l\'imposer', (WidgetTester tester) async {
    // CT-06 : la friction est réservée aux gestes risqués. L'accès au compte
    // existe, mais comme une action de barre — jamais comme un passage obligé.
    await tester.pumpWidget(_app(LookupScreen(session: _session())));

    // La pastille de l'en-tête dit la promesse AVANT toute action, et le
    // bouton de compte n'est qu'une cible parmi d'autres.
    expect(find.text('Gratuit · Sans compte'), findsOneWidget);
    expect(find.text('🔔'), findsOneWidget);
  });
}
