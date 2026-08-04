import 'lookup.dart';

/// De quel côté de la table on se trouve.
///
/// LE SERVEUR LE DIT, LE CLIENT NE LE DEVINE PAS. Une erreur de camp ferait
/// confirmer une vente à quelqu'un qui croyait accepter un bien — ou l'inverse,
/// et le transfert échouerait sans que l'écran sache dire pourquoi.
enum TransferRole {
  seller('seller'),
  buyer('buyer');

  const TransferRole(this.wire);

  final String wire;

  static TransferRole fromWire(String value) =>
      value == 'seller' ? TransferRole.seller : TransferRole.buyer;
}

/// Un transfert en attente d'un geste.
class PendingTransfer {
  const PendingTransfer({
    required this.id,
    required this.status,
    required this.statusLabel,
    required this.role,
    required this.sellerConfirmed,
    required this.buyerConfirmed,
    this.expiresAt,
    this.asset,
  });

  factory PendingTransfer.fromJson(Map<String, Object?> json) {
    final bien = json['asset'];

    return PendingTransfer(
      id: json['id'] is int ? json['id']! as int : 0,
      status: _string(json['status']),
      statusLabel: _string(json['status_label']),
      role: TransferRole.fromWire(_string(json['role'])),
      sellerConfirmed: json['seller_confirmed'] == true,
      buyerConfirmed: json['buyer_confirmed'] == true,
      expiresAt: DateTime.tryParse(_string(json['expires_at'])),
      asset: bien is Map<String, Object?> ? PublicAsset.fromJson(bien) : null,
    );
  }

  final int id;
  final String status;

  /// Libellé en langage courant, rendu par le serveur (CT-04).
  final String statusLabel;

  final TransferRole role;
  final bool sellerConfirmed;
  final bool buyerConfirmed;

  /// LE TRANSFERT EXPIRE, et l'écran doit le dire : sans échéance affichée, un
  /// vendeur croit la vente actée et découvre son bien revenu chez lui.
  final DateTime? expiresAt;

  /// Vue PUBLIQUE du bien, même pour le vendeur : un transfert part vers un
  /// numéro saisi à la main, et le numéro complet du véhicule n'a rien à faire
  /// chez un destinataire qui pourrait être un inconnu.
  final PublicAsset? asset;

  /// Ce qu'il reste à faire de MON côté.
  bool get awaitsMe =>
      role == TransferRole.seller ? !sellerConfirmed : !buyerConfirmed;

  static String _string(Object? value) => value is String ? value : '';
}

/// Un dossier de réclamation.
class Claim {
  const Claim({
    required this.id,
    required this.status,
    required this.statusLabel,
    this.decisionLabel,
    this.decisionReason,
    this.respondentDeadline,
    this.fee,
  });

  factory Claim.fromJson(Map<String, Object?> json) {
    final dossier = json['claim'] is Map<String, Object?>
        ? json['claim']! as Map<String, Object?>
        : json;

    return Claim(
      id: dossier['id'] is int ? dossier['id']! as int : 0,
      status: _string(dossier['status']),
      statusLabel: _string(dossier['status_label']),
      decisionLabel: dossier['decision_label'] is String
          ? dossier['decision_label']! as String
          : null,
      decisionReason: dossier['decision_reason'] is String
          ? dossier['decision_reason']! as String
          : null,
      respondentDeadline: DateTime.tryParse(_string(dossier['respondent_deadline'])),
      fee: dossier['fee'] is Map<String, Object?>
          ? dossier['fee']! as Map<String, Object?>
          : null,
    );
  }

  final int id;
  final String status;
  final String statusLabel;
  final String? decisionLabel;
  final String? decisionReason;
  final DateTime? respondentDeadline;

  /// Frais de dossier tels que le serveur les annonce. JAMAIS CODÉS EN DUR :
  /// un administrateur peut les mettre à zéro, et une application qui
  /// afficherait un montant embarqué réclamerait de l'argent que la plateforme
  /// vient précisément de cesser de demander.
  final Map<String, Object?>? fee;

  static String _string(Object? value) => value is String ? value : '';
}
