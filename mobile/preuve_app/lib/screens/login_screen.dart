import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';
import 'signup_screen.dart';

/// Connexion par code à usage unique. Il n'existe aucun mot de passe.
///
/// DEUX TEMPS, ET LE PREMIER NE DIT RIEN. La réponse du serveur à une demande
/// de code est invariable, que le numéro soit connu ou non : toute différence
/// observable ferait de cette étape un service d'énumération d'abonnés. Cet
/// écran ne doit donc jamais afficher « compte inconnu » ni proposer une
/// inscription sur cette base — il n'en sait rien, et c'est voulu.
///
/// LA FRICTION EST ICI PARCE QUE LE RISQUE EST ICI (CT-06). Consulter ne
/// demande rien ; se connecter ouvre l'accès aux biens d'une personne.
class LoginScreen extends StatefulWidget {
  const LoginScreen({
    required this.session,
    this.purpose = OtpPurpose.login,
    super.key,
  });

  final PreuveSession session;
  final OtpPurpose purpose;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final TextEditingController _telephone = TextEditingController();
  final TextEditingController _code = TextEditingController();

  bool _codeDemande = false;
  bool _enCours = false;
  String? _erreur;
  Duration _validite = const Duration(minutes: 5);

  @override
  void dispose() {
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
      _validite = await widget.session.auth.requestCode(
        _telephone.text.trim(),
        widget.purpose,
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

  Future<void> _verifier() async {
    setState(() {
      _erreur = null;
      _enCours = true;
    });

    try {
      final compte = await widget.session.auth.verify(
        _telephone.text.trim(),
        _code.text.trim(),
        widget.purpose,
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
      appBar: const BarrePreuve(titre: 'Connexion'),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text('Connexion', style: Djassa.affiche(34)),
              const SizedBox(height: 8),
              Text(
                _codeDemande
                    ? 'Code envoyé à ${_telephone.text.trim()}. Il est valable '
                        '${_validite.inMinutes} minutes.'
                    : 'Pas de mot de passe : nous t\'envoyons un code à usage unique.',
                style: const TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 16,
                  height: 1.45,
                  color: Djassa.sourdine,
                ),
              ),
              const SizedBox(height: 22),
              if (!_codeDemande) ...<Widget>[
                // LES DEUX SONT ACCEPTÉS, ET LE CHAMP LE DIT. Aucune
                // passerelle SMS n'est branchée : le code part par courriel, et
                // n'accepter qu'un numéro fermerait le produit à qui n'a pas
                // déjà un compte. Le clavier reste ordinaire — un clavier
                // numérique empêcherait de taper une adresse.
                ChampRelief(
                  controller: _telephone,
                  indication: 'Téléphone ou e-mail',
                  clavier: TextInputType.emailAddress,
                  autofocus: true,
                  tailleTexte: 17,
                  erreur: _erreur,
                ),
                const SizedBox(height: 8),
                const Text(
                  'Le code part par e-mail pour le moment. Avec un numéro, il faut '
                  'qu\'une adresse soit déjà associée à ton compte.',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 14,
                    height: 1.45,
                    color: Djassa.etiquette,
                  ),
                ),
                const SizedBox(height: 14),
                BoutonRelief(
                  libelle: 'Recevoir mon code',
                  enCours: _enCours,
                  onPressed: _demanderCode,
                ),
                const SizedBox(height: 16),
                _LienSouligne(
                  libelle: 'Pas encore de compte ? Je m\'inscris',
                  onPressed: () async {
                    // Le navigateur est saisi AVANT le détour par l'inscription :
                    // `mounted` porte sur l'état, pas sur ce contexte-là.
                    final navigateur = Navigator.of(context);

                    final compte = await navigateur.push<Account>(
                      MaterialPageRoute<Account>(
                        builder: (_) => SignupScreen(session: widget.session),
                      ),
                    );

                    // Une inscription réussie vaut connexion : la repasser par
                    // l'écran de code ferait redemander un second code pour
                    // rien, à quelqu'un qui vient d'en saisir un.
                    if (compte != null) {
                      navigateur.pop(compte);
                    }
                  },
                ),
              ] else ...<Widget>[
                _ChampCode(controller: _code),
                if (_erreur != null) ...<Widget>[
                  const SizedBox(height: 12),
                  EncadreErreur(_erreur!),
                ],
                const SizedBox(height: 14),
                BoutonRelief(
                  libelle: 'Je me connecte',
                  enCours: _enCours,
                  onPressed: _verifier,
                ),
                const SizedBox(height: 14),
                _LienSouligne(
                  libelle: 'Renvoyer le code',
                  pale: true,
                  onPressed: _enCours ? null : _demanderCode,
                ),
                _LienSouligne(
                  libelle: 'Changer de numéro',
                  pale: true,
                  onPressed: _enCours
                      ? null
                      : () => setState(() {
                            _codeDemande = false;
                            _erreur = null;
                            _code.clear();
                          }),
                ),
              ],
              const SizedBox(height: 28),
              const Text(
                'Vérifier un bien ne demande jamais de compte. La connexion ne sert qu\'à '
                'enregistrer, transférer ou déclarer un vol.',
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

/// Le champ de code : gros, espacé, centré. On le recopie depuis un SMS, d'une
/// main, souvent en marchant.
class _ChampCode extends StatelessWidget {
  const _ChampCode({required this.controller});

  final TextEditingController controller;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(Djassa.rayon),
        boxShadow: Djassa.relief(),
      ),
      child: TextField(
        controller: controller,
        autofocus: true,
        keyboardType: TextInputType.number,
        textAlign: TextAlign.center,
        style: const TextStyle(
          fontFamily: Djassa.titre,
          fontWeight: FontWeight.w800,
          fontSize: 34,
          letterSpacing: 17,
          color: Djassa.encre,
        ),
        decoration: const InputDecoration(
          hintText: '····',
          contentPadding: EdgeInsets.symmetric(vertical: 14, horizontal: 14),
        ),
      ),
    );
  }
}

class _LienSouligne extends StatelessWidget {
  const _LienSouligne({
    required this.libelle,
    required this.onPressed,
    this.pale = false,
  });

  final String libelle;
  final VoidCallback? onPressed;
  final bool pale;

  @override
  Widget build(BuildContext context) {
    return TextButton(
      style: TextButton.styleFrom(minimumSize: const Size.fromHeight(44)),
      onPressed: onPressed,
      child: Text(
        libelle,
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: pale ? 14 : 15,
          fontWeight: FontWeight.w700,
          color: pale ? Djassa.etiquette : Djassa.encre,
          decoration: TextDecoration.underline,
          decorationColor: pale ? Djassa.etiquette : Djassa.encre,
        ),
      ),
    );
  }
}
