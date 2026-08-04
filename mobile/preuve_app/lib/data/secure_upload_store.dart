import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:preuve_core/preuve_core.dart';

/// File d'envoi conservée dans le coffre de la plateforme.
///
/// POURQUOI LE COFFRE ET NON UN FICHIER ORDINAIRE — deux raisons, dans cet
/// ordre :
///
/// 1. **Aucune dépendance de plus.** Écrire un fichier suppose de connaître le
///    dossier de l'application, donc `path_provider`, donc un greffon
///    supplémentaire dans un logiciel qui transporte des pièces d'identité. Le
///    coffre est déjà là, pour le jeton de session.
/// 2. **Ce qu'on y range n'est pas anodin.** La file ne porte pas les images,
///    mais leurs CHEMINS : « cette personne a une photo de CNI à cet endroit »
///    est un renseignement en soi, et il n'a rien à faire dans un fichier de
///    préférences en clair.
///
/// LE VOLUME EST BORNÉ, et c'est une contrainte réelle : un coffre n'est pas
/// une base de données. Au-delà de [_plafond] pièces en attente, les plus
/// anciennes sont écartées — un parc de mille photos jamais parties relèverait
/// d'un autre problème, et remplir le trousseau ferait échouer l'écriture du
/// JETON DE SESSION, c'est-à-dire déconnecter quelqu'un pour une photo.
class SecureUploadStore implements PendingUploadStore {
  const SecureUploadStore([this._storage = const FlutterSecureStorage()]);

  static const String _cle = 'preuve.uploads.pending';
  static const int _plafond = 50;

  final FlutterSecureStorage _storage;

  @override
  Future<List<Map<String, Object?>>> read() async {
    final brut = await _storage.read(key: _cle);

    if (brut == null || brut.isEmpty) {
      return const <Map<String, Object?>>[];
    }

    try {
      final decode = jsonDecode(brut);

      return decode is List
          ? decode.whereType<Map<String, Object?>>().toList(growable: false)
          : const <Map<String, Object?>>[];
    } on FormatException {
      // Coffre illisible : on repart d'une file vide plutôt que d'empêcher le
      // démarrage. `UploadManager` fait le même choix, pour la même raison.
      return const <Map<String, Object?>>[];
    }
  }

  @override
  Future<void> write(List<Map<String, Object?>> entries) {
    final bornees = entries.length <= _plafond
        ? entries
        : entries.sublist(entries.length - _plafond);

    return _storage.write(key: _cle, value: jsonEncode(bornees));
  }
}
