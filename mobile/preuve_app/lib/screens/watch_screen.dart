import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Les veilles posées sur ses biens (ST-0403, ST-0405).
///
/// CE QUE C'EST, DIT SANS JARGON. Être prévenu quand un bien qu'on a
/// enregistré est consulté — parce qu'une rafale de consultations sur un bien
/// qu'on n'a pas mis en vente veut souvent dire qu'un autre le vend. Le
/// service, la détection de pics et les notifications existaient depuis EP-04 ;
/// aucun écran ne les appelait, et personne ne pouvait donc en poser une.
///
/// ON NE PEUT SURVEILLER QUE SES PROPRES BIENS, et le serveur le refuse
/// autrement : savoir quand le bien d'autrui est consulté, c'est savoir quand
/// il est mis en vente. L'écran ne propose donc que les biens du compte, plutôt
/// que de laisser saisir un numéro et de faire refuser par le serveur — un
/// refus qu'on aurait pu éviter n'apprend rien à personne.
///
/// L'ANONYMAT TIENT DANS LES DEUX SENS (règle métier absolue n° 4). Une veille
/// dit QUE le bien a été consulté et COMBIEN de fois ; jamais par qui.
class WatchScreen extends StatefulWidget {
  const WatchScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<WatchScreen> createState() => _WatchScreenState();
}

class _WatchScreenState extends State<WatchScreen> {
  List<WatchAlert>? _veilles;
  List<OwnedAsset> _biens = const <OwnedAsset>[];
  bool _occupe = false;
  String? _erreur;
  String? _confirmation;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final List<WatchAlert> veilles = await widget.session.veilles.mine();
      final Inventory inventaire = await widget.session.assets.mine();

      if (mounted) {
        setState(() {
          _veilles = veilles;
          _biens = inventaire.assets;
        });
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() {
          _veilles = const <WatchAlert>[];
          _erreur = messageDeRefus(e);
        });
      }
    }
  }

  Future<void> _poser(OwnedAsset bien) async {
    setState(() {
      _occupe = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      await widget.session.veilles.watch(bien.identifier);

      if (mounted) {
        setState(() => _confirmation = 'Veille posée sur ${bien.identifier}.');
      }

      await _charger();
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _occupe = false);
      }
    }
  }

  Future<void> _retirer(WatchAlert veille) async {
    setState(() {
      _occupe = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      // L'IDENTIFIANT RENDU PAR LE SERVEUR, pas celui saisi : il est
      // normalisé, et lui renvoyer une forme différente ne retirerait rien.
      await widget.session.veilles.unwatch(veille.identifier);

      if (mounted) {
        setState(() => _confirmation = 'Veille retirée.');
      }

      await _charger();
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _occupe = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final List<WatchAlert>? veilles = _veilles;
    final Set<String> surveilles = <String>{
      for (final WatchAlert v in veilles ?? const <WatchAlert>[]) v.identifier,
    };

    // Un bien n'est proposé qu'une fois : la comparaison se fait sur la forme
    // normalisée que le serveur a rendue, sans quoi un même bien réapparaîtrait
    // dans la liste des propositions après avoir été surveillé.
    final List<OwnedAsset> aProposer = _biens
        .where((OwnedAsset b) => !surveilles.contains(_normalise(b.identifier)))
        .toList(growable: false);

    return Scaffold(
      appBar: const BarrePreuve(titre: 'Veille'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          children: <Widget>[
            Text('Être prévenu', style: Djassa.affiche(28)),
            const SizedBox(height: 12),
            const Text(
              'Une veille te prévient quand ton bien est consulté. Beaucoup de '
              'consultations sur un bien que tu n\'as pas mis en vente, c\'est souvent '
              'quelqu\'un d\'autre qui le vend.',
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 16,
                height: 1.5,
                color: Djassa.sourdine,
              ),
            ),
            const SizedBox(height: 10),
            const Text(
              'On te dit QUE ton bien a été consulté, jamais par qui.',
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 15,
                height: 1.45,
                fontWeight: FontWeight.w700,
                color: Djassa.etiquette,
              ),
            ),
            const SizedBox(height: 18),

            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 14),
            ],
            if (_confirmation != null) ...<Widget>[
              EncadreConfirmation(_confirmation!),
              const SizedBox(height: 14),
            ],

            if (veilles == null)
              const EnCours()
            else ...<Widget>[
              if (veilles.isEmpty)
                const RienEncore(
                  titre: 'Aucune veille',
                  explication: 'Choisis un bien ci-dessous pour être prévenu de ses '
                      'consultations.',
                )
              else
                ...veilles.map((WatchAlert v) => _Carte(
                      veille: v,
                      occupe: _occupe,
                      onRetirer: () => _retirer(v),
                    )),

              const SizedBox(height: 22),
              Text('Poser une veille', style: Djassa.affiche(20)),
              const SizedBox(height: 10),

              if (_biens.isEmpty)
                const Text(
                  'Tu n\'as encore enregistré aucun bien.',
                  style: TextStyle(fontFamily: Djassa.texte, fontSize: 16, color: Djassa.sourdine),
                )
              else if (aProposer.isEmpty)
                const Text(
                  'Tous tes biens sont déjà sous veille.',
                  style: TextStyle(fontFamily: Djassa.texte, fontSize: 16, color: Djassa.sourdine),
                )
              else
                ...aProposer.map((OwnedAsset b) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: BoutonRelief(
                        libelle: b.identifier,
                        principal: false,
                        enCours: _occupe,
                        onPressed: () => _poser(b),
                      ),
                    )),
            ],
          ],
        ),
      ),
    );
  }

  /// La forme que le serveur rend : majuscules, sans séparateurs.
  static String _normalise(String identifiant) =>
      identifiant.toUpperCase().replaceAll(RegExp('[^A-Z0-9]'), '');
}

class _Carte extends StatelessWidget {
  const _Carte({required this.veille, required this.occupe, required this.onRetirer});

  final WatchAlert veille;
  final bool occupe;
  final VoidCallback onRetirer;

  @override
  Widget build(BuildContext context) {
    final DateTime? dernier = veille.lastTriggeredAt;

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
        boxShadow: Djassa.relief(),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(veille.identifier, style: Djassa.affiche(20)),
          const SizedBox(height: 6),
          Text(
            // « JAMAIS DÉCLENCHÉE » EST UNE BONNE NOUVELLE, et le dire évite de
            // prendre un silence pour une panne.
            dernier == null
                ? 'Rien à signaler depuis que la veille est posée.'
                : 'Dernière alerte le ${dernier.day}/${dernier.month}/${dernier.year}.',
            style: const TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 15,
              height: 1.45,
              color: Djassa.sourdine,
            ),
          ),
          const SizedBox(height: 12),
          BoutonRelief(
            libelle: 'Retirer cette veille',
            principal: false,
            enCours: occupe,
            onPressed: onRetirer,
          ),
        ],
      ),
    );
  }
}
