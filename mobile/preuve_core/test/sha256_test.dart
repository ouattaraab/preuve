import 'dart:convert';

import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

/// SHA-256 vérifié contre les vecteurs officiels (FIPS 180-4, NIST CAVP).
///
/// C'EST CE QUI AUTORISE À L'AVOIR ÉCRIT PLUTÔT QU'EMPRUNTÉ. Un calcul
/// entièrement spécifié et confronté à ses vecteurs de référence n'a pas
/// d'espace pour une erreur silencieuse : soit il tombe juste sur les six cas
/// ci-dessous, soit il est faux partout.
void main() {
  String hachage(String texte) => Sha256.of(utf8.encode(texte));

  test('vecteur : chaîne vide', () {
    expect(
      hachage(''),
      equals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
    );
  });

  test('vecteur : « abc »', () {
    expect(
      hachage('abc'),
      equals('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'),
    );
  });

  test('vecteur : message de 448 bits', () {
    // Le cas qui piège les bourrages : le message finit juste avant la limite
    // où la longueur ne tient plus dans le bloc.
    expect(
      hachage('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'),
      equals('248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1'),
    );
  });

  test('vecteur : message de 896 bits, sur deux blocs', () {
    expect(
      hachage(
        'abcdefghbcdefghicdefghijdefghijkefghijklfghijklmghijklmn'
        'hijklmnoijklmnopjklmnopqklmnopqrlmnopqrsmnopqrstnopqrstu',
      ),
      equals('cf5b16a778af8380036ce59e7b0492370b249b11e8f07a51afac45037afee9d1'),
    );
  });

  test('vecteur : un million de « a »', () {
    // Éprouve le compteur de longueur et l'enchaînement de milliers de blocs :
    // une erreur de report ne se verrait sur aucun message court.
    expect(
      Sha256.of(List<int>.filled(1000000, 0x61)),
      equals('cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0'),
    );
  });

  test('rend le même résultat par morceaux qu\'en une fois', () {
    // C'EST TOUT L'INTÉRÊT DU CALCUL INCRÉMENTAL : hacher un fichier de huit
    // mégaoctets ne doit pas obliger à le charger entièrement en mémoire, puis
    // à le relire pour l'envoyer.
    final contenu = List<int>.generate(5000, (int i) => i % 251);

    final enUneFois = Sha256.of(contenu);

    final parMorceaux = Sha256();
    for (int i = 0; i < contenu.length; i += 257) {
      parMorceaux.add(contenu.sublist(i, i + 257 > contenu.length ? contenu.length : i + 257));
    }

    expect(parMorceaux.close(), equals(enUneFois));
  });

  test('rend soixante-quatre caractères hexadécimaux minuscules', () {
    // La forme exacte que le serveur valide : `regex:/^[0-9a-fA-F]{64}$/`.
    // Un zéro de tête perdu par `toRadixString` produirait 63 caractères, et le
    // refus n'arriverait qu'à l'ouverture de la session d'envoi.
    for (int i = 0; i < 200; i++) {
      final empreinte = Sha256.of(List<int>.filled(i, i % 256));

      expect(empreinte, hasLength(64));
      expect(RegExp(r'^[0-9a-f]{64}$').hasMatch(empreinte), isTrue);
    }
  });

  test('refuse d\'être réutilisée après clôture', () {
    // Reprendre une empreinte close rendrait une valeur cohérente et fausse.
    final empreinte = Sha256()..add(<int>[1, 2, 3]);
    empreinte.close();

    expect(() => empreinte.add(<int>[4]), throwsStateError);
    expect(empreinte.close, throwsStateError);
  });
}
