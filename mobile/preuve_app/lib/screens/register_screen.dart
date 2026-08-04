import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/fichiers.dart';
import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/photo_choice.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Enregistrement express, en quatre temps (ST-0201, CT-02 : moins de 90 s).
///
/// LE CHRONOMÈTRE EST AFFICHÉ, ET C'EST UNE DÉCISION DE PRODUIT. La promesse
/// « moins de quatre-vingt-dix secondes » n'engage à rien si personne ne la
/// voit ; affichée, elle engage l'équipe autant que l'utilisateur. Elle part à
/// l'ouverture du formulaire, pas à l'envoi — c'est le temps réellement passé,
/// et c'est lui qui alimente la mesure côté serveur.
///
/// LE BIEN EST CRÉÉ À LA FIN DE L'ÉTAPE 2, avant les photos. Ce n'est pas un
/// détail technique : une photo se rattache à un bien qui existe, et surtout
/// quelqu'un dont le réseau tombe pendant la prise de vue a DÉJÀ son bien
/// enregistré. Attendre la quatrième photo pour enregistrer ferait perdre
/// l'essentiel pour cause d'accessoire.
///
/// LES CHAMPS VIENNENT DU CATALOGUE, JAMAIS DU CODE (décision D6). Une nouvelle
/// catégorie doit apparaître sans passer par les magasins d'applications.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final Stopwatch _chrono = Stopwatch();
  final Map<String, TextEditingController> _champs = <String, TextEditingController>{};

  Timer? _tic;
  CategoryCatalog? _catalogue;
  AssetCategory? _categorie;

  int _etape = 1;
  bool _enCours = true;
  bool _envoi = false;
  String? _erreur;
  Map<String, String> _erreursParChamp = const <String, String>{};

  OwnedAsset? _cree;
  Duration _duree = Duration.zero;

  /// Vrai quand le serveur n'a pas rendu d'identifiant de bien : les photos
  /// sont alors impossibles, et l'écran doit le DIRE plutôt que de proposer
  /// quatre cases qui ne mèneront nulle part.
  bool _sansPhotos = false;
  final Set<int> _photosPrises = <int>{};

  /// Les quatre prises de vue demandées.
  ///
  /// ELLES SONT GÉNÉRIQUES À DESSEIN : le catalogue des catégories est servi à
  /// distance, et nommer « le compteur » ou « la plaque » ne vaudrait que pour
  /// les véhicules. Ce qui compte est qu'il y en ait QUATRE, sous des angles
  /// différents — une seule photo prouve moins qu'on ne croit.
  static const List<(String, String, String)> _prises = <(String, String, String)>[
    ('📸', 'Vue d\'ensemble', 'photo'),
    ('🔎', 'Le numéro', 'photo'),
    ('↩️', 'De l\'autre côté', 'photo'),
    ('📄', 'Les papiers', 'registration_card'),
  ];

  @override
  void initState() {
    super.initState();
    _chrono.start();
    // Une seconde suffit : la promesse se compte en secondes, pas en dixièmes,
    // et rafraîchir plus vite ne ferait que réveiller l'écran pour rien.
    _tic = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && _chrono.isRunning) {
        setState(() {});
      }
    });
    _chargerCatalogue();
  }

  @override
  void dispose() {
    _tic?.cancel();

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

          final categories = catalogue?.categories ?? const <AssetCategory>[];

          // Une seule catégorie : on la choisit pour l'utilisateur et on passe
          // directement au numéro. Lui faire cocher l'unique option disponible
          // serait un geste de plus pour rien (CT-02).
          if (categories.length == 1) {
            _choisir(categories.first, avancer: true);
          }
        });
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  void _choisir(AssetCategory categorie, {bool avancer = true}) {
    _categorie = categorie;

    for (final CategoryField champ in categorie.fields) {
      _champs.putIfAbsent(champ.key, TextEditingController.new);
    }

    if (avancer) {
      _etape = 2;
    }
  }

  Future<void> _enregistrer() async {
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

      final inscription = await widget.session.assets.register(
        category: categorie.key,
        attributes: attributs,
        elapsed: _chrono.elapsed,
      );

      if (mounted) {
        setState(() {
          // Le bien rendu porte son identifiant interne : c'est lui qui permet
          // d'y rattacher les photos, à l'étape suivante.
          _cree = inscription.asset;
          _duree = _chrono.elapsed;

          // SANS IDENTIFIANT, ON NE PASSE PAS AUX PHOTOS. Un serveur plus
          // ancien rend la vue publique du bien, qui n'en porte pas : la file
          // d'envoi accepterait alors des pièces rattachées au bien numéro
          // ZÉRO, qui n'arriveraient jamais nulle part et dont personne ne
          // saurait qu'elles manquent. Le bien, lui, EST enregistré — c'est
          // l'essentiel, et l'écran le dit.
          _etape = inscription.asset.id > 0 ? 3 : 4;
          _sansPhotos = inscription.asset.id <= 0;
        });

        // LE CHRONOMÈTRE S'ARRÊTE À L'ENREGISTREMENT, pas aux photos : c'est ce
        // moment que CT-02 mesure. Le laisser courir pendant la prise de vue
        // ferait paraître le parcours plus long qu'il ne l'est, et pousserait à
        // sabrer les photos pour gagner un chiffre.
        _chrono.stop();
      }
    } on AlreadyRegistered catch (e) {
      // LA SEULE ISSUE EST LA RÉCLAMATION, et il faut le dire ainsi : réessayer
      // ne créera jamais un second enregistrement actif. C'est la règle qui
      // protège le premier détenteur, pas un caprice du serveur.
      if (mounted) {
        setState(() => _erreur = '${e.message}\n\nSi ce bien est le tien, ouvre une '
            'réclamation depuis sa fiche publique : un agent instruira le dossier.');
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

  Future<void> _prendrePhoto(int index) async {
    final bien = _cree;

    if (bien == null) {
      return;
    }

    final (String _, String nom, String type) = _prises[index];
    final photo = await choisirPhoto(context, titre: nom);

    if (photo == null || !mounted) {
      return;
    }

    try {
      // EN FILE, JAMAIS EN DIRECT (ST-0206, CT-05). Le bien est déjà
      // enregistré : la photo peut partir quand le réseau le permettra, et une
      // coupure ne coûte rien de plus qu'un envoi à reprendre.
      await widget.session.envois.enqueue(
        await preparerEnvoi(photo: photo, assetId: bien.id, docType: type),
      );

      if (mounted) {
        setState(() => _photosPrises.add(index));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _erreur = 'Cette photo n\'a pas pu être mise en file ($e). Reprends-la.');
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

  String get _minuterie {
    final d = _chrono.isRunning ? _chrono.elapsed : _duree;

    return '${d.inMinutes}:${(d.inSeconds % 60).toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(22),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _Entete(minuterie: _minuterie, depasse: _chrono.elapsed.inSeconds > 90),
              const SizedBox(height: 16),
              _Jalons(etape: _etape),
              const SizedBox(height: 20),
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 16),
              ],
              if (_enCours)
                const EnCours()
              else
                ...switch (_etape) {
                  1 => _etapeType(),
                  2 => _etapeNumero(),
                  3 => _etapePhotos(),
                  _ => _etapeFin(),
                },
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _etapeType() {
    final categories = _catalogue?.categories ?? const <AssetCategory>[];

    return <Widget>[
      Text('C\'est quoi, ton bien ?', style: Djassa.affiche(30)),
      const SizedBox(height: 16),
      ...categories.map(
        (AssetCategory c) => Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _CarteType(
            categorie: c,
            onChoisir: () => setState(() => _choisir(c)),
          ),
        ),
      ),
      const SizedBox(height: 18),
      const Text(
        'Pas de pièce d\'identité. 90 secondes, montre en main.',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 14,
          fontWeight: FontWeight.w700,
          color: Djassa.etiquette,
        ),
      ),
    ];
  }

  List<Widget> _etapeNumero() {
    final categorie = _categorie;

    if (categorie == null) {
      return <Widget>[const EnCours()];
    }

    final canonique = categorie.canonical;
    final autres = categorie.fields.where((CategoryField f) => !f.canonical);

    return <Widget>[
      Text('Le numéro', style: Djassa.affiche(30)),
      const SizedBox(height: 6),
      Text(
        canonique == null
            ? 'Le numéro qui identifie ton bien.'
            : 'Le ${canonique.label.toLowerCase()} — celui qui identifie ton bien, '
                'et qu\'un acheteur tapera pour le vérifier.',
        style: const TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 16,
          height: 1.4,
          color: Djassa.sourdine,
        ),
      ),
      const SizedBox(height: 16),
      // Le scan n'est pas encore branché côté application ; le serveur, lui,
      // sait déjà pré-remplir (ST-0202). Le cadre reste, désactivé et dit
      // pourquoi : une cible qui disparaît d'une version à l'autre se cherche.
      const _CadreScan(),
      const SizedBox(height: 14),
      const Center(
        child: Text(
          '— ou j\'écris —',
          style: TextStyle(
            fontFamily: Djassa.texte,
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: Djassa.etiquette,
          ),
        ),
      ),
      const SizedBox(height: 14),
      if (canonique != null)
        ChampRelief(
          controller: _champs[canonique.key]!,
          indication: canonique.label,
          erreur: _erreursParChamp[canonique.key],
          majuscules: true,
          autofocus: true,
          tailleTexte: 18,
          formateurs: <TextInputFormatter>[
            TextInputFormatter.withFunction(
              (_, TextEditingValue next) => next.copyWith(text: next.text.toUpperCase()),
            ),
          ],
        ),
      ...autres.map(
        (CategoryField champ) => Padding(
          padding: const EdgeInsets.only(top: 14),
          child: ChampRelief(
            controller: _champs[champ.key]!,
            libelle: champ.label.toUpperCase(),
            indication: champ.required ? champ.label : '${champ.label} (facultatif)',
            erreur: _erreursParChamp[champ.key],
            tailleTexte: 18,
            clavier: switch (champ.type) {
              'number' => TextInputType.number,
              'date' => TextInputType.datetime,
              // Un type que cette version ne connaît pas se saisit librement
              // plutôt que de disparaître : un champ masqué ferait échouer
              // l'enregistrement sans que personne puisse y remédier.
              _ => TextInputType.text,
            },
          ),
        ),
      ),
      const SizedBox(height: 16),
      BoutonRelief(
        libelle: 'Continuer',
        enCours: _envoi,
        onPressed: _enregistrer,
      ),
    ];
  }

  List<Widget> _etapePhotos() {
    return <Widget>[
      Text('4 photos', style: Djassa.affiche(30)),
      const SizedBox(height: 6),
      const Text(
        'Touche chaque case pour prendre la photo. Ton bien est DÉJÀ enregistré : '
        'les photos partiront quand le réseau le permettra.',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 16,
          height: 1.4,
          color: Djassa.sourdine,
        ),
      ),
      const SizedBox(height: 16),
      GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 12,
        crossAxisSpacing: 12,
        children: List<Widget>.generate(_prises.length, (int i) {
          final (String icone, String nom, String _) = _prises[i];

          return _CasePhoto(
            icone: icone,
            nom: nom,
            prise: _photosPrises.contains(i),
            onTap: () => _prendrePhoto(i),
          );
        }),
      ),
      const SizedBox(height: 16),
      BoutonRelief(
        libelle: 'C\'est bon (${_photosPrises.length}/4)',
        // JAMAIS BLOQUANT. Les photos renforcent la preuve, elles ne la
        // constituent pas : quelqu'un dont le téléphone n'a plus de batterie
        // doit pouvoir finir. Le bouton avance, et l'écran suivant rappelle ce
        // qu'il reste à faire.
        onPressed: () => setState(() => _etape = 4),
      ),
    ];
  }

  List<Widget> _etapeFin() {
    final bien = _cree;

    return <Widget>[
      Container(
        padding: const EdgeInsets.all(26),
        decoration: BoxDecoration(
          color: const Color(0xFF1E8A4C),
          border: Border.all(color: Djassa.encre, width: Djassa.trait),
          borderRadius: BorderRadius.circular(Djassa.rayonPanneau),
          boxShadow: const <BoxShadow>[
            BoxShadow(color: Djassa.encre, offset: Offset(0, 5)),
          ],
        ),
        child: Column(
          children: <Widget>[
            Text('✓', style: Djassa.affiche(52, couleur: Djassa.creme, hauteur: 1)),
            const SizedBox(height: 8),
            Text(
              'C\'est enregistré !',
              textAlign: TextAlign.center,
              style: Djassa.affiche(26, couleur: Djassa.creme),
            ),
            const SizedBox(height: 6),
            Text(
              '${bien?.publicRef ?? ''} · fait en $_minuterie',
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                color: Djassa.creme,
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 24),
      if (_sansPhotos) ...<Widget>[
        const SizedBox(height: 16),
        const EncadreErreur(
          'Les photos n\'ont pas pu être proposées : le serveur n\'a pas rendu la '
          'référence interne de ce bien. Ton bien EST enregistré et protégé — ajoute '
          'les photos depuis sa fiche, dans « Mes biens ».',
        ),
      ],
      const SizedBox(height: 24),
      Text('Renforce ta preuve 💪', style: Djassa.affiche(22)),
      const SizedBox(height: 12),
      // CE QUE CHAQUE GESTE FAIT GAGNER est écrit à côté. Sans cela, « ajoute ta
      // facture » est une corvée sans contrepartie ; avec, c'est un échange.
      _Renfort(
        libelle: 'Ajoute ta facture',
        gain: '→ DOCUMENTÉ',
        couleurGain: const Color(0xFF1D4ED8),
        onTap: () => Navigator.of(context).pop(true),
      ),
      const SizedBox(height: 10),
      _Renfort(
        libelle: 'Pièce d\'identité + selfie',
        gain: '→ VÉRIFIÉ',
        couleurGain: const Color(0xFF1E8A4C),
        onTap: () => Navigator.of(context).pop(true),
      ),
      const SizedBox(height: 10),
      _Renfort(
        libelle: 'Envoyer les photos maintenant',
        gain: 'FILE D\'ENVOI',
        couleurGain: Djassa.etiquette,
        onTap: () => Navigator.of(context).pop(true),
      ),
      const SizedBox(height: 14),
      Center(
        child: TextButton(
          style: TextButton.styleFrom(minimumSize: const Size.fromHeight(44)),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text(
            'Plus tard → Mes biens',
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 15,
              fontWeight: FontWeight.w700,
              decoration: TextDecoration.underline,
            ),
          ),
        ),
      ),
    ];
  }
}

class _Entete extends StatelessWidget {
  const _Entete({required this.minuterie, required this.depasse});

  final String minuterie;
  final bool depasse;

  @override
  Widget build(BuildContext context) {
    // LES DEUX BLOCS PEUVENT RÉTRÉCIR. Cette rangée débordait de 17 pixels sur
    // un écran de téléphone, dès que le réglage d'accessibilité agrandit le
    // texte — c'est-à-dire chez les gens que cette application vise en premier.
    // Un débordement n'est pas cosmétique : il barre l'écran de rayures en
    // débogage, et rogne une cible tactile en production.
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: <Widget>[
        Flexible(
          child: OutlinedButton(
          style: OutlinedButton.styleFrom(
            minimumSize: const Size(0, 44),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            side: const BorderSide(color: Djassa.encre, width: 2),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
            textStyle: const TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 14,
              fontWeight: FontWeight.w700,
            ),
          ),
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('← Quitter', maxLines: 1, overflow: TextOverflow.ellipsis),
          ),
        ),
        const SizedBox(width: 8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: Djassa.encre,
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text.rich(
            TextSpan(
              children: <TextSpan>[
                TextSpan(
                  text: '⏱ $minuterie ',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    // Le dépassement se voit, il ne se punit pas : c'est la
                    // mesure d'une promesse d'équipe, pas une note donnée à
                    // l'utilisateur.
                    color: depasse ? Djassa.alerte : Djassa.ambre,
                  ),
                ),
                const TextSpan(
                  text: '/ 1:30',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: Color(0xA6FFF6E8),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _Jalons extends StatelessWidget {
  const _Jalons({required this.etape});

  final int etape;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: List<Widget>.generate(4, (int i) {
        return Expanded(
          child: Container(
            height: 8,
            margin: EdgeInsets.only(right: i == 3 ? 0 : 6),
            decoration: BoxDecoration(
              color: i < etape ? Djassa.accent : Colors.white,
              border: Border.all(color: Djassa.encre, width: 2),
              borderRadius: BorderRadius.circular(999),
            ),
          ),
        );
      }),
    );
  }
}

class _CarteType extends StatelessWidget {
  const _CarteType({required this.categorie, required this.onChoisir});

  final AssetCategory categorie;
  final VoidCallback onChoisir;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onChoisir,
      child: Container(
        constraints: const BoxConstraints(minHeight: 72),
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Djassa.encre, width: Djassa.trait),
          borderRadius: BorderRadius.circular(Djassa.rayon),
          boxShadow: Djassa.relief(),
        ),
        child: Row(
          children: <Widget>[
            // L'icône vient du catalogue : elle change avec lui, sans livraison.
            Text(categorie.icon, style: const TextStyle(fontSize: 30)),
            const SizedBox(width: 16),
            Expanded(child: Text(categorie.name, style: Djassa.affiche(21))),
          ],
        ),
      ),
    );
  }
}

class _CadreScan extends StatelessWidget {
  const _CadreScan();

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 130,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.etiquette, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: const Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Text('▣', style: TextStyle(fontSize: 34, color: Djassa.etiquette)),
          SizedBox(height: 8),
          Text(
            'Scan de la carte grise — bientôt',
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: Djassa.etiquette,
            ),
          ),
        ],
      ),
    );
  }
}

class _CasePhoto extends StatelessWidget {
  const _CasePhoto({
    required this.icone,
    required this.nom,
    required this.prise,
    required this.onTap,
  });

  final String icone;
  final String nom;
  final bool prise;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          color: prise ? Djassa.accent : Colors.white,
          border: Border.all(color: Djassa.encre, width: Djassa.trait),
          borderRadius: BorderRadius.circular(Djassa.rayon),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Text(prise ? '✓' : icone, style: const TextStyle(fontSize: 32)),
            const SizedBox(height: 6),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: Text(
                nom,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: prise ? Djassa.creme : Djassa.encre,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Renfort extends StatelessWidget {
  const _Renfort({
    required this.libelle,
    required this.gain,
    required this.couleurGain,
    required this.onTap,
  });

  final String libelle;
  final String gain;
  final Color couleurGain;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        constraints: const BoxConstraints(minHeight: 60),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Djassa.encre, width: Djassa.trait),
          borderRadius: BorderRadius.circular(Djassa.rayon),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: <Widget>[
            Expanded(
              child: Text(
                libelle,
                style: const TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  color: Djassa.encre,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Text(
              gain,
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: couleurGain,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
