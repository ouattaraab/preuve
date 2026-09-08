/// Une pièce déposée sur un bien, telle que son détenteur la revoit.
///
/// SANS CE MODÈLE, PERSONNE NE SAIT CE QU'IL A ENVOYÉ. Quelqu'un qui a
/// photographié sa carte grise il y a six mois n'a aucun moyen de savoir si
/// elle est arrivée, ni si un agent l'a acceptée — il la renverra, ou pire, il
/// croira son bien documenté alors qu'il ne l'est pas.
class AssetDocumentRef {
  const AssetDocumentRef({
    required this.id,
    required this.docType,
    required this.docTypeLabel,
    required this.reviewStatus,
    required this.reviewStatusLabel,
    required this.fileUrl,
    this.reviewReason,
    this.submittedAt,
  });

  factory AssetDocumentRef.fromJson(Map<String, Object?> json) {
    return AssetDocumentRef(
      id: json['id'] is int ? json['id']! as int : 0,
      docType: _s(json['doc_type']),
      docTypeLabel: _s(json['doc_type_label']),
      reviewStatus: _s(json['review_status']),
      reviewStatusLabel: _s(json['review_status_label']),
      fileUrl: _s(json['file_url']),
      reviewReason: json['review_reason'] is String ? json['review_reason']! as String : null,
      submittedAt: DateTime.tryParse(_s(json['submitted_at'])),
    );
  }

  final int id;
  final String docType;
  final String docTypeLabel;

  /// `pending`, `accepted`, `rejected`, `suspected_forgery`.
  final String reviewStatus;
  final String reviewStatusLabel;

  /// Chemin d'une route AUTHENTIFIÉE, jamais un lien signé : la pièce est
  /// chiffrée au repos, et un lien signé est une capacité au porteur — quiconque
  /// le recopie ouvrirait la carte grise de quelqu'un.
  final String fileUrl;

  /// Rendu au déposant : c'est ce qui lui permet de corriger, et ce qui rend la
  /// décision contestable.
  final String? reviewReason;

  final DateTime? submittedAt;

  bool get isAccepted => reviewStatus == 'accepted';

  bool get isRefused => reviewStatus == 'rejected' || reviewStatus == 'suspected_forgery';

  /// Vrai pour une image affichable en vignette. Un PDF ne se prévisualise pas
  /// ici : mieux vaut une étiquette lisible qu'un carré cassé.
  bool get isImage => !fileUrl.toLowerCase().endsWith('.pdf');

  static String _s(Object? v) => v is String ? v : '';
}
