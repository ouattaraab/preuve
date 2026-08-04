import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Inscription : trois informations, pas plus.
///
/// PAS DE PIÈCE D'IDENTITÉ POUR COMMENCER, et c'est une décision (CT-06). La
/// friction est réservée aux gestes qui engagent — céder un bien, réclamer
/// celui d'un autre. Demander une CNI pour ouvrir un compte écarterait
/// précisément ceux que la plateforme doit protéger : quelqu'un qui vient de se
/// faire voler sa moto et n'a plus ses papiers sur lui.
///
/// LE NOM EST FACULTATIF CÔTÉ SERVEUR, et il n'atteste rien : seul le KYC le
/// fait. Il sert à s'adresser à quelqu'un, pas à l'identifier — l'écran ne doit
/// donc pas le présenter comme une vérification.
///
/// C'EST TOUJOURS UN CODE QUI OUVRE LE COMPTE, jamais ce formulaire : le motif
/// `register` est vérifié par le serveur, et le compte n'existe qu'une fois le
/// code du téléphone confirmé.
class SignupScreen extends StatefulWidget {
  const SignupScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<SignupScreen> createState() => _SignupScreenState();
}

class _SignupScreenState extends State<SignupScreen> {
  final TextEditingController _nom = TextEditingController();
  final TextEditingController _courriel = TextEditingController();
  final TextEditingController _telephone = TextEditingController();
  final TextEditingController _code = TextEditingController();

  bool _codeDemande = false;
  bool _enCours = false;
  String? _erreur;

  @override
  void dispose() {
    _nom.dispose();
    _courriel.dispose();
    _telephone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _demanderCode() async {
    setState(() {
      _erreur = null;
      _enCours = true;
    });

    try {
      await widget.session.auth.requestCode(
        _telephone.text.trim(),
        OtpPurpose.register,
        email: _courriel.text.trim(),
      );

      if (mounted) {
        setState(() => _codeDemande = true);
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

  Future<void> _creer() async {
    setState(() {
      _erreur = null;
      _enCours = true;
    });

    try {
      final compte = await widget.session.auth.verify(
        _telephone.text.trim(),
        _code.text.trim(),
        OtpPurpose.register,
        email: _courriel.text.trim(),
        fullName: _nom.text.trim(),
      );

      if (mounted) {
        Navigator.of(context).pop(compte);
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BarrePreuve(titre: 'Inscription'),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text('Inscription', style: Djassa.affiche(34)),
              const SizedBox(height: 8),
              const Text(
                '3 infos, pas plus. Pas de pièce d\'identité pour commencer.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 16,
                  height: 1.45,
                  color: Djassa.sourdine,
                ),
              ),
              const SizedBox(height: 20),
              if (!_codeDemande) ...<Widget>[
                ChampRelief(
                  controller: _nom,
                  libelle: 'NOM ET PRÉNOMS',
                  indication: 'Ex : Koné Awa',
                  tailleTexte: 17,
                ),
                const SizedBox(height: 14),
                ChampRelief(
                  controller: _courriel,
                  libelle: 'EMAIL',
                  indication: 'awa@exemple.ci',
                  clavier: TextInputType.emailAddress,
                  tailleTexte: 17,
                ),
                const SizedBox(height: 14),
                ChampRelief(
                  controller: _telephone,
                  libelle: 'NUMÉRO DE TÉLÉPHONE',
                  indication: '+225 07 00 00 00 00',
                  clavier: TextInputType.phone,
                  tailleTexte: 17,
                  erreur: _erreur,
                ),
                const SizedBox(height: 18),
                BoutonRelief(
                  libelle: 'Je crée mon compte',
                  enCours: _enCours,
                  onPressed: _demanderCode,
                ),
              ] else ...<Widget>[
                Text(
                  'Entre le code reçu au ${_telephone.text.trim()}.',
                  style: const TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 14),
                ChampRelief(
                  controller: _code,
                  indication: '····',
                  clavier: TextInputType.number,
                  autofocus: true,
                  tailleTexte: 26,
                  erreur: _erreur,
                ),
                const SizedBox(height: 14),
                BoutonRelief(
                  libelle: 'Valider mon compte',
                  enCours: _enCours,
                  onPressed: _creer,
                ),
              ],
              const SizedBox(height: 24),
              const Text(
                'Ton nom n\'apparaît jamais sur la fiche publique d\'un bien. Un acheteur qui '
                'vérifie un numéro voit son statut, jamais qui l\'a enregistré.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  color: Djassa.sourdine,
                  height: 1.5,
                  fontSize: 15,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
