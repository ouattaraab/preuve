import 'dart:io';

import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

/// Ce qu'une prise de vue vaut comme preuve de présence.
enum Vivacite {
  /// Aucun visage : l'objectif était couvert, ou cadrait autre chose.
  aucunVisage,

  /// Plusieurs visages : on ne sait plus lequel est le déposant.
  plusieursVisages,

  /// Un visage, mais pas dans l'angle demandé.
  mauvaisAngle,

  /// Conforme à la consigne.
  conforme,
}

/// Consignes de la séquence, dans l'ordre où elles sont demandées.
enum ConsigneVivacite {
  face('face', 'Regarde l\'objectif', 'Visage bien en face, sans lunettes de soleil.'),
  gauche('left', 'Tourne la tête à GAUCHE', 'Lentement, jusqu\'à voir ton oreille droite.'),
  droite('right', 'Tourne la tête à DROITE', 'Lentement, jusqu\'à voir ton oreille gauche.');

  const ConsigneVivacite(this.cle, this.titre, this.explication);

  /// Le nom que le serveur attend. IL NE VIENT PAS DU CLIENT : le serveur ne
  /// reconnaît que `left` et `right`, et ignore le reste — sans quoi une
  /// application pourrait envoyer quatre fois la même photo sous quatre noms
  /// inventés, et l'agent croirait voir une séquence.
  final String cle;
  final String titre;
  final String explication;
}

/// Guide la prise de vivacité, SUR L'APPAREIL.
///
/// CE QU'ELLE FAIT, ET CE QU'ELLE NE FAIT PAS. Elle guide : elle refuse une
/// prise sans visage, une prise à plusieurs visages, et une prise où la tête
/// n'a pas tourné. Elle ne CERTIFIE rien, et aucun score ne part vers le
/// serveur — une application modifiée enverrait ce qu'elle veut, et un chiffre
/// invérifiable affiché à un agent lui ferait cesser de regarder. Ce qui part,
/// ce sont les images ; c'est un humain qui tranche.
///
/// CE QU'ELLE COÛTE À UN FRAUDEUR : brandir une photo imprimée ne suffit plus,
/// puisqu'une photo ne tourne pas la tête. Elle n'arrête pas quelqu'un qui
/// rejoue une vidéo, ni qui modifie l'application. C'est un cran, pas un mur —
/// et le dire est ce qui empêche de s'en contenter.
class GuideDeVivacite {
  GuideDeVivacite({FaceDetector? moteur})
      : _moteur = moteur ??
            FaceDetector(
              options: FaceDetectorOptions(
                // Un visage suffit, et on veut ses angles : c'est le seul
                // signal qui distingue une tête qui tourne d'une photo qu'on
                // incline.
                performanceMode: FaceDetectorMode.accurate,
                enableClassification: true,
              ),
            );

  final FaceDetector _moteur;

  /// L'écart minimal, en degrés, entre la position de face et une position
  /// tournée.
  ///
  /// VINGT DEGRÉS, ET PAS CINQ : au-delà du bruit de mesure, en deçà de ce
  /// qu'un cou fait sans effort. Trop exigeant, on rejette des gens honnêtes
  /// dans une file où le rejet coûte une semaine d'attente.
  static const double ecartMinimal = 20;

  Future<Vivacite> verifier(String cheminImage, ConsigneVivacite consigne) async {
    if (!File(cheminImage).existsSync()) {
      return Vivacite.aucunVisage;
    }

    final List<Face> visages = await _moteur.processImage(
      InputImage.fromFilePath(cheminImage),
    );

    if (visages.isEmpty) {
      return Vivacite.aucunVisage;
    }

    if (visages.length > 1) {
      return Vivacite.plusieursVisages;
    }

    final double? angle = visages.first.headEulerAngleY;

    if (angle == null) {
      // L'angle n'a pas pu être mesuré. ON LAISSE PASSER : l'agent verra
      // l'image, et refuser ici priverait quelqu'un de sa vérification pour
      // une limite de l'appareil, pas pour une faute.
      return Vivacite.conforme;
    }

    return switch (consigne) {
      // De face, on tolère largement : personne ne pose parfaitement droit.
      ConsigneVivacite.face => angle.abs() <= 15 ? Vivacite.conforme : Vivacite.mauvaisAngle,
      ConsigneVivacite.gauche => angle >= ecartMinimal ? Vivacite.conforme : Vivacite.mauvaisAngle,
      ConsigneVivacite.droite => angle <= -ecartMinimal ? Vivacite.conforme : Vivacite.mauvaisAngle,
    };
  }

  /// Un message qui dit QUOI FAIRE, jamais ce qui est faux.
  static String message(Vivacite issue, ConsigneVivacite consigne) {
    return switch (issue) {
      Vivacite.aucunVisage => 'Aucun visage sur cette photo. Cadre ton visage entier, '
          'dans un endroit éclairé.',
      Vivacite.plusieursVisages => 'Plusieurs visages sur la photo. Éloigne-toi des autres '
          'personnes : on doit voir toi, et toi seul.',
      Vivacite.mauvaisAngle => consigne == ConsigneVivacite.face
          ? 'Regarde bien l\'objectif, visage droit.'
          : '${consigne.titre}, plus franchement. ${consigne.explication}',
      Vivacite.conforme => '',
    };
  }

  Future<void> fermer() => _moteur.close();
}
