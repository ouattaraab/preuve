import 'transport.dart';

/// Le péage de déclaration de vol, quand un exploitant en ouvre un (ST-0604).
///
/// À ZÉRO PAR DÉFAUT, ET L'APPLICATION DOIT LE TRAITER COMME LE CAS NORMAL.
/// Déclarer un vol est ce qui rend un bien invendable ; celui qui déclare vient
/// de se faire dépouiller. Tant que le tarif vaut zéro, ce service répond
/// « gratuit, déjà réglé » et l'écran enchaîne sur le code sans jamais parler
/// d'argent. Un client qui afficherait un écran de paiement à 0 FCFA ajouterait
/// un obstacle là où il n'y en a pas.
///
/// L'ORDRE EST PAYER, PUIS RECEVOIR LE CODE. Le serveur émet le code quand
/// l'opérateur a confirmé : demandé avant, il aurait expiré pendant la
/// traversée de la page bancaire.
class TheftFeeService {
  const TheftFeeService(this._api);

  final PreuveTransport _api;

  /// Ce que la déclaration coûte à cet utilisateur, pour ce bien, maintenant.
  Future<TheftFee> state(int assetId) async {
    return TheftFee.fromJson(await _api.get('/assets/$assetId/theft-fee'));
  }

  /// Ouvre le paiement qui débloquera l'envoi du code.
  Future<TheftFee> pay(int assetId) async {
    return TheftFee.fromJson(await _api.post('/assets/$assetId/theft-fee'));
  }
}

class TheftFee {
  const TheftFee({
    this.feeFcfa = 0,
    this.free = true,
    this.paid = true,
    this.alreadyStolen = false,
    this.checkoutUrl,
    this.message,
    this.explanation,
  });

  factory TheftFee.fromJson(Map<String, Object?> json) {
    final montant = json['fee_fcfa'] is int ? json['fee_fcfa']! as int : 0;

    return TheftFee(
      feeFcfa: montant,
      // GRATUIT PAR DÉFAUT, y compris si le serveur ne dit rien : une réponse
      // tronquée doit ouvrir la déclaration, jamais la fermer. Le serveur
      // refusera de son côté si un règlement manque — c'est lui qui tranche,
      // et se tromper dans ce sens ne coûte qu'un aller-retour.
      free: json['free'] == true || montant == 0,
      paid: json['paid'] != false || montant == 0,
      alreadyStolen: json['already_stolen'] == true,
      checkoutUrl: json['checkout_url'] is String ? json['checkout_url']! as String : null,
      message: json['message'] is String ? json['message']! as String : null,
      explanation: json['explanation'] is String ? json['explanation']! as String : null,
    );
  }

  final int feeFcfa;
  final bool free;

  /// Vrai quand rien n'est dû : gratuit, ou déjà réglé pour ce bien.
  final bool paid;

  final bool alreadyStolen;

  /// Où régler. Nul quand rien n'est dû, ou quand aucune passerelle n'est
  /// branchée — l'écran ne doit alors proposer aucun bouton mort.
  final String? checkoutUrl;

  final String? message;
  final String? explanation;

  /// Le seul test que l'écran ait à faire avant d'ouvrir la saisie du code.
  bool get bloque => !paid;
}
