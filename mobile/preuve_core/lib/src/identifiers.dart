/// Normalisation et contrôle local des identifiants de biens.
///
/// CE CODE DOUBLE CELUI DU SERVEUR, ET C'EST VOULU. Le serveur reste seul juge :
/// il refuse en 422 ce qui ne tient pas. Mais un aller-retour en 3G coûte
/// plusieurs secondes, et refuser sur place un châssis à seize caractères évite
/// à l'utilisateur d'attendre pour apprendre ce que le clavier savait déjà.
///
/// LA RÈGLE INVERSE VAUT AUSSI : ne jamais refuser localement ce que le serveur
/// accepterait. Un client trop sévère rendrait inconsultables des biens
/// parfaitement enregistrés, et le défaut serait invisible côté serveur — aucune
/// requête n'y parviendrait. En cas de doute, on laisse passer.
library;

/// Résultat d'un contrôle local, avant tout appel réseau.
enum LocalCheck {
  /// Exploitable : à envoyer au serveur, qui tranchera.
  acceptable,

  /// Trop court pour être un identifiant. Le serveur le refuserait aussi, et
  /// ce refus-là ne consomme même pas le quota de consultation.
  tooShort,

  /// Dix-sept caractères mais chiffre de contrôle faux : c'est une faute de
  /// frappe, pas un bien inconnu. Le dire ainsi évite de laisser croire que le
  /// véhicule n'est pas enregistré.
  vinChecksumFailed,

  /// Quinze chiffres dont la clé de Luhn ne tombe pas juste.
  imeiChecksumFailed,
}

class IdentifierNormalizer {
  /// Longueur minimale retenue côté serveur.
  static const int minimumLength = 3;

  /// Majuscules, sans séparateur. Identique à la normalisation du serveur :
  /// c'est cette forme qui porte l'unicité de l'enregistrement actif.
  static String normalize(String raw) {
    final buffer = StringBuffer();

    for (final unit in raw.toUpperCase().codeUnits) {
      final isDigit = unit >= 0x30 && unit <= 0x39;
      final isLetter = unit >= 0x41 && unit <= 0x5A;

      if (isDigit || isLetter) {
        buffer.writeCharCode(unit);
      }
    }

    return buffer.toString();
  }

  /// Contrôle ce qui peut l'être hors ligne, sans jamais être plus sévère que
  /// le serveur.
  static LocalCheck check(String raw) {
    final value = normalize(raw);

    if (value.length < minimumLength) {
      return LocalCheck.tooShort;
    }

    if (value.length == 17 && !isValidVin(value)) {
      return LocalCheck.vinChecksumFailed;
    }

    if (_isFifteenDigits(value) && !isValidImei(value)) {
      return LocalCheck.imeiChecksumFailed;
    }

    return LocalCheck.acceptable;
  }

  /// Chiffre de contrôle d'un VIN (ISO 3779, position 9).
  ///
  /// Les lettres I, O et Q sont absentes de l'alphabet VIN : elles se
  /// confondent avec 1 et 0 sur une plaque gravée, et les accepter ferait
  /// enregistrer deux biens distincts sous ce que l'œil lit pareil.
  static bool isValidVin(String vin) {
    if (vin.length != 17) {
      return false;
    }

    const weights = <int>[8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];
    const values = <String, int>{
      'A': 1, 'B': 2, 'C': 3, 'D': 4, 'E': 5, 'F': 6, 'G': 7, 'H': 8,
      'J': 1, 'K': 2, 'L': 3, 'M': 4, 'N': 5, 'P': 7, 'R': 9,
      'S': 2, 'T': 3, 'U': 4, 'V': 5, 'W': 6, 'X': 7, 'Y': 8, 'Z': 9,
    };

    var sum = 0;

    for (var i = 0; i < 17; i++) {
      final character = vin[i];
      final int value;

      if (RegExp(r'^\d$').hasMatch(character)) {
        value = int.parse(character);
      } else if (values.containsKey(character)) {
        value = values[character]!;
      } else {
        // I, O, Q ou caractère hors alphabet : le VIN est invalide.
        return false;
      }

      sum += value * weights[i];
    }

    final remainder = sum % 11;
    final expected = remainder == 10 ? 'X' : remainder.toString();

    return vin[8] == expected;
  }

  /// Clé de Luhn d'un IMEI (15 chiffres).
  static bool isValidImei(String imei) {
    if (!_isFifteenDigits(imei)) {
      return false;
    }

    var sum = 0;

    for (var i = 0; i < 15; i++) {
      var digit = int.parse(imei[i]);

      // Un chiffre sur deux est doublé, en partant de l'avant-dernier.
      if (i.isOdd) {
        digit *= 2;

        if (digit > 9) {
          digit -= 9;
        }
      }

      sum += digit;
    }

    return sum % 10 == 0;
  }

  /// Vrai si la saisie a la forme d'une référence publique `PRV-XXXXXXXX`.
  ///
  /// Utile pour reconnaître un lien partagé par un vendeur, jamais pour en
  /// déduire un verdict : seul le serveur sait ce qu'elle désigne.
  static bool isPublicReference(String raw) {
    return RegExp(r'^PRV-[A-Z0-9]{8}$').hasMatch(raw.trim().toUpperCase());
  }

  static bool _isFifteenDigits(String value) => RegExp(r'^\d{15}$').hasMatch(value);
}
