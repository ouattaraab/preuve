import 'transport.dart';

/// Offre flotte B2B (EP-07) : le tableau de bord d'un loueur.
///
/// LA VERTICALE DE LANCEMENT. Un loueur ne consulte pas un bien à la fois : il
/// veut savoir, d'un coup d'œil, ce qui ne va pas dans son parc. L'écran doit
/// donc ouvrir sur les ALERTES, pas sur un total.
///
/// LE MARQUAGE « EN LOCATION » EST L'ACTE MÉTIER DU LOUEUR : un véhicule loué
/// n'est pas à vendre, et un acheteur qui vérifie le numéro doit le lire avant
/// de donner de l'argent à quelqu'un qui n'est pas le propriétaire.
class FleetService {
  const FleetService(this._api);

  final PreuveTransport _api;

  Future<FleetDashboard> dashboard(int companyId) async {
    return FleetDashboard.fromJson(await _api.get('/fleet/$companyId/dashboard'));
  }

  /// Marque des véhicules « En location », par leurs identifiants.
  ///
  /// EN MASSE, parce que c'est ainsi qu'un loueur travaille : rendre un parc à
  /// l'unité serait un formulaire par véhicule, et personne ne le ferait.
  Future<Map<String, Object?>> markRented(
    int companyId, {
    required List<String> identifiers,
    required bool rented,
  }) {
    return _api.post('/fleet/$companyId/rented', body: <String, Object?>{
      'identifiers': identifiers,
      'rented': rented,
    });
  }

  /// Suivi d'un import différé (au-delà de 200 lignes).
  Future<Map<String, Object?>> importStatus(int companyId, int importId) {
    return _api.get('/fleet/$companyId/imports/$importId');
  }
}

/// Ce qu'un loueur voit de son parc.
class FleetDashboard {
  const FleetDashboard({
    required this.statuses,
    required this.total,
    this.lookups30d = 0,
    this.billing,
  });

  factory FleetDashboard.fromJson(Map<String, Object?> json) {
    final tableau = json['dashboard'];
    final d = tableau is Map<String, Object?> ? tableau : json;
    // Les noms viennent du serveur, vérifiés sur la réponse réelle :
    // `fleet_size` et `by_status`. Deviner « total » aurait affiché ZÉRO
    // véhicule sur un parc de quarante, sans qu'aucun test ne s'en aperçoive.
    final statuts = d['by_status'];

    return FleetDashboard(
      statuses: statuts is List
          ? statuts
              .whereType<Map<String, Object?>>()
              .map(FleetStatusCount.fromJson)
              .toList(growable: false)
          : const <FleetStatusCount>[],
      total: d['fleet_size'] is int ? d['fleet_size']! as int : 0,
      lookups30d: d['lookups_30d'] is int ? d['lookups_30d']! as int : 0,
      billing: json['billing'] is Map<String, Object?>
          ? json['billing']! as Map<String, Object?>
          : null,
    );
  }

  final List<FleetStatusCount> statuses;

  /// Taille du parc, telle que le serveur la compte (`fleet_size`).
  final int total;

  final int lookups30d;

  /// Décompte mensuel rendu par le serveur. JAMAIS RECALCULÉ ICI : les paliers
  /// sont des réglages d'exploitation, et un calcul local annoncerait un montant
  /// que la plateforme n'applique plus.
  final Map<String, Object?>? billing;

  /// CE QUI ALARME, remonté en tête : un loueur ouvre cet écran pour savoir ce
  /// qui ne va pas, pas pour lire un total.
  List<FleetStatusCount> get alerts =>
      statuses.where((FleetStatusCount s) => s.warning).toList(growable: false);
}

class FleetStatusCount {
  const FleetStatusCount({
    required this.code,
    required this.label,
    required this.count,
    required this.warning,
  });

  factory FleetStatusCount.fromJson(Map<String, Object?> json) {
    return FleetStatusCount(
      code: json['code'] is String ? json['code']! as String : '',
      label: json['label'] is String ? json['label']! as String : '',
      count: json['count'] is int ? json['count']! as int : 0,
      warning: json['warning'] == true,
    );
  }

  final String code;

  /// Le libellé du serveur, jamais le code (CT-04).
  final String label;
  final int count;
  final bool warning;
}
