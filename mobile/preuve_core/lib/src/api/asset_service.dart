import '../models/catalog.dart';
import '../models/document.dart';
import '../models/owned_asset.dart';
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

  /// Inventaire du porteur du jeton — et de lui seul.
  ///
  /// C'EST LE POINT D'ENTRÉE DE TOUTE ACTION. Déclarer un vol, céder ou
  /// réclamer passent par `/assets/{id}/…`, et seul cet appel rend
  /// l'identifiant interne des biens qu'on détient. Sans lui, l'application ne
  /// sait rien enregistrer d'autre que le premier bien de la session.
  Future<Inventory> mine({int page = 1}) async {
    final body = await _api.get(
      '/assets',
      query: page > 1 ? <String, String>{'page': page.toString()} : null,
    );

    final brutes = body['assets'];
    final pagination = body['pagination'];

    return Inventory(
      assets: brutes is List
          ? brutes
              .whereType<Map<String, Object?>>()
              .map(OwnedAsset.fromJson)
              .toList(growable: false)
          : const <OwnedAsset>[],
      page: _int(pagination, 'page', 1),
      lastPage: _int(pagination, 'last_page', 1),
      total: _int(pagination, 'total', 0),
      quota: body['quota'] is Map<String, Object?>
          ? body['quota']! as Map<String, Object?>
          : null,
    );
  }

  static int _int(Object? pagination, String key, int defaut) {
    if (pagination is! Map<String, Object?>) {
      return defaut;
    }

    final valeur = pagination[key];

    return valeur is int ? valeur : defaut;
  }

  /// Les pièces déposées sur un bien, avec l'état de leur revue.
  Future<List<AssetDocumentRef>> documents(int assetId) async {
    final body = await _api.get('/assets/$assetId/documents');
    final brutes = body['documents'];

    return brutes is List
        ? brutes
            .whereType<Map<String, Object?>>()
            .map(AssetDocumentRef.fromJson)
            .toList(growable: false)
        : const <AssetDocumentRef>[];
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
        asset: OwnedAsset.fromJson(
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

/// Une page de l'inventaire, et ce qu'il reste au quota.
class Inventory {
  const Inventory({
    required this.assets,
    required this.page,
    required this.lastPage,
    required this.total,
    this.quota,
  });

  final List<OwnedAsset> assets;
  final int page;
  final int lastPage;
  final int total;

  /// Rendu avec l'inventaire : l'utilisateur voit ce qu'il lui reste AVANT
  /// d'ouvrir un formulaire, plutôt que de l'apprendre au refus après
  /// quatre-vingt-dix secondes de saisie.
  final Map<String, Object?>? quota;

  bool get hasMore => page < lastPage;
}

/// Un bien qui vient d'être enregistré, et ce qu'il reste au quota.
class Registration {
  const Registration({required this.asset, this.quota});

  /// LA VUE DU DÉTENTEUR, avec son identifiant interne : sans lui, on ne
  /// pourrait rattacher ni photo ni justificatif au bien qu'on vient de créer.
  final OwnedAsset asset;

  /// Rendu par le serveur avec la création : l'utilisateur voit ce qu'il lui
  /// reste AVANT d'être arrêté, plutôt que de le découvrir au refus.
  final Map<String, Object?>? quota;
}
