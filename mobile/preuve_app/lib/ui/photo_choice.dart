import 'package:flutter/material.dart';

import '../data/fichiers.dart';
import 'theme.dart';

/// Demande d'où vient l'image, puis la rend. `null` si la personne renonce.
///
/// LES DEUX SOURCES SONT TOUJOURS PROPOSÉES. L'appareil photo convient au bord
/// d'une route ; la galerie convient à qui a déjà scanné sa carte grise ou l'a
/// reçue par messagerie. N'en offrir qu'une obligerait la moitié des gens à
/// recommencer un geste qu'ils avaient déjà fait.
Future<PhotoLocale?> choisirPhoto(BuildContext context, {required String titre}) async {
  final source = await showModalBottomSheet<SourcePhoto>(
    context: context,
    backgroundColor: Djassa.creme,
    builder: (BuildContext feuille) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(
              titre,
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 18),
            FilledButton(
              onPressed: () => Navigator.of(feuille).pop(SourcePhoto.camera),
              child: const Text('Prendre une photo'),
            ),
            const SizedBox(height: 10),
            OutlinedButton(
              style: OutlinedButton.styleFrom(
                minimumSize: const Size.fromHeight(Djassa.cible),
                side: const BorderSide(color: Djassa.encre, width: 3),
                foregroundColor: Djassa.encre,
                textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
              ),
              onPressed: () => Navigator.of(feuille).pop(SourcePhoto.galerie),
              child: const Text('Choisir dans la galerie'),
            ),
            const SizedBox(height: 16),
            // DIT CE QU'IL ADVIENT DE L'IMAGE, au moment où on la demande. Une
            // application qui réclame une pièce d'identité sans expliquer ce
            // qu'elle en fait ne mérite pas qu'on la lui donne.
            const Text(
              'La photo est envoyée chiffrée et n\'est visible que des agents qui '
              'instruisent ton dossier. Elle n\'apparaît jamais sur la fiche publique '
              'de ton bien.',
              style: TextStyle(color: Djassa.sourdine, height: 1.5),
            ),
          ],
        ),
      ),
    ),
  );

  if (source == null) {
    return null;
  }

  return const Photos().choisir(source);
}
