import 'transport.dart';

/// Veille sur un identifiant (ST-0403, ST-0405).
///
/// CE QUE C'EST, ET POURQUOI ON L'AVAIT PERDU. Poser une veille, c'est demander
/// à être prévenu quand un bien qu'on a enregistré est consulté — parce qu'une
/// rafale de consultations sur un bien qu'on n'a pas mis en vente veut souvent
/// dire qu'un autre le vend. Le service, la détection de pics et les
/// notifications existaient depuis EP-04 ; aucune application ne les appelait.
///
/// ELLE NE SE POSE QUE SUR SES PROPRES BIENS. Sans cette borne, la veille
/// deviendrait un outil de surveillance du bien d'autrui : savoir quand il est
/// consulté, c'est savoir quand il est mis en vente. Le serveur le refuse, et
/// c'est lui qui tranche.
class WatchService {
  const WatchService(this._api);

  final PreuveTransport _api;

  Future<List<WatchAlert>> mine() async {
    final body = await _api.get('/watch-alerts');
    final brutes = body['watch_alerts'];

    return brutes is List
        ? brutes.whereType<Map<String, Object?>>().map(WatchAlert.fromJson).toList(growable: false)
        : const <WatchAlert>[];
  }

  /// Pose une veille. [channel] vaut `push`, `sms` ou `both`.
  Future<WatchAlert> watch(String identifier, {String channel = 'push'}) async {
    final body = await _api.post('/watch-alerts', body: <String, Object?>{
      'identifier': identifier,
      'channel': channel,
    });

    final veille = body['watch_alert'];

    return WatchAlert.fromJson(veille is Map<String, Object?> ? veille : const <String, Object?>{});
  }

  /// Retire la veille. L'identifiant est NORMALISÉ par le serveur : on lui
  /// renvoie celui qu'il nous a rendu, jamais la saisie d'origine.
  Future<void> unwatch(String identifier) async {
    await _api.delete('/watch-alerts/${Uri.encodeComponent(identifier)}');
  }
}

class WatchAlert {
  const WatchAlert({
    required this.id,
    required this.identifier,
    this.channel = 'push',
    this.active = true,
    this.lastTriggeredAt,
  });

  factory WatchAlert.fromJson(Map<String, Object?> json) {
    return WatchAlert(
      id: json['id'] is int ? json['id']! as int : 0,
      identifier: json['identifier'] is String ? json['identifier']! as String : '',
      channel: json['channel'] is String ? json['channel']! as String : 'push',
      active: json['active'] != false,
      lastTriggeredAt: DateTime.tryParse(
        json['last_triggered_at'] is String ? json['last_triggered_at']! as String : '',
      ),
    );
  }

  final int id;
  final String identifier;
  final String channel;
  final bool active;

  /// Quand la veille s'est déclenchée pour la dernière fois. Nul tant qu'il ne
  /// s'est rien passé — ce qui est le cas ordinaire, et rassurant.
  final DateTime? lastTriggeredAt;
}
