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
    this.needsAttention = const <FleetAlert>[],
    this.lookups30d = 0,
    this.billing,
  });

  factory FleetDashboard.fromJson(Map<String, Object?> json) {
    final tableau = json['dashboard'];
    final d = tableau is Map<String, Object?> ? tableau : json;
    // TOUS LES NOMS CI-DESSOUS SONT RELEVÉS SUR LA RÉPONSE RÉELLE, aucun n'est
    // deviné. Trois l'avaient été, et les trois étaient faux : `total` pour
    // `fleet_size`, et `lookups_30d` pris pour un entier alors que c'est un
    // objet `{total, most_viewed}`. Chacun rendait un ZÉRO silencieux, qu'un
    // test écrit avec le même nom deviné aurait confirmé au lieu de démentir.
    final statuts = d['by_status'];
    final consultations = d['lookups_30d'];
    final aTraiter = d['needs_attention'];

    return FleetDashboard(
      statuses: statuts is List
          ? statuts
              .whereType<Map<String, Object?>>()
              .map(FleetStatusCount.fromJson)
              .toList(growable: false)
          : const <FleetStatusCount>[],
      total: d['fleet_size'] is int ? d['fleet_size']! as int : 0,
      needsAttention: aTraiter is List
          ? aTraiter
              .whereType<Map<String, Object?>>()
              .map(FleetAlert.fromJson)
              .toList(growable: false)
          : const <FleetAlert>[],
      lookups30d: switch (consultations) {
        final Map<String, Object?> m when m['total'] is int => m['total']! as int,
        final int n => n,
        _ => 0,
      },
      billing: json['billing'] is Map<String, Object?>
          ? json['billing']! as Map<String, Object?>
          : null,
    );
  }

  final List<FleetStatusCount> statuses;

  /// Taille du parc, telle que le serveur la compte (`fleet_size`).
  final int total;

  /// LES VÉHICULES À TRAITER, UN PAR UN. Un décompte « Volé déclaré : 1 » ne
  /// dit pas LEQUEL : le loueur devrait ouvrir son parc et chercher. Le serveur
  /// nomme déjà les concernés, il n'y a aucune raison de ne pas les afficher.
  final List<FleetAlert> needsAttention;

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

/// Un véhicule du parc qui demande une action, nommé par sa référence publique.
class FleetAlert {
  const FleetAlert({
    required this.assetId,
    required this.publicRef,
    required this.status,
    required this.statusLabel,
  });

  factory FleetAlert.fromJson(Map<String, Object?> json) {
    return FleetAlert(
      assetId: json['asset_id'] is int ? json['asset_id']! as int : 0,
      publicRef: json['public_ref'] is String ? json['public_ref']! as String : '',
      status: json['status'] is String ? json['status']! as String : '',
      statusLabel: json['status_label'] is String ? json['status_label']! as String : '',
    );
  }

  final int assetId;

  /// La référence publique, JAMAIS le numéro d'immatriculation : cet écran se
  /// consulte au comptoir, et l'écran d'un loueur se lit par-dessus l'épaule.
  final String publicRef;

  final String status;

  /// Le libellé du serveur, jamais le code (CT-04).
  final String statusLabel;
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
