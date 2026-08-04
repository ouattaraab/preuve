import '../models/catalog.dart';
import '../models/lookup.dart';
import 'exceptions.dart';
import 'transport.dart';

/// Enregistrement d'un bien, et catalogue qui en dicte les champs.
///
/// LE CATALOGUE VIENT DU SERVEUR ET N'EST JAMAIS CODÉ EN DUR (décision D6). Une
/// nouvelle catégorie doit apparaître sans passer par les magasins
/// d'applications : c'est tout l'intérêt de la configuration distante, et
/// embarquer la liste annulerait ce bénéfice sur le parc déjà installé.
class AssetService {
  const AssetService(this._api);

  final PreuveTransport _api;

  /// Catalogue publié, avec son ETag.
  ///
  /// [knownVersion] évite de retélécharger ce qui n'a pas changé : sur une 3G
  /// facturée au volume, un catalogue rechargé à chaque lancement se paie.
  /// Rend `null` quand le serveur répond « rien de neuf ».
  Future<CategoryCatalog?> catalog({String? knownVersion}) async {
    final body = await _api.getAnonymous(
      '/config/categories',
      headers: knownVersion == null ? null : <String, String>{'If-None-Match': '"$knownVersion"'},
    );

    if (body.isEmpty) {
      return null;
    }

    return CategoryCatalog.fromJson(body);
  }

  /// Enregistre un bien.
  ///
  /// [elapsed] est le chronomètre du parcours, mesuré depuis l'ouverture du
  /// formulaire. IL ALIMENTE LA MESURE DE CT-02 (moins de 90 s au médian) :
  /// sans lui, la promesse produit n'est pas mesurée et n'est qu'une intention.
  /// À omettre quand on rejoue une file différée, où la durée ne voudrait rien
  /// dire.
  Future<Registration> register({
    required String category,
    required Map<String, Object?> attributes,
    Duration? elapsed,
    int? scanId,
  }) async {
    try {
      final body = await _api.post('/assets', body: <String, Object?>{
        'category': category,
        'attributes': attributes,
        if (elapsed != null) 'client_elapsed_ms': elapsed.inMilliseconds,
        if (scanId != null) 'scan_id': scanId,
      });

      final asset = body['asset'];

      return Registration(
        asset: PublicAsset.fromJson(
          asset is Map<String, Object?> ? asset : const <String, Object?>{},
        ),
        quota: body['quota'] is Map<String, Object?>
            ? body['quota']! as Map<String, Object?>
            : null,
      );
    } on AlreadyRegistered {
      // Remonte tel quel : la seule issue est la réclamation, et c'est à
      // l'écran de le dire. Un réessai ne créera jamais un second
      // enregistrement actif — c'est la règle qui protège le premier
      // propriétaire, pas un caprice du serveur.
      rethrow;
    }
  }
}

/// Un bien qui vient d'être enregistré, et ce qu'il reste au quota.
class Registration {
  const Registration({required this.asset, this.quota});

  final PublicAsset asset;

  /// Rendu par le serveur avec la création : l'utilisateur voit ce qu'il lui
  /// reste AVANT d'être arrêté, plutôt que de le découvrir au refus.
  final Map<String, Object?>? quota;
}
