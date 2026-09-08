import 'lookup.dart';

/// Un bien vu par SON PROPRE DÉTENTEUR — jamais par un tiers.
///
/// DISTINCT DE `PublicAsset`, ET CE N'EST PAS UN DOUBLON. Le verdict public
/// tait l'identifiant complet et l'identifiant interne, pour qu'on ne puisse
/// pas constituer par balayage l'annuaire des biens enregistrés. Rien de tel
/// ici : le détenteur relit ce qu'il a saisi. Fusionner les deux classes
/// finirait par faire passer, un jour, le numéro de châssis de quelqu'un dans
/// une réponse publique — c'est le genre d'erreur qu'un type distinct rend
/// difficile à commettre.
class OwnedAsset {
  const OwnedAsset({
    required this.id,
    required this.publicRef,
    required this.identifier,
    required this.category,
    required this.lifeStatus,
    required this.trustLevel,
    required this.attributes,
    this.registeredAt,
    this.stolenDeclaredAt,
    this.lookups30d = 0,
  });

  factory OwnedAsset.fromJson(Map<String, Object?> json) {
    final attributs = json['attributes'];

    return OwnedAsset(
      id: json['id'] is int ? json['id']! as int : 0,
      publicRef: _string(json['public_ref']),
      identifier: _string(json['identifier']),
      category: _string(json['category']),
      lifeStatus: StatusView.fromJson(_map(json['life_status'])),
      trustLevel: StatusView.fromJson(_map(json['trust_level'])),
      attributes: attributs is Map<String, Object?> ? attributs : const <String, Object?>{},
      registeredAt: DateTime.tryParse(_string(json['registered_at'])),
      stolenDeclaredAt: DateTime.tryParse(_string(json['stolen_declared_at'])),
      lookups30d: json['lookups_30d'] is int ? json['lookups_30d']! as int : 0,
    );
  }

  /// Identifiant interne : c'est lui, et lui seul, qui permet d'agir —
  /// `/assets/{id}/stolen`, `/assets/{id}/transfer`, `/assets/{id}/claims`.
  final int id;

  final String publicRef;

  /// Numéro complet, tel qu'il a été saisi et normalisé.
  final String identifier;

  final String category;

  /// Libellé et couleur VIENNENT DU SERVEUR (CT-04) : une table locale se
  /// périmerait au premier statut ajouté, sur des téléphones qui ne se mettent
  /// pas à jour.
  final StatusView lifeStatus;
  final StatusView trustLevel;

  /// Champs propres à la catégorie (marque, modèle…), tels que le catalogue
  /// les a déclarés.
  final Map<String, Object?> attributes;

  final DateTime? registeredAt;
  final DateTime? stolenDeclaredAt;

  /// Nombre de consultations sur 30 jours. UN NOMBRE, JAMAIS UNE LISTE : le
  /// détenteur apprend que son bien est regardé — le signal utile, parfois le
  /// seul indice d'un vol qui se prépare — sans rien apprendre de qui regarde.
  final int lookups30d;

  /// Un libellé lisible, à défaut d'un numéro que personne ne reconnaît dans
  /// une liste. Le numéro reste affiché à côté : c'est lui qui fait foi.
  String get label {
    final marque = attributes['brand_model'];

    return marque is String && marque.isNotEmpty ? marque : identifier;
  }

  bool get isStolen => lifeStatus.code == 'V-VOL';

  /// Un bien en transfert ou en litige n'accepte plus les gestes ordinaires :
  /// le proposer ferait promettre à l'écran ce que le serveur refusera.
  bool get isFrozen => lifeStatus.code == 'V-VTE' || lifeStatus.code == 'V-LIT';

  static String _string(Object? value) => value is String ? value : '';

  static Map<String, Object?> _map(Object? value) =>
      value is Map<String, Object?> ? value : const <String, Object?>{};
}
