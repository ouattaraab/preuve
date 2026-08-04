/// Ce que le centre de notifications rend, et ce qu'il ne rendra jamais.
///
/// AUCUNE IDENTITÉ, AUCUNE ADRESSE, AUCUN COMPTE UNITAIRE DE CONSULTATION
/// NOMINATIF. Le serveur agrège les consultations à l'heure et les rend
/// anonymes ; ces classes n'ont donc aucun champ pour les recevoir, et c'est
/// délibéré — un modèle qui prévoirait la place d'un « consulté par » finirait
/// par la faire remplir.
library;

class NotificationFeed {
  const NotificationFeed({
    required this.notifications,
    required this.unreadCount,
    required this.page,
    required this.lastPage,
  });

  factory NotificationFeed.fromJson(Map<String, Object?> json) {
    final brutes = json['notifications'];
    final meta = json['meta'];

    return NotificationFeed(
      notifications: brutes is List
          ? brutes
              .whereType<Map<String, Object?>>()
              .map(UserNotification.fromJson)
              .toList(growable: false)
          : const <UserNotification>[],
      unreadCount: _int(json['unread_count']),
      page: _int(_champ(meta, 'current_page'), defaut: 1),
      lastPage: _int(_champ(meta, 'last_page'), defaut: 1),
    );
  }

  final List<UserNotification> notifications;

  /// Badge. Rendu par le serveur et non recompté sur la page reçue : le fil est
  /// paginé, et compter les non-lus visibles annoncerait « 20 » à quelqu'un qui
  /// en a deux cents.
  final int unreadCount;

  final int page;
  final int lastPage;

  bool get hasMore => page < lastPage;

  static Object? _champ(Object? meta, String cle) =>
      meta is Map<String, Object?> ? meta[cle] : null;

  static int _int(Object? valeur, {int defaut = 0}) => valeur is int ? valeur : defaut;
}

class UserNotification {
  const UserNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.read,
    this.assetId,
    this.createdAt,
    this.payload = const <String, Object?>{},
  });

  factory UserNotification.fromJson(Map<String, Object?> json) {
    final charge = json['payload'];

    return UserNotification(
      id: json['id'] is int ? json['id']! as int : 0,
      type: _string(json['type']),
      title: _string(json['title']),
      body: _string(json['body']),
      read: json['read'] == true,
      assetId: json['asset_id'] is int ? json['asset_id']! as int : null,
      createdAt: DateTime.tryParse(_string(json['created_at'])),
      payload: charge is Map<String, Object?> ? charge : const <String, Object?>{},
    );
  }

  final int id;

  /// Code stable du type (`asset_lookup`, `duplicate_attempt`…), pour les
  /// règles du client — jamais pour l'affichage.
  final String type;

  /// TITRE ET CORPS SONT RÉDIGÉS PAR LE SERVEUR. Les recomposer côté client
  /// obligerait à y embarquer les règles d'agrégation et de langage courant
  /// (CT-04), qui se périmeraient sur les téléphones qui ne se mettent pas à
  /// jour — et une alerte mal formulée est une alerte mal comprise.
  final String title;
  final String body;

  final bool read;
  final int? assetId;
  final DateTime? createdAt;
  final Map<String, Object?> payload;

  /// Vrai pour les alertes qu'on ne peut pas couper et qui appellent un geste :
  /// quelqu'un a tenté d'enregistrer un bien déjà à vous, ou un bien est
  /// soudainement très consulté.
  bool get isCritical =>
      type == 'duplicate_attempt' || type == 'lookup_spike' || type == 'claim_opened';

  static String _string(Object? valeur) => valeur is String ? valeur : '';
}

/// Préférences, et types que l'on peut RÉELLEMENT couper.
class NotificationPreferences {
  const NotificationPreferences({required this.values, required this.available});

  factory NotificationPreferences.fromJson(Map<String, Object?> json) {
    final brutes = json['preferences'];
    final disponibles = json['available'];

    return NotificationPreferences(
      values: brutes is Map<String, Object?>
          ? <String, bool>{
              for (final MapEntry<String, Object?> e in brutes.entries) e.key: e.value == true,
            }
          : const <String, bool>{},
      available: disponibles is List
          ? disponibles
              .whereType<Map<String, Object?>>()
              .map(OptionalNotification.fromJson)
              .toList(growable: false)
          : const <OptionalNotification>[],
    );
  }

  final Map<String, bool> values;

  /// LA LISTE VIENT DU SERVEUR. Les événements critiques n'y sont pas : afficher
  /// une case à cocher qui ne coupe rien serait pire que de ne rien proposer.
  final List<OptionalNotification> available;

  /// Actif par défaut : une préférence jamais réglée signifie « je veux être
  /// prévenu », pas « je ne veux rien ».
  bool enabled(String type) => values[type] ?? true;
}

class OptionalNotification {
  const OptionalNotification({required this.type, required this.label});

  factory OptionalNotification.fromJson(Map<String, Object?> json) {
    return OptionalNotification(
      type: json['type'] is String ? json['type']! as String : '',
      label: json['label'] is String ? json['label']! as String : '',
    );
  }

  final String type;
  final String label;
}
