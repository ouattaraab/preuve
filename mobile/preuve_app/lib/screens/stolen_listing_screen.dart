import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Mettre son bien volé en avant sur la liste publique (ST-0805).
///
/// CET ÉCRAN DOIT DIRE CE QU'ON N'ACHÈTE PAS, avant de dire ce qu'on achète.
/// Le bien est DÉJÀ invendable pour quiconque vérifie son numéro : c'est la
/// protection, elle est acquise, immédiate et gratuite. Ne pas le rappeler
/// ferait payer quelqu'un pour ce qu'il a déjà — et un produit qui laisse
/// croire cela une fois n'est plus cru ensuite.
///
/// IL N'ARRIVE JAMAIS PENDANT LA DÉCLARATION. Proposer de payer au moment où
/// quelqu'un signale un vol reviendrait à monnayer sa détresse. On protège
/// d'abord, on propose après.
///
/// IL RELIT L'ÉTAT AU RETOUR DU NAVIGATEUR. Le règlement se fait hors de
/// l'application ; sans cette relecture, l'utilisateur revenait sur un écran
/// inchangé, bouton « Payer et publier » toujours en place — et la seule
/// conduite évidente était de payer une seconde fois. C'est la faute la plus
/// coûteuse qu'un écran de paiement puisse commettre, et elle ne se voit sur
/// aucun test qui ne quitte pas l'application.
class StolenListingScreen extends StatefulWidget {
  const StolenListingScreen({required this.session, required this.bien, super.key});

  final PreuveSession session;
  final OwnedAsset bien;

  @override
  State<StolenListingScreen> createState() => _StolenListingScreenState();
}

