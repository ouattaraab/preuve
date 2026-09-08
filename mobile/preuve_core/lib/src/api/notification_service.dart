import '../models/notification.dart';
import 'transport.dart';

/// Centre de notifications in-app (ST-1001) et préférences (ST-0107).
///
/// C'EST LA SEULE FORME SOUS LAQUELLE LE PROPRIÉTAIRE APPREND QU'ON REGARDE SON
/// BIEN. Les consultations lui sont rendues AGRÉGÉES et ANONYMES — « consulté 3
/// fois aujourd'hui » — jamais unitairement, et jamais avec une identité ou une
/// adresse : l'anonymat est symétrique, et le consultant y a autant droit que
/// le détenteur (règle métier absolue n° 4).
///
/// LE CLIENT NE DOIT DONC RIEN CHERCHER DE PLUS. Aucun champ ne dira qui a
/// consulté, aucune requête ne le rendra ; une interface qui laisserait espérer
/// cette information ferait promettre au produit l'inverse de ce qu'il garantit.
class NotificationService {
  const NotificationService(this._api);

  final PreuveTransport _api;

  /// Fil chronologique, avec le compte de non-lus.
  Future<NotificationFeed> feed({int page = 1}) async {
    final body = await _api.get(
      '/notifications',
      query: page > 1 ? <String, String>{'page': page.toString()} : null,
    );

    return NotificationFeed.fromJson(body);
  }

  /// Marque une notification comme lue.
  ///
  /// Le serveur rend 404, et non 403, sur celle d'un autre : répondre
  /// « interdit » confirmerait son existence, donc l'activité sur son bien.
  Future<void> markAsRead(int id) => _api.post('/notifications/$id/read');

  Future<void> markAllAsRead() => _api.post('/notifications/read-all');

  /// Préférences en vigueur, et types RÉELLEMENT désactivables.
  ///
  /// LA LISTE DES TYPES VIENT DU SERVEUR, jamais du client : les événements
  /// critiques — vol constaté, tentative d'enregistrement en doublon — n'y
  /// figurent pas, et afficher une case à cocher qui ne coupe rien serait pire
  /// que de ne pas la proposer.
  Future<NotificationPreferences> preferences() async {
    return NotificationPreferences.fromJson(await _api.get('/notification-preferences'));
  }

  Future<NotificationPreferences> updatePreferences(Map<String, bool> preferences) async {
    return NotificationPreferences.fromJson(
      await _api.put('/notification-preferences', body: <String, Object?>{
        'preferences': preferences,
      }),
    );
  }
}
