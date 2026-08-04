import 'package:preuve_core/preuve_core.dart';

import 'fichiers.dart';
import 'secure_upload_store.dart';

/// Ce que l'application tient en main pendant toute une session.
///
/// UN SEUL ENDROIT SAIT SI QUELQU'UN EST CONNECTÉ. Sans cela, chaque écran
/// finirait par tenir sa propre idée de l'état du compte, et deux écrans en
/// désaccord sur ce point proposeraient l'un une action que l'autre refuse.
///
/// LE COMPTE Y EST CONSERVÉ POUR SON NUMÉRO, et non par confort : déclarer un
/// vol, céder ou réclamer exigent un code à usage unique, et le code s'envoie à
/// un numéro. Un jeton restauré au lancement ne dit pas lequel — d'où
/// [reprendre], qui va le demander au serveur.
class PreuveSession {
  PreuveSession({
    required this.api,
    required this.auth,
    required this.lookups,
    required this.assets,
    required this.lifecycle,
    required this.transfers,
    required this.claims,
    required this.notifications,
    required this.kyc,
    required this.envois,
  });

  factory PreuveSession.pour(PreuveApi api, TokenStore coffre) {
    return PreuveSession(
      api: api,
      auth: AuthService(api, coffre),
      lookups: LookupService(api),
      assets: AssetService(api),
      lifecycle: LifecycleService(api),
      transfers: TransferService(api),
      claims: ClaimService(api),
      notifications: NotificationService(api),
      kyc: KycService(api),
      envois: UploadManager(
        // La lecture du fichier est INJECTÉE : c'est ce qui permet d'éprouver
        // toute la reprise dans une console, sans appareil.
        queue: UploadQueue(transport: api, readChunk: lireMorceau),
        store: const SecureUploadStore(),
      ),
    );
  }

  final PreuveApi api;
  final AuthService auth;
  final LookupService lookups;
  final AssetService assets;
  final LifecycleService lifecycle;
  final TransferService transfers;
  final ClaimService claims;
  final NotificationService notifications;
  final KycService kyc;

  /// File d'envoi différée, avec reprise (ST-0206, CT-05).
  final UploadManager envois;

  Account? compte;

  bool get estConnecte => compte != null;

  /// Reprend une session enregistrée sur l'appareil, s'il y en a une.
  ///
  /// NE FAIT JAMAIS ÉCHOUER LE LANCEMENT. L'application ouvre sur la
  /// consultation, qui ne demande aucun compte (règle métier absolue n° 1) :
  /// un serveur injoignable au démarrage doit laisser passer quelqu'un qui
  /// vient seulement vérifier une moto au marché.
  Future<void> reprendre() async {
    try {
      // LA FILE D'ABORD, ET HORS DE TOUTE CONDITION DE SESSION : des pièces
      // peuvent attendre depuis des jours, et les relire ne coûte rien. Ne les
      // charger qu'une fois connecté ferait disparaître l'écran de suivi
      // exactement quand quelqu'un vient y vérifier que sa carte grise est
      // partie.
      await envois.restore();

      if (!await auth.restore()) {
        return;
      }

      compte = await auth.me();
    } on PreuveException {
      // Jeton révoqué, expiré, ou réseau absent. `me()` a déjà vidé le coffre
      // dans le premier cas ; dans les autres, la prochaine action proposera
      // de se connecter.
      compte = null;
    }
  }

  Future<void> fermer() async {
    await auth.logout();
    compte = null;
  }
}
