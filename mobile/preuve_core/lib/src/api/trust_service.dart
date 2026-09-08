import 'transport.dart';

/// Ce qu'il manque à un bien pour monter d'un cran de fiabilité (ST-0401).
///
/// POURQUOI CE SERVICE COMPTE. La pastille « Déclaré » se voit sur la fiche
/// d'un bien, mais rien ne dit CE QU'IL FAUT FAIRE pour la faire monter, ni
/// pourquoi cela vaudrait la peine. Le détenteur reste donc au niveau le plus
/// bas — et c'est le niveau de fiabilité qui donne sa valeur au rapport vendu
/// aux acheteurs. Le serveur savait répondre à ces deux questions depuis le
/// début ; personne ne les lui posait.
///
/// LES RÈGLES VIENNENT DU SERVEUR, JAMAIS D'ICI. Elles sont versionnées et
/// changent : embarquées dans l'application, elles se périmeraient sur des
/// téléphones qui ne se mettent pas à jour, et afficheraient une marche à
/// suivre qui ne mène plus nulle part.
class TrustService {
  const TrustService(this._api);

  final PreuveTransport _api;

  Future<TrustProgress> forAsset(int assetId) async {
    return TrustProgress.fromJson(await _api.get('/assets/$assetId/trust'));
  }
}

class TrustProgress {
  const TrustProgress({
    required this.current,
    required this.currentLabel,
    this.next,
    this.nextLabel,
    this.benefit,
    this.missing = const <String>[],
    this.documents = const <TrustDocument>[],
  });

  factory TrustProgress.fromJson(Map<String, Object?> json) {
    final trust = json['trust'];
    final t = trust is Map<String, Object?> ? trust : const <String, Object?>{};
    final pieces = json['documents'];

    return TrustProgress(
      current: _texte(t['current']),
      currentLabel: _texte(t['current_label']),
      next: t['next'] is String ? t['next']! as String : null,
      nextLabel: t['next_label'] is String ? t['next_label']! as String : null,
      benefit: t['benefit'] is String ? t['benefit']! as String : null,
      missing: t['missing'] is List
          ? (t['missing']! as List).whereType<String>().toList(growable: false)
          : const <String>[],
      documents: pieces is List
          ? pieces
              .whereType<Map<String, Object?>>()
              .map(TrustDocument.fromJson)
              .toList(growable: false)
          : const <TrustDocument>[],
    );
  }

  final String current;
  final String currentLabel;

  /// Nul quand le bien est au niveau le plus élevé : il n'y a alors rien à
  /// proposer, et inventer une étape ferait courir après un palier inexistant.
  final String? next;
  final String? nextLabel;

  /// CE QUE LE PALIER SUIVANT APPORTE. Sans cette phrase, on demande un effort
  /// pour une pastille de couleur.
  final String? benefit;

  /// Les conditions non satisfaites, dans les mots du serveur.
  final List<String> missing;

  final List<TrustDocument> documents;

  bool get canProgress => next != null;
}

/// Une pièce déposée, et où elle en est.
class TrustDocument {
  const TrustDocument({
    required this.id,
    required this.typeLabel,
    required this.statusLabel,
    this.status = '',
    this.reason,
    this.submittedAt,
  });

  factory TrustDocument.fromJson(Map<String, Object?> json) {
    return TrustDocument(
      id: json['id'] is int ? json['id']! as int : 0,
      typeLabel: _texte(json['doc_type_label']),
      statusLabel: _texte(json['review_status_label']),
      status: _texte(json['review_status']),
      // LE MOTIF DE REFUS EST RENDU AU DÉPOSANT : c'est ce qui lui permet de
      // corriger, et ce qui rend la décision contestable.
      reason: json['review_reason'] is String ? json['review_reason']! as String : null,
      submittedAt: DateTime.tryParse(_texte(json['submitted_at'])),
    );
  }

  final int id;
  final String typeLabel;
  final String statusLabel;
  final String status;
  final String? reason;
  final DateTime? submittedAt;

  bool get isRejected => status == 'rejected';
  bool get isAccepted => status == 'accepted';
}

String _texte(Object? valeur) => valeur is String ? valeur : '';
