import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Enregistrement express d'un bien (ST-0201, CT-02 : moins de 90 s au médian).
///
/// LES CHAMPS VIENNENT DU CATALOGUE, JAMAIS DU CODE (décision D6). Une nouvelle
/// catégorie doit apparaître sans passer par les magasins d'applications ;
/// embarquer la liste annulerait ce bénéfice sur le parc déjà installé,
/// c'est-à-dire sur la majorité des téléphones.
///
/// UN TYPE DE CHAMP INCONNU SE RABAT SUR UNE SAISIE LIBRE, il ne disparaît
/// jamais. Masquer un champ qu'une vieille version ne sait pas dessiner ferait
/// échouer l'enregistrement sans que personne puisse y remédier.
///
/// LE CHRONOMÈTRE PART À L'OUVERTURE DU FORMULAIRE, pas à l'envoi : c'est le
/// temps que l'utilisateur passe réellement, et c'est lui qui mesure CT-02. Une
/// promesse produit qu'on ne mesure pas n'est qu'une intention.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final Stopwatch _chrono = Stopwatch();
  final Map<String, TextEditingController> _champs = <String, TextEditingController>{};

  CategoryCatalog? _catalogue;
  AssetCategory? _categorie;
  bool _enCours = true;
  bool _envoi = false;
  String? _erreur;
  Map<String, String> _erreursParChamp = const <String, String>{};

  @override
  void initState() {
    super.initState();
    _chrono.start();
    _chargerCatalogue();
  }

  @override
  void dispose() {
    for (final TextEditingController controleur in _champs.values) {
      controleur.dispose();
    }

    super.dispose();
  }

  Future<void> _chargerCatalogue() async {
    try {
      final catalogue = await widget.session.assets.catalog();

      if (mounted) {
        setState(() {
          _catalogue = catalogue;

          // Une seule catégorie : on la choisit pour l'utilisateur. Lui faire
          // cocher l'unique option disponible serait un geste de plus pour rien
          // (CT-02).
          final categories = catalogue?.categories ?? const <AssetCategory>[];
          _categorie = categories.length == 1 ? categories.first : null;
          _preparerChamps();
        });
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = e.message);
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  void _preparerChamps() {
    for (final CategoryField champ in _categorie?.fields ?? const <CategoryField>[]) {
      _champs.putIfAbsent(champ.key, TextEditingController.new);
    }
  }

  Future<void> _envoyer() async {
    final categorie = _categorie;

    if (categorie == null) {
      return;
    }

    // CONTRÔLE LOCAL SUR LE SEUL CHAMP QUI PORTE L'IDENTITÉ DU BIEN. Les autres
    // se corrigent après coup ; celui-là décide de quel bien il s'agit, et un
    // aller-retour en 3G pour apprendre ce que le clavier savait déjà coûte
    // plusieurs secondes sur les quatre-vingt-dix promises.
    final canonique = categorie.canonical;

    if (canonique != null) {
      final saisie = _champs[canonique.key]?.text ?? '';
      final controle = IdentifierNormalizer.check(saisie);

      if (controle != LocalCheck.acceptable) {
        setState(() {
          _erreursParChamp = <String, String>{canonique.key: _messageLocal(controle)};
          _erreur = null;
        });

        return;
      }
    }

    setState(() {
      _envoi = true;
      _erreur = null;
      _erreursParChamp = const <String, String>{};
    });

    try {
      final attributs = <String, Object?>{};

      _champs.forEach((String cle, TextEditingController controleur) {
        final valeur = controleur.text.trim();

        if (valeur.isNotEmpty) {
          attributs[cle] = valeur;
        }
      });

      await widget.session.assets.register(
        category: categorie.key,
        attributes: attributs,
        elapsed: _chrono.elapsed,
      );

      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } on AlreadyRegistered catch (e) {
      // LA SEULE ISSUE EST LA RÉCLAMATION, et il faut le dire ainsi : réessayer
      // ne créera jamais un second enregistrement actif. C'est la règle qui
      // protège le premier détenteur, pas un caprice du serveur.
      if (mounted) {
        setState(() => _erreur = '${e.message}\n\nSi ce bien est le tien, ouvre une '
            'réclamation depuis la fiche publique : un agent instruira le dossier.');
      }
    } on InvalidRequest catch (e) {
      if (mounted) {
        setState(() {
          _erreursParChamp = <String, String>{
            for (final MapEntry<String, List<String>> entree in e.errors.entries)
              // Le serveur préfixe par « attributes. » ; l'écran, lui, connaît
              // ses champs par leur clé nue.
              entree.key.replaceFirst('attributes.', ''):
                  entree.value.isEmpty ? e.message : entree.value.first,
          };
        });
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _envoi = false);
      }
    }
  }

  static String _messageLocal(LocalCheck controle) {
    return switch (controle) {
      LocalCheck.tooShort => 'Ce numéro est trop court. Vérifie que tu l\'as saisi en entier.',
      LocalCheck.vinChecksumFailed =>
        'Ce numéro de châssis comporte une erreur. Recompte les 17 caractères : '
            'le 1 et le I, le 0 et le O se confondent facilement.',
      LocalCheck.imeiChecksumFailed =>
        'Cet IMEI comporte une erreur. Compose *#06# sur le téléphone pour le réafficher.',
      LocalCheck.acceptable => '',
    };
  }

  @override
  Widget build(BuildContext context) {
    final categories = _catalogue?.categories ?? const <AssetCategory>[];

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Enregistrer un bien', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: _enCours
            ? const EnCours()
            : SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    if (_erreur != null) ...<Widget>[
                      EncadreErreur(_erreur!),
                      const SizedBox(height: 16),
                    ],
                    if (categories.length > 1) ...<Widget>[
                      const Text(
                        'Quel type de bien ?',
                        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
                      ),
                      const SizedBox(height: 10),
                      Wrap(
                        spacing: 10,
                        runSpacing: 10,
                        children: categories
                            .map((AssetCategory c) => _Choix(
                                  categorie: c,
                                  choisie: _categorie?.key == c.key,
                                  onChoisir: () => setState(() {
                                    _categorie = c;
                                    _preparerChamps();
                                  }),
                                ))
                            .toList(growable: false),
                      ),
                      const SizedBox(height: 22),
                    ],
                    if (_categorie != null) ..._formulaire(_categorie!),
                  ],
                ),
              ),
      ),
    );
  }

  List<Widget> _formulaire(AssetCategory categorie) {
    final champs = <Widget>[];

    for (final CategoryField champ in categorie.fields) {
      final erreur = _erreursParChamp[champ.key];

      champs
        ..add(
          TextField(
            controller: _champs[champ.key],
            // L'identifiant canonique prend le focus : c'est par lui qu'on
            // commence, et c'est le seul dont une faute change l'identité du
            // bien.
            autofocus: champ.canonical,
            textCapitalization: champ.canonical || champ.type == 'identifier'
                ? TextCapitalization.characters
                : TextCapitalization.sentences,
            autocorrect: !champ.canonical,
            enableSuggestions: !champ.canonical,
            keyboardType: switch (champ.type) {
              'number' => TextInputType.number,
              'date' => TextInputType.datetime,
              // Un type que cette version ne connaît pas se saisit librement
              // plutôt que de disparaître.
              _ => TextInputType.text,
            },
            inputFormatters: champ.canonical || champ.type == 'identifier'
                ? <TextInputFormatter>[
                    TextInputFormatter.withFunction(
                      (_, TextEditingValue next) =>
                          next.copyWith(text: next.text.toUpperCase()),
                    ),
                  ]
                : null,
            style: TextStyle(fontSize: champ.canonical ? 22 : 18),
            decoration: InputDecoration(
              labelText: champ.required ? champ.label : '${champ.label} (facultatif)',
              errorText: erreur,
              errorMaxLines: 4,
            ),
          ),
        )
        ..add(const SizedBox(height: 14));
    }

    return <Widget>[
      ...champs,
      const SizedBox(height: 6),
      FilledButton(
        onPressed: _envoi ? null : _envoyer,
        child: _envoi
            ? const SizedBox(
                height: 24,
                width: 24,
                child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
              )
            : const Text('Enregistrer'),
      ),
      const SizedBox(height: 22),
      const Text(
        'Ton bien sera visible pendant 30 jours comme « enregistrement récent » : '
        'c\'est le délai pendant lequel quelqu\'un peut le contester. Passé ce délai, '
        'il devient actif.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 14),
      const Text(
        'Personne ne saura que ce bien est à toi. Un acheteur qui vérifie le numéro '
        'voit son statut, jamais ton nom.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
    ];
  }
}

class _Choix extends StatelessWidget {
  const _Choix({
    required this.categorie,
    required this.choisie,
    required this.onChoisir,
  });

  final AssetCategory categorie;
  final bool choisie;
  final VoidCallback onChoisir;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onChoisir,
      child: Container(
        constraints: const BoxConstraints(minHeight: Djassa.cible),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
        decoration: BoxDecoration(
          color: choisie ? Djassa.accent : Colors.white,
          border: Border.all(color: Djassa.encre, width: 3),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            // L'icône vient du catalogue : elle change avec lui, sans livraison.
            if (categorie.icon.isNotEmpty) ...<Widget>[
              Text(categorie.icon, style: const TextStyle(fontSize: 22)),
              const SizedBox(width: 8),
            ],
            Text(
              categorie.name,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
            ),
          ],
        ),
      ),
    );
  }
}
