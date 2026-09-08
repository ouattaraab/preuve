/// SHA-256, en Dart pur et par morceaux.
///
/// POURQUOI PAS UNE BIBLIOTHÈQUE : le cœur ne dépend de rien, et ce n'est pas
/// une coquetterie — c'est une chaîne d'approvisionnement de moins à surveiller
/// dans un logiciel qui transporte des pièces d'identité. Le calcul, lui, est
/// entièrement spécifié (FIPS 180-4) et se vérifie contre les vecteurs
/// officiels : c'est exactement le genre de code qu'un test rend certain.
///
/// CE N'EST PAS UNE PRIMITIVE DE SÉCURITÉ ICI. L'empreinte sert à constater, à
/// l'assemblage, qu'un fichier envoyé en morceaux est arrivé intact. Aucune clé
/// n'entre dans ce calcul, aucun secret n'en dépend, et la chaîne d'audit — qui,
/// elle, engage — est calculée côté serveur.
///
/// PAR MORCEAUX, ET C'EST LE POINT. Une carte grise pèse plusieurs mégaoctets ;
/// la charger entièrement en mémoire pour l'empreinte, puis la relire morceau
/// par morceau pour l'envoi, ferait porter deux fois le même fichier à un
/// téléphone qui n'a pas de mémoire à perdre.
library;

class Sha256 {
  Sha256();

  static const List<int> _k = <int>[
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1,
    0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786,
    0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147,
    0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
    0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
    0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ];

  final List<int> _h = <int>[
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
  ];

  /// Bloc en cours, de taille FIXE.
  ///
  /// Une liste qui grandirait puis se ferait rogner par la tête coûterait une
  /// recopie complète à chaque bloc — quadratique, et donc quinze secondes pour
  /// un seul mégaoctet, mesuré. Sur une carte grise de huit, l'application
  /// paraîtrait plantée avant d'avoir envoyé un octet.
  final List<int> _bloc = List<int>.filled(64, 0);
  int _rempli = 0;

  final List<int> _w = List<int>.filled(64, 0);

  /// Longueur totale en octets. Sur 64 bits logiques : un fichier de plus de
  /// deux gigaoctets n'a rien à faire dans une file d'envoi mobile, mais le
  /// compteur ne doit pas déborder en silence pour autant.
  int _longueur = 0;

  bool _clos = false;

  /// Ajoute des octets. À appeler autant de fois qu'il y a de morceaux.
  void add(List<int> bytes) {
    if (_clos) {
      throw StateError('Empreinte déjà close : en ouvrir une nouvelle.');
    }

    _longueur += bytes.length;

    var lu = 0;

    while (lu < bytes.length) {
      final place = 64 - _rempli;
      final restant = bytes.length - lu;
      final aCopier = restant < place ? restant : place;

      for (int i = 0; i < aCopier; i++) {
        _bloc[_rempli + i] = bytes[lu + i];
      }

      _rempli += aCopier;
      lu += aCopier;

      if (_rempli == 64) {
        _traiter(_bloc);
        _rempli = 0;
      }
    }
  }

  /// Clôt le calcul et rend l'empreinte en minuscules hexadécimales — la forme
  /// exacte que le serveur valide (`regex:/^[0-9a-fA-F]{64}$/`).
  String close() {
    if (_clos) {
      throw StateError('Empreinte déjà close.');
    }

    final bits = _longueur * 8;
    final reste = <int>[..._bloc.sublist(0, _rempli), 0x80];

    // Bourrage jusqu'à 56 octets modulo 64, puis la longueur sur 8 octets.
    while (reste.length % 64 != 56) {
      reste.add(0);
    }

    for (int decalage = 56; decalage >= 0; decalage -= 8) {
      // `>>>` et non `>>` : le décalage arithmétique de Dart propagerait le bit
      // de signe sur un entier négatif, ce qui ne se verrait que sur des
      // fichiers de plus de deux exaoctets — mais l'écrire juste ne coûte rien.
      reste.add((bits >>> decalage) & 0xff);
    }

    for (int i = 0; i < reste.length; i += 64) {
      _traiter(reste.sublist(i, i + 64));
    }

    _clos = true;

    final buffer = StringBuffer();

    for (final int mot in _h) {
      buffer.write(mot.toRadixString(16).padLeft(8, '0'));
    }

    return buffer.toString();
  }

  void _traiter(List<int> bloc) {
    for (int i = 0; i < 16; i++) {
      _w[i] = (bloc[i * 4] << 24) |
          (bloc[i * 4 + 1] << 16) |
          (bloc[i * 4 + 2] << 8) |
          bloc[i * 4 + 3];
    }

    for (int i = 16; i < 64; i++) {
      final s0 = _rotr(_w[i - 15], 7) ^ _rotr(_w[i - 15], 18) ^ (_w[i - 15] >>> 3);
      final s1 = _rotr(_w[i - 2], 17) ^ _rotr(_w[i - 2], 19) ^ (_w[i - 2] >>> 10);

      _w[i] = (_w[i - 16] + s0 + _w[i - 7] + s1) & 0xffffffff;
    }

    var a = _h[0];
    var b = _h[1];
    var c = _h[2];
    var d = _h[3];
    var e = _h[4];
    var f = _h[5];
    var g = _h[6];
    var h = _h[7];

    for (int i = 0; i < 64; i++) {
      final s1 = _rotr(e, 6) ^ _rotr(e, 11) ^ _rotr(e, 25);
      final ch = (e & f) ^ ((~e & 0xffffffff) & g);
      final temp1 = (h + s1 + ch + _k[i] + _w[i]) & 0xffffffff;
      final s0 = _rotr(a, 2) ^ _rotr(a, 13) ^ _rotr(a, 22);
      final maj = (a & b) ^ (a & c) ^ (b & c);
      final temp2 = (s0 + maj) & 0xffffffff;

      h = g;
      g = f;
      f = e;
      e = (d + temp1) & 0xffffffff;
      d = c;
      c = b;
      b = a;
      a = (temp1 + temp2) & 0xffffffff;
    }

    _h[0] = (_h[0] + a) & 0xffffffff;
    _h[1] = (_h[1] + b) & 0xffffffff;
    _h[2] = (_h[2] + c) & 0xffffffff;
    _h[3] = (_h[3] + d) & 0xffffffff;
    _h[4] = (_h[4] + e) & 0xffffffff;
    _h[5] = (_h[5] + f) & 0xffffffff;
    _h[6] = (_h[6] + g) & 0xffffffff;
    _h[7] = (_h[7] + h) & 0xffffffff;
  }

  static int _rotr(int valeur, int bits) =>
      ((valeur >>> bits) | (valeur << (32 - bits))) & 0xffffffff;

  /// Empreinte d'un contenu tenu en mémoire, pour les cas où il est petit.
  static String of(List<int> bytes) => (Sha256()..add(bytes)).close();
}
