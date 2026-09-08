import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

/// Le contrôle local double celui du serveur pour épargner un aller-retour en
/// 3G. Il ne doit JAMAIS être plus sévère que lui : un client trop strict
/// rendrait inconsultables des biens parfaitement enregistrés, et le défaut
/// serait invisible côté serveur — aucune requête n'y parviendrait.
void main() {
  group('normalisation', () {
    test('met en majuscules et retire les séparateurs', () {
      expect(IdentifierNormalizer.normalize(' 1m8-gdm9 axkp042788 '),
          equals('1M8GDM9AXKP042788'));
    });

    test('conserve la forme que porte l\'unicité côté serveur', () {
      // C'est cette chaîne qui est comparée à `identifier_normalized` : une
      // divergence ferait chercher un bien sous une forme qui n'existe pas.
      expect(IdentifierNormalizer.normalize('ab-123.cd'), equals('AB123CD'));
    });
  });

  group('VIN', () {
    test('accepte un châssis dont le chiffre de contrôle tombe juste', () {
      expect(IdentifierNormalizer.isValidVin('1M8GDM9AXKP042788'), isTrue);
    });

    test('refuse un châssis dont un caractère a été mal lu', () {
      // Un seul caractère changé : c'est exactement ce que produit une lecture
      // approximative d'une plaque gravée.
      expect(IdentifierNormalizer.isValidVin('1M8GDM9AXKP042789'), isFalse);
    });

    test('refuse les lettres I, O et Q, absentes de l\'alphabet VIN', () {
      // Elles se confondent avec 1 et 0 sur une plaque : les accepter ferait
      // enregistrer deux biens distincts sous ce que l'œil lit pareil.
      expect(IdentifierNormalizer.isValidVin('1M8GDM9AXKP04278O'), isFalse);
    });

    test('signale une faute de frappe, pas un bien inconnu', () {
      // La distinction compte à l'écran : « ce numéro est mal saisi » et « ce
      // bien n'est pas enregistré » n'appellent pas la même conduite.
      expect(IdentifierNormalizer.check('1M8GDM9AXKP042789'),
          equals(LocalCheck.vinChecksumFailed));
    });
  });

  group('IMEI', () {
    test('accepte une clé de Luhn correcte', () {
      expect(IdentifierNormalizer.isValidImei('490154203237518'), isTrue);
    });

    test('refuse une clé fausse', () {
      expect(IdentifierNormalizer.isValidImei('490154203237519'), isFalse);
    });

    test('ne juge pas ce qui n\'a pas quinze chiffres', () {
      // Quatorze chiffres peuvent être un autre identifiant légitime : le
      // contrôle Luhn ne s'applique qu'à la forme qu'il connaît.
      expect(IdentifierNormalizer.check('49015420323751'),
          equals(LocalCheck.acceptable));
    });
  });

  group('ce qui doit passer au serveur', () {
    test('laisse filer ce qu\'il ne sait pas juger', () {
      // Une plaque ivoirienne n'a ni chiffre de contrôle ni longueur fixe : le
      // client n'a rien à en dire, et le serveur tranchera.
      expect(IdentifierNormalizer.check('AA123BB'), equals(LocalCheck.acceptable));
    });

    test('arrête ce qui est trop court, qui ne consomme même pas le quota', () {
      expect(IdentifierNormalizer.check('AB'), equals(LocalCheck.tooShort));
    });

    test('reconnaît une référence publique partagée par un vendeur', () {
      expect(IdentifierNormalizer.isPublicReference('prv-2h4k9mnp'), isTrue);
      // Un identifiant réel n'en est pas une : la confusion ferait afficher un
      // lien de partage portant le numéro du bien.
      expect(IdentifierNormalizer.isPublicReference('1M8GDM9AXKP042788'), isFalse);
    });
  });
}
