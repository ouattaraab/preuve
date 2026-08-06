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
    this.email,
    this.fullName,
    this.kycStatus,
    this.companies = const <CompanyMembership>[],
  });

  factory Account.fromJson(Map<String, Object?> json) {
    return Account(
      id: json['id'] is int ? json['id']! as int : 0,
      phone: json['phone'] is String ? json['phone']! as String : '',
      email: json['email'] is String ? json['email']! as String : null,
      fullName: json['full_name'] is String ? json['full_name']! as String : null,
      kycStatus: json['kyc_status'] is String ? json['kyc_status']! as String : null,
      companies: json['companies'] is List
          ? (json['companies']! as List)
              .whereType<Map<String, Object?>>()
              .map(CompanyMembership.fromJson)
              .toList(growable: false)
          : const <CompanyMembership>[],
    );
  }

  final int id;

  /// VIDE POUR UN COMPTE OUVERT PAR ADRESSE. La colonne est désormais
  /// facultative côté serveur : lui inventer une valeur ici ferait afficher un
  /// numéro qui ne désigne personne.
  final String phone;

  final String? email;
  final String? fullName;
  final String? kycStatus;

  /// Sociétés dont ce compte est membre actif.
  ///
  /// SANS ELLES, LA FLOTTE EST INATTEIGNABLE : tous ses points d'entrée sont
  /// en `/fleet/{company}/…`, et rien d'autre ne dit à l'application qu'un
  /// compte est un loueur, ni de quelle société.
  final List<CompanyMembership> companies;

  bool get isFleetOperator => companies.isNotEmpty;

  /// Ce sous quoi le titulaire s'est inscrit, à afficher tel quel.
  ///
  /// L'UN DES DEUX PEUT MANQUER, jamais les deux : un compte se joint par un
  /// numéro ou par une adresse, et l'écran doit montrer celle qui existe plutôt
  /// qu'un champ vide.
  String get identifiant => phone.isNotEmpty ? phone : (email ?? '');
}

/// Appartenance à une société, avec le rôle qui décide de ce qu'on peut faire.
class CompanyMembership {
  const CompanyMembership({
    required this.id,
    required this.name,
    required this.role,
    required this.roleLabel,
  });

  factory CompanyMembership.fromJson(Map<String, Object?> json) {
    return CompanyMembership(
      id: json['id'] is int ? json['id']! as int : 0,
      name: json['name'] is String ? json['name']! as String : '',
      role: json['role'] is String ? json['role']! as String : '',
      roleLabel: json['role_label'] is String ? json['role_label']! as String : '',
    );
  }

  final int id;
  final String name;

  /// `admin` ou `operator`. LE RÔLE VIENT DU SERVEUR : un opérateur marque des
  /// véhicules en location, il n'invite pas de collaborateurs. Le deviner
  /// ferait afficher des boutons que le serveur refuse.
  final String role;
  final String roleLabel;

  bool get isAdmin => role == 'admin';
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

  /// Le compte derrière le jeton.
  ///
  /// INDISPENSABLE APRÈS UNE RESTAURATION : le jeton seul ne dit pas à quel
  /// numéro demander un code, et déclarer un vol, céder ou réclamer en exigent
  /// tous un. Sans cet appel, une session restaurée pourrait tout lire et
  /// n'agir sur rien.
  ///
  /// UN JETON RÉVOQUÉ EFFACE LE COFFRE. Une suspension de compte ou une
  /// rétrogradation de rôle révoque les jetons : garder celui-ci ferait
  /// échouer chaque écran l'un après l'autre, sans jamais proposer de se
  /// reconnecter.
  Future<Account> me() async {
    try {
      return Account.fromJson(await _api.get('/auth/me'));
    } on NotAuthenticated {
      await _store.clear();
      _api.setToken(null);

      rethrow;
    }
  }

  /// Demande un code, à un NUMÉRO OU À UNE ADRESSE.
  ///
  /// LES DEUX SONT ACCEPTÉS PARCE QU'AUCUNE PASSERELLE SMS N'EST BRANCHÉE :
  /// le code part par courriel, et n'exiger qu'un numéro fermerait le produit
  /// à quiconque n'a pas déjà un compte. Le serveur reconnaît une adresse à
  /// son arrobase et rien d'autre.
  ///
  /// LA RÉPONSE DU SERVEUR EST INVARIABLE, que la destination soit connue ou non :
  /// toute différence observable ferait de cette route un service
  /// d'énumération d'abonnés. Le client ne doit donc RIEN en déduire — ni
  /// afficher « compte inconnu », ni proposer une inscription sur cette base.
  Future<Duration> requestCode(
    String identifier,
    OtpPurpose purpose, {
    String? email,
  }) async {
    final body = await _api.post('/auth/otp/request', body: <String, Object?>{
      // LES DEUX NOMS, le temps que le parc se renouvelle. `identifier` porte
      // désormais un numéro OU une adresse ; `phone` reste envoyé pour qu'un
      // serveur plus ancien continue de comprendre cette application.
      'identifier': identifier,
      'phone': identifier,
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
  /// [fullName] n'est retenu qu'à la CRÉATION du compte, et n'est jamais
  /// exigé : on ne demande pas une identité pour ouvrir un compte, seulement
  /// pour céder un bien ou réclamer (CT-06).
  Future<Account> verify(
    String identifier,
    String code,
    OtpPurpose purpose, {
    String? email,
    String? fullName,
    bool revokeOtherDevices = false,
  }) async {
    final body = await _api.post('/auth/otp/verify', body: <String, Object?>{
      'identifier': identifier,
      'phone': identifier,
      'code': code,
      'purpose': purpose.wire,
      if (email != null && email.isNotEmpty) 'email': email,
      if (fullName != null && fullName.isNotEmpty) 'full_name': fullName,
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
