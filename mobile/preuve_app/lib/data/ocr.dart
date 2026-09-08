import 'dart:io';

import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';

/// Lecture du texte d'une photo, SUR L'APPAREIL.
///
/// TOUTE LA RAISON D'ÊTRE DE CE FICHIER TIENT EN UNE PHRASE : l'image ne part
/// pas. Une carte grise porte le nom et l'adresse de son propriétaire, et il
/// n'a jamais fallu l'envoyer à un tiers pour en extraire dix-sept caractères.
/// Seuls les mots lus quittent le téléphone — quelques centaines d'octets.
///
/// ON NE DÉCIDE RIEN ICI. Ce fichier rend des mots, dans le désordre, sans
/// prétendre qu'aucun soit un numéro de châssis. Le chiffre de contrôle du VIN,
/// le Luhn de l'IMEI, le format de plaque et l'ordre de priorité sont des
/// règles métier : elles restent sur le serveur, où elles peuvent changer sans
/// attendre que le parc se mette à jour.
///
/// EN LATIN SEULEMENT, et c'est suffisant : les documents visés sont émis en
/// français. Charger les autres alphabets coûterait quatre mégaoctets chacun
/// pour rien.
class LecteurEmbarque {
  LecteurEmbarque({TextRecognizer? moteur})
      : _moteur = moteur ?? TextRecognizer(script: TextRecognitionScript.latin);

  final TextRecognizer _moteur;

  /// Les mots lus sur la photo, tels quels.
  ///
  /// LES SÉPARATEURS SONT RETIRÉS, PAS LE BRUIT. Un numéro de châssis est
  /// souvent imprimé avec des espaces ou des tirets qu'aucun registre ne
  /// conserve ; les garder ferait échouer la reconnaissance sur une différence
  /// de mise en page. Le reste — mots parasites, tampons, mentions légales —
  /// est laissé au serveur, qui sait quoi en faire.
  ///
  /// Rend une liste VIDE plutôt qu'une exception si la lecture échoue : sur ce
  /// parcours, l'échec de lecture est un résultat ordinaire, et la saisie
  /// manuelle reste le chemin nominal.
  Future<List<String>> lire(String cheminImage) async {
    if (!File(cheminImage).existsSync()) {
      return const <String>[];
    }

    final RecognizedText texte = await _moteur.processImage(
      InputImage.fromFilePath(cheminImage),
    );

    final Set<String> mots = <String>{};

    for (final TextBlock bloc in texte.blocks) {
      for (final TextLine ligne in bloc.lines) {
        // La LIGNE ENTIÈRE autant que ses éléments : un châssis imprimé
        // « 1M8GDM9 AXKP042788 » est coupé en deux par le moteur, et aucune
        // moitié ne passerait le chiffre de contrôle. Recollée sans ses
        // espaces, elle le passe.
        _ajouter(mots, ligne.text);

        for (final TextElement element in ligne.elements) {
          _ajouter(mots, element.text);
        }
      }
    }

    return mots.toList(growable: false);
  }

  /// Libère le moteur natif. À appeler quand l'écran se ferme : le modèle
  /// occupe plusieurs mégaoctets de mémoire vive.
  Future<void> fermer() => _moteur.close();

  static void _ajouter(Set<String> mots, String brut) {
    final String propre = brut.replaceAll(RegExp(r'[\s\-.·/]'), '').toUpperCase();

    // Six caractères : en deçà, aucun identifiant du catalogue n'existe, et on
    // enverrait au serveur des centaines de mots de liaison à examiner.
    if (propre.length >= 6 && propre.length <= 64) {
      mots.add(propre);
    }
  }
}
