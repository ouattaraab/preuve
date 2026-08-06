import 'transport.dart';

/// La liste publique des biens volés (ST-0805).
///
/// PUBLIQUE ET SANS JETON, comme la consultation, et pour la même raison :
/// elle ne sert que si on la parcourt. Un garagiste à qui l'on apporte une moto
/// n'ouvrira pas de compte pour vérifier une intuition — et joindre le jeton
/// d'un utilisateur connecté associerait à son compte chaque bien qu'il
/// regarde.
///
/// ELLE NE MONTRE QUE CE QUI A ÉTÉ PUBLIÉ. Déclarer un vol rend le bien
/// invendable pour qui vérifie son numéro ; y figurer est un geste distinct,
/// que le détenteur pose lui-même. Sans cette distinction, l'application
/// exposerait des identifiants que personne n'a demandé d'exposer.
class StolenService {
  const StolenService(this._api);

  final PreuveTransport _api;

  /// L'aperçu de l'accueil : dix au plus, et le total à côté.
  Future<StolenPreview> preview() async {
    return StolenPreview.fromJson(await _api.getAnonymous('/stolen/preview'));
  }

  /// La liste complète, paginée et cherchable.
  ///
  /// [query] accepte un FRAGMENT : quelqu'un qui croit reconnaître une moto
  /// n'a souvent qu'un bout de plaque.
  Future<StolenPage> browse({String? query, int page = 1}) async {
    return StolenPage.fromJson(await _api.getAnonymous('/stolen', query: <String, String>{
      if (query != null && query.trim().isNotEmpty) 'q': query.trim(),
      'page': page.toString(),
    }));
  }
}

/// Ce que l'accueil montre.
class StolenPreview {
  const StolenPreview({this.items = const <StolenAsset>[], this.total = 0, this.hasMore = false});

  factory StolenPreview.fromJson(Map<String, Object?> json) {
    return StolenPreview(
      items: StolenAsset.liste(json['stolen']),
      total: json['total'] is int ? json['total']! as int : 0,
      hasMore: json['has_more'] == true,
    );
  }

  final List<StolenAsset> items;

  /// LE TOTAL, PAS SEULEMENT LES DIX AFFICHÉS. « 3 sur 47 » donne une raison
  /// d'ouvrir la liste entière ; trois lignes seules n'en donnent aucune.
  final int total;

  final bool hasMore;
}

class StolenPage {
  const StolenPage({
    this.items = const <StolenAsset>[],
    this.page = 1,
    this.lastPage = 1,
    this.total = 0,
  });

  factory StolenPage.fromJson(Map<String, Object?> json) {
    final Object? pagination = json['pagination'];
    final Map<String, Object?> p =
        pagination is Map<String, Object?> ? pagination : const <String, Object?>{};

    return StolenPage(
      items: StolenAsset.liste(json['stolen']),
      page: p['page'] is int ? p['page']! as int : 1,
      lastPage: p['last_page'] is int ? p['last_page']! as int : 1,
      total: p['total'] is int ? p['total']! as int : 0,
    );
  }

  final List<StolenAsset> items;
  final int page;
  final int lastPage;
  final int total;

  bool get hasMore => page < lastPage;
}

/// Un bien volé, tel que la liste publique le montre.
class StolenAsset {
  const StolenAsset({
    required this.publicRef,
    required this.identifier,
    required this.category,
    this.brandModel,
    this.stolenDeclaredAt,
    this.daysSince,
    this.consolidated = false,
  });

  factory StolenAsset.fromJson(Map<String, Object?> json) {
    return StolenAsset(
      publicRef: _texte(json['public_ref']),
      // L'IDENTIFIANT Y FIGURE, ET C'EST TOUT LE POINT : sans lui, personne ne
      // peut reconnaître le bien qu'on lui propose. Le détenteur, lui, n'y
      // figure jamais.
      identifier: _texte(json['identifier']),
      category: _texte(json['category']),
      brandModel: json['brand_model'] is String ? json['brand_model']! as String : null,
      stolenDeclaredAt: DateTime.tryParse(_texte(json['stolen_declared_at'])),
      daysSince: json['days_since'] is int ? json['days_since']! as int : null,
      consolidated: json['consolidated'] == true,
    );
  }

  static List<StolenAsset> liste(Object? brut) {
    return brut is List
        ? brut.whereType<Map<String, Object?>>().map(StolenAsset.fromJson).toList(growable: false)
        : const <StolenAsset>[];
  }

  final String publicRef;
  final String identifier;
  final String category;
  final String? brandModel;
  final DateTime? stolenDeclaredAt;
  final int? daysSince;

  /// Vrai quand une plainte a été déposée et constatée. C'est le seul signal
  /// qui distingue une déclaration d'un dépôt de plainte réel.
  final bool consolidated;

  /// Ce qu'on affiche en second : la marque si elle est connue, la catégorie
  /// sinon. Jamais un champ vide.
  String get description => brandModel ?? category;

  static String _texte(Object? valeur) => valeur is String ? valeur : '';
}