class _StolenListingScreenState extends State<StolenListingScreen>
    with WidgetsBindingObserver {
  ListingState? _etat;
  bool _enCours = true;
  String? _erreur;
  String? _confirmation;

  /// Vrai dès qu'une page de paiement a été ouverte : c'est ce qui distingue
  /// un retour d'application ordinaire d'un retour DE LA CAISSE.
  bool _paiementOuvert = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _charger();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// L'utilisateur revient — probablement de la page de l'opérateur.
  ///
  /// ON NE RELIT QUE SI UNE CAISSE A ÉTÉ OUVERTE : rappeler le serveur à chaque
  /// passage en avant-plan coûterait de la donnée en 3G pour rien (CT-05).
  @override
  void didChangeAppLifecycleState(AppLifecycleState etat) {
    if (etat == AppLifecycleState.resumed && _paiementOuvert && !_enCours) {
      _charger(discret: true);
    }
  }

  Future<void> _charger({bool discret = false}) async {
    // DISCRET AU RETOUR : remplacer l'écran par un indicateur de chargement
    // ferait clignoter ce que l'utilisateur vient de regarder.
    if (!discret) {
      setState(() => _enCours = true);
    }

    try {
      final ListingState etat = await widget.session.miseEnAvant.state(widget.bien.id);

      if (mounted) {
        setState(() {
          _etat = etat;

          if (etat.listed) {
            // ON NE DIT « PUBLIÉ » QUE SUR LA PAROLE DU SERVEUR, jamais sur
            // celle de l'utilisateur qui affirme avoir payé.
            _confirmation = 'Paiement confirmé : ton bien est sur la liste.';
            _paiementOuvert = false;
          } else if (discret && _paiementOuvert) {
            // NI ÉCHEC NI SILENCE. Le rappel de l'opérateur met parfois
            // quelques secondes : annoncer un échec ferait repayer.
            _confirmation = 'Le paiement n\'est pas encore confirmé par l\'opérateur. '
                'Réessaie dans un instant — ne repaie pas.';
          }
        });
      }
    } on PreuveException catch (e) {
      if (mounted && !discret) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted && !discret) {
        setState(() => _enCours = false);
      }
    }
  }

  Future<void> _publier() async {
    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      final ListingState etat = await widget.session.miseEnAvant.publish(widget.bien.id);

      if (!mounted) {
        return;
      }

      final String? paiement = etat.checkoutUrl;

      if (paiement != null) {
        // LE RÈGLEMENT SE FAIT DANS LE NAVIGATEUR, jamais dans une vue web
        // embarquée : c'est la barre d'adresse qui permet de vérifier qu'on est
        // bien chez l'opérateur et non sur une imitation.
        final bool ouverte = await launchUrl(Uri.parse(paiement), mode: LaunchMode.externalApplication);

        if (mounted) {
          setState(() {
            _etat = etat;
            _paiementOuvert = ouverte;
            _confirmation = ouverte
                ? 'Règle le montant, puis reviens : cet écran se mettra à jour tout seul.'
                : null;
            _erreur = ouverte ? null : 'Impossible d\'ouvrir la page de paiement.';
          });
        }

        return;
      }

      setState(() {
        _etat = etat;
        _confirmation = etat.message ?? 'Ton bien est maintenant sur la liste.';
      });
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

  Future<void> _retirer() async {
    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      final ListingState etat = await widget.session.miseEnAvant.withdraw(widget.bien.id);

      if (mounted) {
        setState(() {
          _etat = etat;
          _confirmation = etat.message ?? 'Ton bien ne paraît plus sur la liste.';
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

  @override
  Widget build(BuildContext context) {
    final ListingState? etat = _etat;

    return Scaffold(
      appBar: const BarrePreuve(titre: 'Mise en avant'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          children: <Widget>[
            Text('Faire connaître ton bien volé', style: Djassa.affiche(28)),
            const SizedBox(height: 14),

            // CE QUI EST DÉJÀ ACQUIS, EN PREMIER ET EN VERT. Sans cela, on
            // vend à quelqu'un ce qu'il possède déjà.
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                border: Border.all(color: const Color(0xFF1F7A4C), width: Djassa.trait),
                borderRadius: BorderRadius.circular(Djassa.rayon),
              ),
              child: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    'Déjà fait, et gratuit',
                    style: TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                      color: Color(0xFF1F7A4C),
                    ),
                  ),
                  SizedBox(height: 6),
                  Text(
                    'Ton bien est invendable pour quiconque vérifie son numéro. '
                    'Cela ne change pas, et cela ne se paie pas.',
                    style: TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 15,
                      height: 1.45,
                      color: Djassa.encre,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),

            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 14),
            ],
            if (_confirmation != null) ...<Widget>[
              EncadreConfirmation(_confirmation!),
              const SizedBox(height: 14),
            ],

            if (_enCours && etat == null)
              const EnCours()
            else if (etat != null) ...<Widget>[
              if (etat.listed) ...<Widget>[
                const RienEncore(
                  titre: 'Ton bien est sur la liste',
                  explication: 'Garagistes, acheteurs et forces de l\'ordre le voient en '
                      'parcourant la liste des biens volés, sans avoir besoin de connaître '
                      'son numéro.',
                ),
                const SizedBox(height: 14),
                BoutonRelief(
                  libelle: 'Le retirer de la liste',
                  principal: false,
                  enCours: _enCours,
                  onPressed: _retirer,
                ),
                const SizedBox(height: 10),
                const Text(
                  'Le retrait est gratuit et immédiat — si tu retrouves ton bien, il ne '
                  'reste pas exposé.',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 14,
                    height: 1.45,
                    color: Djassa.etiquette,
                  ),
                ),
              ] else ...<Widget>[
                Text('Ce que la mise en avant ajoute', style: Djassa.affiche(20)),
                const SizedBox(height: 8),
                Text(
                  etat.explanation ??
                      'Ton bien paraît sur la liste publique que tout le monde parcourt.',
                  style: const TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 16,
                    height: 1.5,
                    color: Djassa.sourdine,
                  ),
                ),
                const SizedBox(height: 18),
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    color: Djassa.encre,
                    borderRadius: BorderRadius.circular(Djassa.rayon),
                  ),
                  child: Row(
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          etat.free ? 'Gratuit' : '${etat.priceFcfa} FCFA',
                          style: Djassa.affiche(28, couleur: Djassa.ambre),
                        ),
                      ),
                      const Expanded(
                        child: Text(
                          'une seule fois,\npour ce bien',
                          style: TextStyle(
                            fontFamily: Djassa.texte,
                            fontSize: 13,
                            height: 1.35,
                            fontWeight: FontWeight.w700,
                            color: Djassa.creme,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                BoutonRelief(
                  libelle: etat.free
                      ? 'Publier mon bien'
                      : (_paiementOuvert ? 'Rouvrir la page de paiement' : 'Payer et publier'),
                  enCours: _enCours,
                  onPressed: _publier,
                ),
                // LE FILET DE SECOURS. La relecture au retour d'application
                // couvre le cas ordinaire ; ce bouton couvre celui où le
                // système n'a pas signalé le retour, ou celui où le rappel de
                // l'opérateur arrive après.
                if (_paiementOuvert) ...<Widget>[
                  const SizedBox(height: 10),
                  BoutonRelief(
                    libelle: 'J\'ai payé, vérifier',
                    principal: false,
                    enCours: _enCours,
                    onPressed: _charger,
                  ),
                ],
              ],
            ],
          ],
        ),
      ),
    );
  }
}
