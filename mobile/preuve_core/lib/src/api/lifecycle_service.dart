import '../models/evidence.dart';
import '../models/lookup.dart';
import '../models/transfer.dart';
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

  /// Les transferts qui me concernent, dans les deux sens.
  ///
  /// INDISPENSABLE À L'ACHETEUR : l'invitation qu'il reçoit est un code par
  /// SMS — délibérément, pour ne pas payer deux messages — et ce code ne porte
  /// aucun numéro de transfert. Sans cette liste, il n'a rien à confirmer.
  Future<List<PendingTransfer>> mine() async {
    final body = await _api.get('/transfers');
    final brutes = body['transfers'];

    return brutes is List
        ? brutes
            .whereType<Map<String, Object?>>()
            .map(PendingTransfer.fromJson)
            .toList(growable: false)
        : const <PendingTransfer>[];
  }

  /// Propose un transfert vers un NUMÉRO, qui n'a pas forcément de compte.
  ///
  /// AUCUN CODE ICI : c'est le serveur qui en envoie un à l'acheteur, et le
  /// vendeur confirmera ensuite de son côté par [confirm]. Réclamer un code
  /// avant même d'avoir engagé le transfert obligerait le vendeur à en demander
  /// un pour rien si l'acheteur refuse.
  ///
  /// [buyerEmail] EST CE QUI FAIT ARRIVER L'INVITATION. Tant qu'aucune
  /// passerelle SMS n'est branchée, un code adressé à un numéro ne part nulle
  /// part : le vendeur voit son transfert « en cours », l'acheteur n'est jamais
  /// prévenu, et la cession expire au bout de sept jours. L'adresse reste
  /// facultative — le numéro seul demeure accepté, comme avant.
  Future<PendingTransfer> propose(
    int assetId, {
    required String buyerPhone,
    String? buyerEmail,
  }) async {
    final body = await _api.post('/assets/$assetId/transfer', body: <String, Object?>{
      'buyer_phone': buyerPhone,
      if (buyerEmail != null && buyerEmail.isNotEmpty) 'buyer_email': buyerEmail,
    });

    final transfert = body['transfer'];

    return PendingTransfer.fromJson(
      transfert is Map<String, Object?> ? transfert : const <String, Object?>{},
    );
  }

  /// Confirme sa part du transfert.
  ///
  /// LE CAMP EST OBLIGATOIRE, et il vient du serveur (`role` dans [mine]) :
  /// le deviner côté client ferait confirmer une vente à qui croyait accepter
  /// un bien. Le serveur refuse la demande sans lui.
  Future<Map<String, Object?>> confirm(
    int transferId, {
    required String code,
    required TransferRole role,
  }) {
    return _api.post('/transfers/$transferId/confirm', body: <String, Object?>{
      'code': code,
      'role': role.wire,
    });
  }

  /// Émet le code de CE transfert, du côté de celui qui le demande.
  ///
  /// C'EST LA SEULE FAÇON D'EN OBTENIR UN QUI SOIT RECONNU. `/auth/otp/request`
  /// indexe le défi sur la coordonnée DU COMPTE qui se présente ; le serveur, à
  /// la confirmation, le cherche sur celle DU TRANSFERT. Dès qu'une adresse
  /// était donnée — le cas recommandé, et le seul par lequel l'acheteur est
  /// réellement prévenu — les deux différaient : aucun code saisi n'était jamais
  /// reconnu, et la cession expirait au bout de sept jours.
  ///
  /// LE CAMP N'EST PAS ENVOYÉ : le serveur le calcule. Le laisser au client
  /// permettrait à un vendeur de faire partir des codes chez son acheteur.
  Future<TransferCode> sendCode(int transferId) async {
    return TransferCode.fromJson(await _api.post('/transfers/$transferId/code'));
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

  /// Ouvre un dossier depuis la RÉFÉRENCE PUBLIQUE du bien. Gratuit.
  ///
  /// C'EST LE SEUL CHEMIN D'UNE VICTIME. Elle ne connaît pas l'identifiant
  /// interne du bien qu'on lui a pris — la consultation publique le tait, pour
  /// qu'on ne puisse pas balayer le registre. La référence opaque, elle, figure
  /// sur le verdict qu'elle vient de lire et dans le refus qu'elle reçoit en
  /// tentant d'enregistrer un bien déjà pris.
  ///
  /// SANS MOTIF : ce n'est pas un oubli. La recevabilité s'apprécie sur les
  /// PIÈCES, selon une grille pondérée ; un champ libre au dépôt n'y pèserait
  /// rien et laisserait croire qu'une belle explication peut tenir lieu de
  /// justificatif.
  Future<Claim> openByReference(String publicRef) async {
    return Claim.fromJson(await _api.post('/claims', body: <String, Object?>{
      'public_ref': publicRef,
    }));
  }

  /// Ouvre un dossier sur un bien dont on connaît l'identifiant interne.
  ///
  /// En pratique réservé au détenteur : personne d'autre ne dispose de cet
  /// identifiant. [openByReference] est le chemin d'une victime.
  Future<Claim> open(int assetId) async {
    return Claim.fromJson(await _api.post('/assets/$assetId/claims'));
  }

  /// Verse une pièce au dossier. Gratuit.
  ///
  /// [file] est facultatif : certaines natures de preuve — l'ancienneté d'un
  /// compte, une antériorité documentaire — se déclarent sans document joint.
  Future<Map<String, Object?>> addEvidence(
    int claimId, {
    required EvidenceKind evidenceType,
    MultipartFile? file,
    String? documentDate,
  }) {
    return _api.postMultipart(
      '/claims/$claimId/evidences',
      fields: <String, String>{
        'evidence_type': evidenceType.wire,
        if (documentDate != null && documentDate.isNotEmpty) 'document_date': documentDate,
      },
      files: file == null ? const <MultipartFile>[] : <MultipartFile>[file],
    );
  }

  /// Dépose le dossier. C'est ICI que les frais peuvent être exigés (402).
  ///
  /// Le refus remonte en `PaymentRequired`, dont les `details` portent le
  /// montant : router vers le paiement, jamais vers le formulaire — le dossier
  /// n'a rien d'incorrect, il attend un règlement.
  Future<Claim> submit(int claimId) async {
    return Claim.fromJson(await _api.post('/claims/$claimId/submit'));
  }

  Future<Claim> show(int claimId) async {
    return Claim.fromJson(await _api.get('/claims/$claimId'));
  }
}
