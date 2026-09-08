import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Le règlement qui précède une déclaration de vol, quand un exploitant en a
/// ouvert un (ST-0604).
///
/// CET ÉCRAN N'EXISTE QUE SI LE TARIF EST OUVERT. Il vaut zéro par défaut, et
/// la fiche du bien ne l'ouvre alors jamais : la déclaration reste ce qu'elle
/// doit être, gratuite et immédiate. Celui qui déclare vient de se faire
/// dépouiller.
///
/// IL DIT CE QUI RESTE ACQUIS SANS PAYER. Devant un montant, on croit que sans
/// lui son bien n'est protégé par rien — et on renonce à l'enregistrement, qui
/// vaut déjà. La ligne verte est là pour cela, et elle vient avant le prix.
///
/// L'ORDRE EST PAYER, PUIS RECEVOIR LE CODE. Le serveur émet le code quand
/// l'opérateur confirme ; l'écran ne le demande pas lui-même. Un code émis
/// avant la page bancaire aurait expiré pendant la traversée.
///
/// IL REND `true` UNIQUEMENT SI LE SERVEUR DIT QUE C'EST RÉGLÉ. Jamais sur la
/// foi d'un « j'ai payé » : l'appelant enchaînerait sur une saisie de code que
/// le serveur refuserait, et l'utilisateur croirait avoir mal recopié.
class TheftFeeScreen extends StatefulWidget {
  const TheftFeeScreen({required this.session, required this.bien, super.key});

  final PreuveSession session;
  final OwnedAsset bien;

  @override
  State<TheftFeeScreen> createState() => _TheftFeeScreenState();
}

class _TheftFeeScreenState extends State<TheftFeeScreen> with WidgetsBindingObserver {
  TheftFee? _etat;
  bool _enCours = true;
  bool _paiementOuvert = false;
  String? _erreur;
  String? _confirmation;

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
  /// LE RÈGLEMENT SE FAIT HORS DE L'APPLICATION : sans cette relecture, il
  /// revient sur un écran inchangé et la seule conduite évidente est de payer
  /// une seconde fois. On ne relit que si une caisse a été ouverte — rappeler
  /// le serveur à chaque passage en avant-plan coûterait de la donnée en 3G
  /// pour rien (CT-05).
  @override
  void didChangeAppLifecycleState(AppLifecycleState etat) {
    if (etat == AppLifecycleState.resumed && _paiementOuvert && !_enCours) {
      _verifier();
    }
  }

  Future<void> _charger() async {
    try {
      final TheftFee etat = await widget.session.peageVol.state(widget.bien.id);

      if (!mounted) {
        return;
      }

      // DÉJÀ RÉGLÉ : on ne fait pas relire un écran de paiement à quelqu'un
      // qui a payé. On rend la main, le geste reprend où il s'était arrêté.
      if (etat.paid) {
        Navigator.of(context).pop(true);

        return;
      }

      setState(() => _etat = etat);
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

  Future<void> _payer() async {
    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      final TheftFee etat = await widget.session.peageVol.pay(widget.bien.id);

      if (!mounted) {
        return;
      }

      final String? caisse = etat.checkoutUrl;

      if (caisse == null) {
        // Aucune page à ouvrir : soit c'est devenu gratuit, soit l'opérateur
        // se règle hors application. Dans les deux cas on rend la main plutôt
        // que d'afficher un bouton mort.
        if (etat.paid) {
          Navigator.of(context).pop(true);

          return;
        }

        setState(() {
          _etat = etat;
          _confirmation = etat.message;
        });

        return;
      }

      // LE RÈGLEMENT SE FAIT DANS LE NAVIGATEUR, jamais dans une vue web
      // embarquée : c'est la barre d'adresse qui permet de vérifier qu'on est
      // bien chez l'opérateur et non sur une imitation.
      final bool ouverte = await launchUrl(Uri.parse(caisse), mode: LaunchMode.externalApplication);

      if (mounted) {
        setState(() {
          _etat = etat;
          _paiementOuvert = ouverte;
          _confirmation = ouverte
              ? 'Règle le montant, puis reviens ici : ton code de confirmation part dès '
                  'que l\'opérateur aura confirmé.'
              : null;
          _erreur = ouverte ? null : 'Impossible d\'ouvrir la page de paiement.';
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

  /// Relit l'état côté serveur après le retour de la page de paiement.
  Future<void> _verifier() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final TheftFee etat = await widget.session.peageVol.state(widget.bien.id);

      if (!mounted) {
        return;
      }

      if (etat.paid) {
        Navigator.of(context).pop(true);

        return;
      }

      setState(() {
        _etat = etat;
        // ON NE DIT PAS « ÇA A ÉCHOUÉ ». Un webhook met parfois quelques
        // secondes ; annoncer un échec ferait repayer quelqu'un qui a payé.
        _confirmation = 'Le paiement n\'est pas encore confirmé par l\'opérateur. '
            'Réessaie dans un instant — ne repaie pas.';
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

  @override
  Widget build(BuildContext context) {
    final TheftFee? etat = _etat;

    return Scaffold(
      appBar: const BarrePreuve(titre: 'Déclarer un vol'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          children: <Widget>[
            Text('Signaler ce bien volé', style: Djassa.affiche(28)),
            const SizedBox(height: 14),

            // CE QUI RESTE ACQUIS SANS PAYER, EN PREMIER ET EN VERT.
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
                    'Acquis, quoi qu\'il arrive',
                    style: TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                      color: Color(0xFF1F7A4C),
                    ),
                  ),
                  SizedBox(height: 6),
                  Text(
                    'Ton bien reste enregistré à ton nom et consultable par son numéro. '
                    'Cela ne se paie pas et ne se perd pas.',
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

            if (etat == null)
              const EnCours()
            else ...<Widget>[
              Text(
                etat.explanation ??
                    'Le règlement couvre la déclaration de vol elle-même.',
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
                        '${etat.feeFcfa} FCFA',
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
                libelle: _paiementOuvert ? 'Rouvrir la page de paiement' : 'Payer et continuer',
                enCours: _enCours,
                onPressed: _payer,
              ),
              if (_paiementOuvert) ...<Widget>[
                const SizedBox(height: 10),
                BoutonRelief(
                  libelle: 'J\'ai payé, vérifier',
                  principal: false,
                  enCours: _enCours,
                  onPressed: _verifier,
                ),
              ],
              const SizedBox(height: 12),
              const Text(
                'Ton code de confirmation te sera envoyé dès que le paiement sera '
                'confirmé. Tu déclareras le vol avec ce code.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  height: 1.45,
                  color: Djassa.etiquette,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
