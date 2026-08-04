/// Ce que le serveur rend quand on demande un rapport détaillé.
///
/// TROIS ISSUES, ET ELLES N'APPELLENT PAS LE MÊME ÉCRAN : c'est gratuit et le
/// rapport s'ouvre ; c'est payant et il faut aller régler ; ou l'opérateur n'est
/// pas joignable et il n'y a rien à faire pour l'instant.
class ReportOrder {
  const ReportOrder({
    required this.free,
    this.accessToken,
    this.checkoutUrl,
    this.amountFcfa,
    this.message,
  });

  factory ReportOrder.fromJson(Map<String, Object?> json) {
    final paiement = json['payment'];

    return ReportOrder(
      free: json['free'] == true,
      accessToken: json['access_token'] is String ? json['access_token']! as String : null,
      checkoutUrl: json['checkout_url'] is String ? json['checkout_url']! as String : null,
      amountFcfa: paiement is Map<String, Object?> && paiement['amount_fcfa'] is int
          ? paiement['amount_fcfa']! as int
          : null,
      message: json['message'] is String ? json['message']! as String : null,
    );
  }

  /// Vrai quand le tarif est à zéro : aucun paiement n'a été ouvert, et le
  /// jeton est déjà là.
  final bool free;

  /// Le jeton d'accès. Présent d'emblée si c'est gratuit ; sinon il arrivera
  /// après confirmation de l'opérateur.
  final String? accessToken;

  /// Où aller régler. C'est une adresse de l'OPÉRATEUR, pas de la plateforme.
  final String? checkoutUrl;

  final int? amountFcfa;
  final String? message;

  bool get needsPayment => !free && checkoutUrl != null;
}
