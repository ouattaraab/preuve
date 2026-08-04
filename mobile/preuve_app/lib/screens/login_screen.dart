import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../ui/theme.dart';

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
  const LoginScreen({required this.auth, this.purpose = OtpPurpose.login, super.key});

  final AuthService auth;
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
      _validite = await widget.auth.requestCode(_telephone.text.trim(), widget.purpose);

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
      final compte = await widget.auth.verify(
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
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Connexion', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                _codeDemande ? 'Entre le code reçu' : 'Ton numéro de téléphone',
                style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              Text(
                _codeDemande
                    ? 'Il est valable ${_validite.inMinutes} minutes. Si tu ne reçois rien, '
                        'vérifie le numéro et redemande un code.'
                    : 'Pas de mot de passe : nous t\'envoyons un code à usage unique.',
                style: const TextStyle(color: Djassa.sourdine, height: 1.4),
              ),
              const SizedBox(height: 22),
              if (!_codeDemande)
                TextField(
                  controller: _telephone,
                  autofocus: true,
                  keyboardType: TextInputType.phone,
                  style: const TextStyle(fontSize: 22),
                  decoration: const InputDecoration(
                    labelText: 'Téléphone',
                    hintText: '+225 01 01 18 16 86',
                  ),
                )
              else
                TextField(
                  controller: _code,
                  autofocus: true,
                  keyboardType: TextInputType.number,
                  style: const TextStyle(fontSize: 26, letterSpacing: 8),
                  textAlign: TextAlign.center,
                  decoration: const InputDecoration(labelText: 'Code reçu'),
                ),
              if (_erreur != null) ...<Widget>[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFDE7E4),
                    border: Border.all(color: Djassa.alerte, width: 3),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    _erreur!,
                    style: const TextStyle(fontWeight: FontWeight.w700, height: 1.4),
                  ),
                ),
              ],
              const SizedBox(height: 14),
              FilledButton(
                onPressed: _enCours ? null : (_codeDemande ? _verifier : _demanderCode),
                child: _enCours
                    ? const SizedBox(
                        height: 24,
                        width: 24,
                        child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
                      )
                    : Text(_codeDemande ? 'Se connecter' : 'Recevoir un code'),
              ),
              if (_codeDemande) ...<Widget>[
                const SizedBox(height: 10),
                TextButton(
                  onPressed: _enCours
                      ? null
                      : () => setState(() {
                            _codeDemande = false;
                            _code.clear();
                          }),
                  child: const Text('Changer de numéro'),
                ),
              ],
              const SizedBox(height: 28),
              const Text(
                'Vérifier un bien ne demande jamais de compte. La connexion ne sert qu\'à '
                'enregistrer, transférer ou déclarer un vol.',
                style: TextStyle(color: Djassa.sourdine, height: 1.5),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
