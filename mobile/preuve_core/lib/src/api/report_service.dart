import '../models/report.dart';
import 'transport.dart';

/// Rapport détaillé d'un bien qu'on ne détient pas (ST-0801).
///
/// GRATUIT OU PAYANT, C'EST LE SERVEUR QUI TRANCHE. Le tarif est un réglage
/// d'exploitation : un montant embarqué dans l'application réclamerait de
/// l'argent le jour où l'administrateur vient de rendre le rapport gratuit, sur
/// des téléphones qui ne se mettent pas à jour.
///
/// LE RETOUR DE PAIEMENT NE PROUVE RIEN. Ce qui accorde l'accès est le webhook
/// signé de l'opérateur ; l'application, elle, ne fait que RELIRE l'état. Sans
/// cette règle, il suffirait de revenir dans l'application en annonçant « c'est
/// payé » pour obtenir un rapport sans payer.
class ReportService {
  const ReportService(this._api);

  final PreuveTransport _api;

  /// Ouvre l'accès au rapport : soit gratuitement, soit en rendant l'adresse
  /// où régler.
  /// [publicRef] est la référence opaque lue sur le verdict : un acheteur ne
  /// connaît pas l'identifiant interne du bien, et c'est voulu — le publier
  /// permettrait de balayer le registre.
  Future<ReportOrder> order(String publicRef, {String provider = 'paystack'}) async {
    final body = await _api.post('/reports', body: <String, Object?>{
      'public_ref': publicRef,
      'provider': provider,
    });

    return ReportOrder.fromJson(body);
  }

  /// Lit le rapport par son jeton.
  ///
  /// SANS AUTHENTIFICATION, délibérément : le jeton EST le droit d'accès, et il
  /// a été acheté par une personne identifiée. C'est ce qui permet d'ouvrir un
  /// rapport reçu par message sur un autre appareil.
  Future<Map<String, Object?>> read(String token) {
    return _api.getAnonymous('/reports/access/$token');
  }
}
