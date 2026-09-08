/// Comparaison de versions, pour le forçage de mise à jour.
///
/// LA DÉCISION APPARTIENT AU SERVEUR : c'est lui qui refuse une écriture en 426.
/// Ce code sert uniquement à afficher l'écran de mise à jour AU DÉMARRAGE,
/// plutôt que de laisser l'utilisateur saisir un bien pendant quatre-vingt-dix
/// secondes pour se heurter au refus à l'envoi.
///
/// EN CAS DE DOUTE, ON LAISSE PASSER. Une version illisible — numéro de
/// pré-version, format inattendu — ne doit jamais bloquer : refuser sur une
/// chaîne qu'on n'a pas su lire punirait l'utilisateur pour un défaut de la
/// plateforme. Le serveur appliquera sa propre règle, qui est la même.
library;

class AppVersion {
  /// Vrai si `installed` est strictement antérieure à `minimum`.
  ///
  /// Rend `false` dès qu'une des deux est illisible ou absente.
  static bool isOutdated({required String installed, String? minimum}) {
    if (minimum == null || minimum.isEmpty || installed.isEmpty) {
      return false;
    }

    final left = _parse(installed);
    final right = _parse(minimum);

    if (left == null || right == null) {
      return false;
    }

    for (var i = 0; i < 3; i++) {
      if (left[i] != right[i]) {
        return left[i] < right[i];
      }
    }

    return false;
  }

  /// Trois nombres au plus, séparés par des points. « 1.4 » vaut « 1.4.0 ».
  static List<int>? _parse(String version) {
    if (!RegExp(r'^\d{1,4}(\.\d{1,4}){0,2}$').hasMatch(version)) {
      return null;
    }

    final parts = version.split('.').map(int.parse).toList();

    while (parts.length < 3) {
      parts.add(0);
    }

    return parts;
  }
}

/// État des versions annoncé par `GET /config/app`.
class AppRelease {
  const AppRelease({
    this.minimumVersion,
    this.latestVersion,
    this.updateRequiredForWrites = false,
    this.scanAvailable = false,
  });

  factory AppRelease.fromJson(Map<String, Object?> json) {
    return AppRelease(
      minimumVersion: json['minimum_version'] is String
          ? json['minimum_version']! as String
          : null,
      latestVersion:
          json['latest_version'] is String ? json['latest_version']! as String : null,
      updateRequiredForWrites: json['update_required_for_writes'] == true,
      scanAvailable: json['scan_available'] == true,
    );
  }

  final String? minimumVersion;
  final String? latestVersion;
  final bool updateRequiredForWrites;

  /// Vrai quand un fournisseur d'extraction est branché CÔTÉ SERVEUR.
  ///
  /// L'APPLICATION MOBILE NE S'EN SERT PLUS pour décider quoi que ce soit :
  /// elle lit sur l'appareil, ce qui marche hors ligne et sans clé. Le champ
  /// reste parce qu'il décrit une capacité réelle de la plateforme, dont
  /// dépendent la route par image et les intégrateurs qui n'ont pas d'OCR
  /// embarqué — un front web, par exemple.
  ///
  /// Par défaut faux : un serveur plus ancien ne rend pas ce champ, et
  /// annoncer une capacité qu'on n'a pas vérifiée ferait promettre à sa place.
  final bool scanAvailable;

  /// LA CONSULTATION N'EST JAMAIS BLOQUÉE, quelle que soit la version. Cette
  /// constante existe pour que la règle soit lisible dans le code du client et
  /// pas seulement dans la documentation.
  static const bool lookupAlwaysAvailable = true;

  bool blocksWritesFor(String installed) =>
      AppVersion.isOutdated(installed: installed, minimum: minimumVersion);
}
