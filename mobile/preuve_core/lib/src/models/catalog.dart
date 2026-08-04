/// Catalogue des types de biens, servi par configuration distante (décision D6).
///
/// AUCUN TYPE DE BIEN N'EST CODÉ EN DUR DANS LE CLIENT. Une nouvelle catégorie
/// doit apparaître sans passer par les magasins d'applications — c'est tout
/// l'intérêt, et embarquer la liste annulerait ce bénéfice sur le parc déjà
/// installé, c'est-à-dire sur la majorité des téléphones.
library;

class CategoryCatalog {
  const CategoryCatalog({required this.version, required this.categories});

  factory CategoryCatalog.fromJson(Map<String, Object?> json) {
    final brutes = json['categories'];

    return CategoryCatalog(
      version: json['version'] is String ? json['version']! as String : 'v0',
      categories: brutes is List
          ? brutes
              .whereType<Map<String, Object?>>()
              .map(AssetCategory.fromJson)
              .toList(growable: false)
          : const <AssetCategory>[],
    );
  }

  /// Sert d'ETag : la conserver localement évite de retélécharger un catalogue
  /// inchangé sur une connexion facturée au volume.
  final String version;

  final List<AssetCategory> categories;

  AssetCategory? byKey(String key) {
    for (final categorie in categories) {
      if (categorie.key == key) {
        return categorie;
      }
    }

    return null;
  }
}

class AssetCategory {
  const AssetCategory({
    required this.key,
    required this.name,
    required this.icon,
    required this.fields,
  });

  factory AssetCategory.fromJson(Map<String, Object?> json) {
    final brutes = json['fields'];

    return AssetCategory(
      key: _string(json['key']),
      name: _string(json['name']),
      icon: _string(json['icon']),
      fields: brutes is List
          ? brutes
              .whereType<Map<String, Object?>>()
              .map(CategoryField.fromJson)
              .toList(growable: false)
          : const <CategoryField>[],
    );
  }

  final String key;
  final String name;
  final String icon;
  final List<CategoryField> fields;

  /// Le champ qui porte l'unicité de l'enregistrement actif.
  ///
  /// C'est lui que le formulaire doit mettre en avant, et le seul dont une
  /// faute de frappe change l'identité du bien : les autres se corrigent, pas
  /// celui-là.
  CategoryField? get canonical {
    for (final champ in fields) {
      if (champ.canonical) {
        return champ;
      }
    }

    return null;
  }
}

class CategoryField {
  const CategoryField({
    required this.key,
    required this.label,
    required this.type,
    required this.required,
    required this.canonical,
  });

  factory CategoryField.fromJson(Map<String, Object?> json) {
    return CategoryField(
      key: _string(json['key']),
      label: _string(json['label']),
      type: _string(json['type']),
      required: json['required'] == true,
      canonical: json['canonical'] == true,
    );
  }

  final String key;
  final String label;

  /// `text` · `number` · `date` · `identifier` · `select`.
  ///
  /// Un type inconnu d'une version installée doit se rabattre sur une saisie
  /// libre plutôt que de masquer le champ : un champ manquant ferait échouer
  /// l'enregistrement sans que l'utilisateur puisse rien y faire.
  final String type;

  final bool required;
  final bool canonical;
}

String _string(Object? value) => value is String ? value : '';
