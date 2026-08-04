/// Verdict d'une consultation publique.
///
/// TROIS ISSUES QU'IL NE FAUT JAMAIS CONFONDRE À L'ÉCRAN : le bien est connu,
/// il est inconnu, ou la consultation n'a pas eu lieu. Un identifiant inconnu
/// n'est NI un bon NI un mauvais signe — le présenter comme rassurant ferait
/// acheter un bien volé que personne n'a déclaré.
library;

enum LookupOutcome { known, unknown, invalid, rateLimited }

class LookupResult {
  const LookupResult({
    required this.outcome,
    required this.message,
    this.asset,
  });

  factory LookupResult.fromJson(Map<String, Object?> json) {
    final verdict = json['verdict'];
    final asset = json['asset'];

    return LookupResult(
      outcome: switch (verdict) {
        'known' => LookupOutcome.known,
        'invalid' => LookupOutcome.invalid,
        'rate_limited' => LookupOutcome.rateLimited,
        _ => LookupOutcome.unknown,
      },
      message: json['message'] is String ? json['message']! as String : '',
      asset: asset is Map<String, Object?> ? PublicAsset.fromJson(asset) : null,
    );
  }

  final LookupOutcome outcome;
  final String message;
  final PublicAsset? asset;

  bool get isKnown => outcome == LookupOutcome.known && asset != null;

  /// Vrai quand le statut doit alerter avant une transaction.
  ///
  /// C'est le SEUL booléen qui doit décider de la couleur de l'écran : un
  /// client qui déciderait sur le code de statut réinventerait la matrice, et
  /// la ferait dériver au premier statut ajouté côté serveur.
  bool get isWarning => asset?.lifeStatus.warning ?? false;
}

class PublicAsset {
  const PublicAsset({
    required this.publicRef,
    required this.category,
    required this.lifeStatus,
    required this.trustLevel,
    required this.registeredAt,
  });

  factory PublicAsset.fromJson(Map<String, Object?> json) {
    return PublicAsset(
      publicRef: _string(json['public_ref']),
      category: _string(json['category']),
      lifeStatus: StatusView.fromJson(_map(json['life_status'])),
      trustLevel: StatusView.fromJson(_map(json['trust_level'])),
      registeredAt: DateTime.tryParse(_string(json['registered_at'])),
    );
  }

  /// Référence publique opaque. C'est la SEULE forme partageable : le lien
  /// qu'un vendeur envoie ne doit rien laisser deviner du numéro réel.
  final String publicRef;
  final String category;
  final StatusView lifeStatus;
  final StatusView trustLevel;
  final DateTime? registeredAt;

  /// Page publique correspondante, à partager.
  Uri shareUri(String siteBase) => Uri.parse('$siteBase/b/$publicRef');
}

/// Un statut tel que le serveur veut qu'il soit montré.
///
/// LE LIBELLÉ ET LA COULEUR VIENNENT DU SERVEUR, jamais d'une table locale.
/// CT-04 impose du langage courant — « Volé déclaré », jamais « V-VOL » — et
/// une traduction embarquée dans l'application se périmerait au premier statut
/// ajouté, sur des téléphones qui ne se mettent pas à jour.
class StatusView {
  const StatusView({
    required this.code,
    required this.label,
    required this.message,
    required this.color,
    required this.warning,
  });

  factory StatusView.fromJson(Map<String, Object?> json) {
    return StatusView(
      code: _string(json['code']),
      label: _string(json['label']),
      message: _string(json['message']),
      color: _string(json['color']),
      warning: json['warning'] == true,
    );
  }

  /// Code stable, pour les règles du client — jamais pour l'affichage.
  final String code;
  final String label;
  final String message;

  /// Couleur du design system, décidée côté serveur (`#C62F21`, …).
  final String color;
  final bool warning;
}

String _string(Object? value) => value is String ? value : '';

Map<String, Object?> _map(Object? value) =>
    value is Map<String, Object?> ? value : const <String, Object?>{};
