import '../models/lookup.dart';
import 'transport.dart';

/// Cycle de vie d'un bien : vol, levée, fin de vie, transfert.
///
/// DÉCLARER UN VOL EST LE PARCOURS LE PLUS URGENT DU PRODUIT. Quelqu'un vient
/// de se faire prendre sa moto ; chaque écran de plus est une minute pendant
/// laquelle le bien peut être revendu. C'est aussi pourquoi la déclaration
/// n'exige qu'un code à usage unique et rien d'autre : ni pièce jointe, ni
/// procès-verbal, ni vérification d'identité préalable. Ceux-là viendront après,
/// s'ils viennent.
///
/// LE CODE EXIGÉ N'EST PAS UNE FRICTION GRATUITE (CT-06) : une déclaration de
/// vol rend un bien invendable dans la seconde. Un téléphone déverrouillé
/// laissé sur une table ne doit pas suffire à geler le véhicule de son
/// propriétaire.
class LifecycleService {
  const LifecycleService(this._api);

  final PreuveTransport _api;

  /// Rend le bien invendable immédiatement.
  Future<PublicAsset> declareStolen(int assetId, String code) async {
    return _bien(await _api.post('/assets/$assetId/stolen', body: <String, Object?>{
      'code': code,
    }));
  }

  /// Lève l'alerte — réservé au déclarant.
  ///
  /// L'ÉPISODE RESTE CONSIGNÉ dans l'historique du bien : une levée n'efface pas
  /// ce qui a eu lieu. Un acheteur a le droit de savoir qu'un véhicule a été
  /// déclaré volé puis rendu, même si tout est rentré dans l'ordre.
  Future<PublicAsset> clearStolen(int assetId, String code) async {
    return _bien(await _api.delete('/assets/$assetId/stolen', body: <String, Object?>{
      'code': code,
    }));
  }

  /// Déclare un bien hors d'usage.
  ///
  /// N'EST PAS POSSIBLE SUR UN BIEN VOLÉ, et le serveur le refuse : la fin de
  /// vie libérerait le regard porté sur l'identifiant, ce qui offrirait au
  /// voleur un moyen commode d'éteindre l'alerte.
  Future<PublicAsset> declareEndOfLife(int assetId, String code) async {
    return _bien(await _api.post('/assets/$assetId/end-of-life', body: <String, Object?>{
      'code': code,
    }));
  }

  static PublicAsset _bien(Map<String, Object?> body) {
    final asset = body['asset'];

    return PublicAsset.fromJson(
      asset is Map<String, Object?> ? asset : body,
    );
  }
}

/// Transfert de propriété, à double validation.
///
/// LE VENDEUR PROPOSE, L'ACHETEUR CONFIRME, ET LE TRANSFERT EXPIRE. Sans
/// confirmation, un vendeur pourrait se décharger d'un bien litigieux sur
/// quelqu'un qui n'en saurait rien ; sans expiration, un transfert oublié
/// resterait indéfiniment en suspens sur un bien qu'on croit vendu.
///
/// APRÈS TRANSFERT, LE BIEN REPART AU NIVEAU DE FIABILITÉ LE PLUS BAS. Les
/// justificatifs appuyaient la propriété du VENDEUR, pas celle de l'acheteur.
/// L'application doit le dire : sans explication, la baisse de la jauge passera
/// pour un défaut.
class TransferService {
  const TransferService(this._api);

  final PreuveTransport _api;

  /// Propose un transfert. [code] est un code à usage unique de motif
  /// `transfer` : céder la propriété d'un bien n'est pas un geste ordinaire.
  Future<Map<String, Object?>> propose(
    int assetId, {
    required String recipientPhone,
    required String code,
  }) {
    return _api.post('/assets/$assetId/transfer', body: <String, Object?>{
      'recipient_phone': recipientPhone,
      'code': code,
    });
  }

  Future<Map<String, Object?>> confirm(int transferId, String code) {
    return _api.post('/transfers/$transferId/confirm', body: <String, Object?>{
      'code': code,
    });
  }

  /// Annule un transfert proposé.
  Future<void> cancel(int transferId) async {
    await _api.delete('/transfers/$transferId');
  }
}

/// Réclamation : contester un bien enregistré par un tiers.
///
/// OUVRIR UN DOSSIER ET Y VERSER DES PIÈCES SONT LIBRES. C'est le DÉPÔT qui
/// peut être payant, parce que c'est là que le bien est gelé et le détenteur
/// prévenu — le moment où la réclamation commence à coûter à quelqu'un d'autre.
/// Le refus arrive donc à `submit`, jamais avant, et l'application ne doit pas
/// demander de payer pour constituer un dossier.
///
/// LE MONTANT N'EST JAMAIS CODÉ EN DUR : un administrateur peut le mettre à
/// zéro pour une période, et l'application doit le lire dans le refus.
class ClaimService {
  const ClaimService(this._api);

  final PreuveTransport _api;

  /// Ouvre un dossier sur un bien. Gratuit.
  Future<Map<String, Object?>> open(int assetId, {required String reason}) {
    return _api.post('/assets/$assetId/claims', body: <String, Object?>{
      'reason': reason,
    });
  }

  /// Verse une pièce au dossier. Gratuit.
  Future<Map<String, Object?>> addEvidence(
    int claimId, {
    required String evidenceType,
    required int documentId,
  }) {
    return _api.post('/claims/$claimId/evidences', body: <String, Object?>{
      'evidence_type': evidenceType,
      'document_id': documentId,
    });
  }

  /// Dépose le dossier. C'est ICI que les frais peuvent être exigés (402).
  Future<Map<String, Object?>> submit(int claimId) {
    return _api.post('/claims/$claimId/submit');
  }

  Future<Map<String, Object?>> show(int claimId) => _api.get('/claims/$claimId');
}
