import '../identifiers.dart';
import '../models/lookup.dart';
import '../version.dart';
import 'client.dart';
import 'exceptions.dart';

/// Consultation publique et configuration de démarrage.
///
/// C'EST LE SEUL PARCOURS QUI NE DOIT JAMAIS DEMANDER DE COMPTE, ni de version
/// à jour, ni quoi que ce soit d'autre qu'un identifiant. Toute condition
/// ajoutée ici trahirait la promesse du produit (règle métier absolue n° 1).
class LookupService {
  const LookupService(this._api);

  final PreuveApi _api;

  /// Vérifie un identifiant.
  ///
  /// [captchaToken] n'est présenté qu'APRÈS un refus pour plafond atteint : sur
  /// le chemin nominal, il coûterait un aller-retour vers Cloudflare que CT-01
  /// ne permet pas, et brûlerait un jeton à usage unique sans nécessité.
  Future<LookupResult> check(String identifier, {String? captchaToken}) async {
    final normalized = IdentifierNormalizer.normalize(identifier);

    try {
      final body = await _api.getAnonymous(
        '/lookup/$normalized',
        headers: captchaToken == null ? null : <String, String>{'X-Captcha-Token': captchaToken},
      );

      return LookupResult.fromJson(body);
    } on InvalidRequest catch (e) {
      // Le serveur rend 422 avec le verdict complet : une saisie inexploitable
      // n'est pas une panne, et l'utilisateur doit lire pourquoi.
      return LookupResult(outcome: LookupOutcome.invalid, message: e.message);
    } on RateLimited catch (e) {
      return LookupResult(outcome: LookupOutcome.rateLimited, message: e.message);
    }
  }

  /// Version minimale exigée, lue au démarrage.
  ///
  /// Un échec réseau ici ne doit RIEN bloquer : une application qui refuserait
  /// de démarrer parce qu'elle n'a pas joint le serveur serait inutilisable
  /// exactement là où elle sert le plus — en bord de route, sur une 3G qui
  /// vacille.
  Future<AppRelease> release() async {
    try {
      return AppRelease.fromJson(await _api.getAnonymous('/config/app'));
    } on PreuveException {
      return const AppRelease();
    }
  }
}
