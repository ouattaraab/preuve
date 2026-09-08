import 'transport.dart';

/// Mettre SON bien volé en avant sur la liste publique (ST-0805).
///
/// CE QUE CE SERVICE ACHÈTE, ET CE QU'IL N'ACHÈTE PAS. Déclarer un vol rend le
/// bien invendable pour quiconque vérifie son numéro : c'est la protection,
/// elle est immédiate et gratuite. Ceci paie la VISIBILITÉ — figurer sur la
/// liste que tout le monde parcourt, sans qu'on ait besoin de connaître le
/// numéro. L'écran doit dire cette différence, sans quoi on demande de l'argent
/// pour ce qui est déjà acquis.
class StolenListingService {
  const StolenListingService(this._api);

  final PreuveTransport _api;

  Future<ListingState> state(int assetId) async {
    return ListingState.fromJson(await _api.get('/assets/$assetId/stolen-listing'));
  }

  /// Publie, ou ouvre le paiement qui publiera.
  Future<ListingState> publish(int assetId) async {
    return ListingState.fromJson(await _api.post('/assets/$assetId/stolen-listing'));
  }

  /// Retire de la liste. TOUJOURS GRATUIT : un bien retrouvé ne doit pas rester
  /// exposé parce qu'on avait payé.
  Future<ListingState> withdraw(int assetId) async {
    return ListingState.fromJson(await _api.delete('/assets/$assetId/stolen-listing'));
  }
}

class ListingState {
  const ListingState({
    this.listed = false,
    this.priceFcfa = 0,
    this.free = true,
    this.checkoutUrl,
    this.message,
    this.explanation,
  });

  factory ListingState.fromJson(Map<String, Object?> json) {
    return ListingState(
      listed: json['listed'] == true,
      priceFcfa: json['price_fcfa'] is int ? json['price_fcfa']! as int : 0,
      free: json['free'] == true || (json['price_fcfa'] is int && json['price_fcfa'] == 0),
      checkoutUrl: json['checkout_url'] is String ? json['checkout_url']! as String : null,
      message: json['message'] is String ? json['message']! as String : null,
      explanation: json['explanation'] is String ? json['explanation']! as String : null,
    );
  }

  final bool listed;
  final int priceFcfa;
  final bool free;

  /// Où régler. Nul quand c'est gratuit, ou quand aucune passerelle n'est
  /// branchée — l'écran ne doit alors proposer aucun bouton mort.
  final String? checkoutUrl;

  final String? message;

  /// CE QUE LA PUBLICATION APPORTE, rendu par le serveur. Sans cette phrase, on
  /// demande de l'argent pour une notion abstraite.
  final String? explanation;
}
