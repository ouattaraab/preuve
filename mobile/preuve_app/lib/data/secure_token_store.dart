import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:preuve_core/preuve_core.dart';

/// Coffre à jeton, adossé au trousseau de la plateforme.
///
/// `preuve_core` impose la discipline — le jeton se range, se relit, s'efface —
/// et ne sait rien de la plateforme. C'est ici qu'on fournit le coffre : sur
/// iOS le trousseau, sur Android le Keystore. Un fichier de préférences en
/// clair conviendrait au code mais pas à ce qu'il transporte : ce jeton ouvre
/// l'accès aux biens d'une personne.
class SecureTokenStore implements TokenStore {
  const SecureTokenStore([this._storage = const FlutterSecureStorage()]);

  static const String _cle = 'preuve.session.token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: _cle);

  @override
  Future<void> write(String token) => _storage.write(key: _cle, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _cle);
}
