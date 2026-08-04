/// Ce que le cœur ne sait pas faire, et n'a pas à savoir : lire un fichier et
/// ouvrir l'appareil photo.
///
/// TOUT CE QUI DÉCIDE RESTE DANS `preuve_core`. Ici, il n'y a que de la
/// plomberie de plateforme — c'est la frontière qui permet d'éprouver les
/// règles métier dans une console, sans émulateur.
library;

import 'dart:io';

import 'package:image_picker/image_picker.dart';
import 'package:preuve_core/preuve_core.dart';

/// Une photo choisie ou prise, prête à partir.
class PhotoLocale {
  const PhotoLocale({required this.chemin, required this.nom, required this.taille});

  final String chemin;
  final String nom;
  final int taille;

  /// Type déduit de l'extension. Le serveur n'accepte que jpg, png, heic et pdf ;
  /// annoncer autre chose ferait refuser la pièce après l'avoir envoyée.
  String get typeMime {
    final minuscule = nom.toLowerCase();

    if (minuscule.endsWith('.png')) {
      return 'image/png';
    }

    if (minuscule.endsWith('.heic')) {
      return 'image/heic';
    }

    if (minuscule.endsWith('.pdf')) {
      return 'application/pdf';
    }

    return 'image/jpeg';
  }
}

/// D'où vient l'image.
///
/// LES DEUX SONT PROPOSÉES, ET C'EST UNE DÉCISION. L'appareil photo convient au
/// bord d'une route ; la galerie convient à qui a déjà scanné sa carte grise, ou
/// qui la reçoit par messagerie. N'en offrir qu'une obligerait la moitié des
/// gens à recommencer.
enum SourcePhoto { camera, galerie }

class Photos {
  const Photos([this._picker]);

  final ImagePicker? _picker;

  ImagePicker get _outil => _picker ?? ImagePicker();

  /// Rend `null` si la personne renonce — un abandon n'est pas une erreur.
  Future<PhotoLocale?> choisir(SourcePhoto source) async {
    final image = await _outil.pickImage(
      source: source == SourcePhoto.camera ? ImageSource.camera : ImageSource.gallery,
      // BORNÉ À LA PRISE DE VUE, PAS APRÈS. Une photo de douze mégapixels pèse
      // huit mégaoctets : sur une 3G facturée au volume, l'envoyer coûte à
      // quelqu'un qui n'a rien demandé, pour une lisibilité que 1600 pixels
      // donnent déjà sur une carte grise.
      maxWidth: 1600,
      imageQuality: 85,
    );

    if (image == null) {
      return null;
    }

    final fichier = File(image.path);

    return PhotoLocale(
      chemin: image.path,
      nom: image.name,
      taille: await fichier.length(),
    );
  }
}

/// Lit un morceau de fichier, pour la file d'envoi avec reprise.
///
/// OUVRE ET REFERME À CHAQUE MORCEAU. Garder un descripteur ouvert entre deux
/// tentatives espacées de plusieurs minutes empêcherait le système de récupérer
/// le fichier, et l'application peut être suspendue entre-temps.
Future<List<int>> lireMorceau(String chemin, int position, int longueur) async {
  final fichier = File(chemin);

  if (!await fichier.exists()) {
    // Le cœur traduira ce vide en « le fichier a changé » : la conduite est la
    // même, et elle est juste — la pièce a disparu, il faut la reprendre.
    return const <int>[];
  }

  final poignee = await fichier.open();

  try {
    await poignee.setPosition(position);

    return await poignee.read(longueur);
  } finally {
    await poignee.close();
  }
}

/// Empreinte d'un fichier, calculée PAR MORCEAUX.
///
/// Le charger entièrement en mémoire pour le hacher, puis le relire pour
/// l'envoyer, ferait porter deux fois le même fichier à un téléphone qui n'a pas
/// de mémoire à perdre.
Future<String> empreinteDe(String chemin) async {
  final empreinte = Sha256();

  await for (final List<int> morceau in File(chemin).openRead()) {
    empreinte.add(morceau);
  }

  return empreinte.close();
}

/// Prépare une pièce pour la file d'envoi : empreinte, taille, identifiant.
Future<PendingUpload> preparerEnvoi({
  required PhotoLocale photo,
  required int assetId,
  required String docType,
}) async {
  return PendingUpload(
    // Tiré maintenant, une seule fois, et conservé avec la pièce : c'est ce qui
    // rend la reprise idempotente.
    uuid: PendingUpload.newId(),
    assetId: assetId,
    docType: docType,
    filename: photo.nom,
    localPath: photo.chemin,
    byteSize: photo.taille,
    checksum: await empreinteDe(photo.chemin),
  );
}

/// Lit un fichier en entier, pour les envois `multipart` d'un seul tenant
/// (pièces d'une réclamation, KYC) — les seuls que le serveur n'accepte pas en
/// morceaux.
Future<MultipartFile> enUnSeulMorceau(PhotoLocale photo, {required String champ}) async {
  return MultipartFile(
    field: champ,
    filename: photo.nom,
    bytes: await File(photo.chemin).readAsBytes(),
    contentType: photo.typeMime,
  );
}
