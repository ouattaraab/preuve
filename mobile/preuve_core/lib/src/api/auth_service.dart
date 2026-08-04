import 'exceptions.dart';
import 'transport.dart';

/// Où le jeton de session est conservé, entre deux lancements.
///
/// UNE INTERFACE, PAS UNE IMPLÉMENTATION : ce paquet ne sait pas ce qu'est un
/// trousseau iOS ni un Keystore Android, et il n'a pas à le savoir. Ce qu'il
/// impose, c'est que le jeton ne traîne pas dans un fichier de préférences en
/// clair — l'application fournit le coffre, ce paquet fournit la discipline.
abstract interface class TokenStore {
  Future<String?> read();

  Future<void> write(String token);

  Future<void> clear();
}

/// Motifs de code à usage unique, tels que le serveur les nomme.
///
/// LE MOTIF N'EST PAS DÉCORATIF : un code demandé pour se connecter ne doit pas
/// pouvoir servir à autoriser un transfert de propriété. Le serveur le vérifie ;
/// le client n'a donc aucune raison de le choisir au hasard.
enum OtpPurpose {
  login('login'),
  register('register'),
  sensitiveAction('sensitive_action'),
  transfer('transfer'),
  guestPayment('guest_payment');

  const OtpPurpose(this.wire);

  final String wire;
}

/// Compte connecté, tel que le serveur le rend.
class Account {
  const Account({
    required this.id,
    required this.phone,
    this.fullName,
    this.kycStatus,
  });

  factory Account.fromJson(Map<String, Object?> json) {
    return Account(
      id: json['id'] is int ? json['id']! as int : 0,
      phone: json['phone'] is String ? json['phone']! as String : '',
      fullName: json['full_name'] is String ? json['full_name']! as String : null,
      kycStatus: json['kyc_status'] is String ? json['kyc_status']! as String : null,
    );
  }

  final int id;
  final String phone;
  final String? fullName;
  final String? kycStatus;
}

/// Connexion par code à usage unique. Il n'existe aucun mot de passe.
class AuthService {
  AuthService(this._api, this._store);

  final PreuveTransport _api;
  final TokenStore _store;

  /// Restaure la session au lancement, s'il y en a une.
  Future<bool> restore() async {
    final token = await _store.read();

    if (token == null || token.isEmpty) {
      return false;
    }

    _api.setToken(token);

    return true;
  }

  /// Demande un code.
  ///
  /// LA RÉPONSE DU SERVEUR EST INVARIABLE, que le numéro soit connu ou non :
  /// toute différence observable ferait de cette route un service
  /// d'énumération d'abonnés. Le client ne doit donc RIEN en déduire — ni
  /// afficher « compte inconnu », ni proposer une inscription sur cette base.
  Future<Duration> requestCode(
    String phone,
    OtpPurpose purpose, {
    String? email,
  }) async {
    final body = await _api.post('/auth/otp/request', body: <String, Object?>{
      'phone': phone,
      'purpose': purpose.wire,
      if (email != null && email.isNotEmpty) 'email': email,
    });

    final seconds = body['expires_in'];

    return Duration(seconds: seconds is int ? seconds : 300);
  }

  /// Vérifie le code et ouvre la session.
  ///
  /// [revokeOtherDevices] ferme les autres sessions : à proposer sur un
  /// parcours « je pense qu'on a accédé à mon compte », jamais par défaut —
  /// déconnecter silencieusement les autres appareils d'un utilisateur qui
  /// change simplement de téléphone serait une punition sans faute.
  Future<Account> verify(
    String phone,
    String code,
    OtpPurpose purpose, {
    String? email,
    bool revokeOtherDevices = false,
  }) async {
    final body = await _api.post('/auth/otp/verify', body: <String, Object?>{
      'phone': phone,
      'code': code,
      'purpose': purpose.wire,
      if (email != null && email.isNotEmpty) 'email': email,
      if (revokeOtherDevices) 'revoke_other_devices': true,
    });

    final token = body['token'];

    if (token is! String || token.isEmpty) {
      throw const ServerFailure('Le serveur n\'a pas rendu de jeton de session.', 200);
    }

    // LE JETON EST RANGÉ AVANT D'ÊTRE POSÉ. Si l'écriture dans le coffre
    // échoue, la session ne doit pas s'ouvrir : l'utilisateur croirait être
    // connecté et se retrouverait dehors au prochain lancement, sans
    // comprendre pourquoi.
    await _store.write(token);
    _api.setToken(token);

    final compte = body['user'];

    return Account.fromJson(compte is Map<String, Object?> ? compte : const <String, Object?>{});
  }

  /// Ferme la session.
  ///
  /// LE JETON LOCAL EST EFFACÉ MÊME SI LE SERVEUR NE RÉPOND PAS. Une
  /// déconnexion qui échouerait faute de réseau laisserait le jeton sur
  /// l'appareil — c'est-à-dire exactement l'inverse de ce que l'utilisateur
  /// vient de demander, et souvent parce qu'il prête son téléphone.
  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } on PreuveException {
      // Sans conséquence : le serveur expirera le jeton de son côté.
    } finally {
      await _store.clear();
      _api.setToken(null);
    }
  }

  bool get isAuthenticated => _api.isAuthenticated;
}
