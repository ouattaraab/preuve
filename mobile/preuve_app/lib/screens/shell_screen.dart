import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/theme.dart';
import 'login_screen.dart';
import 'lookup_screen.dart';
import 'my_assets_screen.dart';
import 'register_screen.dart';

/// La coquille de l'application : trois onglets, une barre en bas.
///
/// L'ORDRE N'EST PAS NÉGOCIABLE. « Vérifier » est le premier onglet et l'écran
/// d'ouverture, parce que c'est la promesse du produit et l'usage majoritaire :
/// quelqu'un qui vérifie une moto au marché n'a pas de compte et n'en aura
/// peut-être jamais. Mettre « Mes biens » en tête ferait de la plateforme un
/// service pour propriétaires, alors qu'elle sert d'abord à celui qui achète.
///
/// LES DEUX AUTRES ONGLETS DEMANDENT UN COMPTE, mais seulement au moment où on
/// les touche (CT-06). Ils restent visibles et lisibles : les cacher tant qu'on
/// n'est pas connecté priverait quelqu'un de savoir que la plateforme protège
/// aussi ses propres biens.
class ShellScreen extends StatefulWidget {
  const ShellScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<ShellScreen> createState() => _ShellScreenState();
}

class _ShellScreenState extends State<ShellScreen> {
  int _onglet = 0;

  /// Demande une connexion si nécessaire. Rend vrai si l'on peut continuer.
  Future<bool> _assurerCompte() async {
    final session = widget.session;

    if (session.estConnecte) {
      return true;
    }

    final compte = await Navigator.of(context).push<Account>(
      MaterialPageRoute<Account>(builder: (_) => LoginScreen(session: session)),
    );

    if (compte == null) {
      return false;
    }

    session.compte = compte;

    return true;
  }

  Future<void> _aller(int cible) async {
    // « Protéger » n'est pas un onglet mais un GESTE : il ouvre le parcours
    // d'enregistrement et rend la main. En faire un onglet laisserait un
    // formulaire à moitié rempli dans un onglet qu'on croit avoir quitté.
    if (cible == 1) {
      if (!await _assurerCompte()) {
        return;
      }

      if (!mounted) {
        return;
      }

      final cree = await Navigator.of(context).push<bool>(
        MaterialPageRoute<bool>(
          builder: (_) => RegisterScreen(session: widget.session),
        ),
      );

      if (cree == true && mounted) {
        setState(() => _onglet = 2);
      }

      return;
    }

    if (cible == 2 && !await _assurerCompte()) {
      return;
    }

    if (mounted) {
      setState(() => _onglet = cible);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;

    return Scaffold(
      body: _onglet == 2 && session.estConnecte
          ? MyAssetsScreen(session: session)
          : LookupScreen(session: session),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          color: Djassa.creme,
          border: Border(top: BorderSide(color: Djassa.encre, width: Djassa.trait)),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: Row(
              children: <Widget>[
                _Onglet(
                  libelle: 'Vérifier',
                  actif: _onglet == 0,
                  onTap: () => _aller(0),
                ),
                const SizedBox(width: 6),
                _Onglet(
                  libelle: 'Protéger',
                  actif: false,
                  onTap: () => _aller(1),
                ),
                const SizedBox(width: 6),
                _Onglet(
                  libelle: 'Mes biens',
                  actif: _onglet == 2,
                  onTap: () => _aller(2),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Onglet extends StatelessWidget {
  const _Onglet({required this.libelle, required this.actif, required this.onTap});

  final String libelle;
  final bool actif;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: Container(
          // UNE HAUTEUR FIXE, PAS UN MINIMUM. Dans une barre de navigation, la
          // contrainte verticale reçue n'est pas bornée : un `minHeight` laisse
          // le conteneur prendre toute la hauteur offerte, et la barre avale
          // l'écran entier. Constaté sur simulateur.
          height: 52,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: actif ? Djassa.encre : Colors.transparent,
            border: Border.all(
              color: actif ? Djassa.encre : Colors.transparent,
              width: 2,
            ),
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(
            libelle,
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: actif ? Djassa.creme : Djassa.encre,
            ),
          ),
        ),
      ),
    );
  }
}
