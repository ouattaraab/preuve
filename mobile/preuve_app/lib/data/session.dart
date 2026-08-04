import 'package:preuve_core/preuve_core.dart';

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
    );
  }

  final PreuveApi api;
  final AuthService auth;
  final LookupService lookups;
  final AssetService assets;
  final LifecycleService lifecycle;
  final TransferService transfers;
  final ClaimService claims;

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
