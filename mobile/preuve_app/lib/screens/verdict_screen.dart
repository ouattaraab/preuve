import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/theme.dart';
import 'claim_screen.dart';
import 'login_screen.dart';

/// Le verdict, plein écran.
///
/// UN SYMBOLE ET UN MOT QUI TRANCHE, lisibles à deux mètres : c'est la
/// signature de la direction DJASSA, et la seule chose qu'on lit debout dans un
/// marché. Le détail vient après, pour qui veut le lire.
///
/// LES TROIS ISSUES NE SE CONFONDENT JAMAIS. Un identifiant inconnu n'est ni un
/// bon ni un mauvais signe : l'afficher en vert ferait acheter un bien volé que
/// personne n'a déclaré. Il a donc sa propre couleur et son propre mot.
///
/// LA COULEUR D'UN BIEN CONNU VIENT DU SERVEUR (CT-04). Les seules teintes
/// décidées ici sont celles des trois cas où il n'y a PAS de bien : inconnu,
/// illisible, plafond atteint.
class VerdictScreen extends StatelessWidget {
  const VerdictScreen({
    required this.resultat,
    required this.saisie,
    required this.session,
    super.key,
  });

  final LookupResult resultat;
  final String saisie;
  final PreuveSession session;

  /// Ouvre le parcours de réclamation sur le bien affiché.
  ///
  /// LA CONNEXION N'EST DEMANDÉE QU'AU DERNIER MOMENT (CT-06) : lire un verdict
  /// reste anonyme, y compris quand il annonce à quelqu'un que son propre bien
  /// est enregistré au nom d'un autre. C'est le dossier qui exige un compte,
  /// parce qu'il faudra pouvoir en répondre.
  Future<void> _reclamer(BuildContext context, String publicRef) async {
    if (!session.estConnecte) {
      final compte = await Navigator.of(context).push<Account>(
        MaterialPageRoute<Account>(builder: (_) => LoginScreen(session: session)),
      );

      if (compte == null) {
        return;
      }

      session.compte = compte;
    }

    if (!context.mounted) {
      return;
    }

    await Navigator.of(context).push<void>(
      MaterialPageRoute<void>(
        builder: (_) => ClaimScreen(session: session, publicRef: publicRef),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bien = resultat.asset;
    final statut = bien?.lifeStatus;

    final fond = switch (resultat.outcome) {
      LookupOutcome.rateLimited || LookupOutcome.invalid => const Color(0xFF5C6470),
      // Ambre et non vert : ne pas rassurer sur une absence d'information.
      LookupOutcome.unknown => const Color(0xFFC77700),
      LookupOutcome.known => Djassa.depuisServeur(statut?.color ?? ''),
    };

    final encre = Djassa.surFond(fond);

    // Panneau posé sur le fond : blanc très transparent si le fond est sombre,
    // encre très transparente s'il est clair. Toujours lisible, quelle que soit
    // la couleur qu'introduira un futur statut.
    final panneau = encre == Djassa.creme
        ? Colors.white.withValues(alpha: 0.16)
        : Djassa.encre.withValues(alpha: 0.10);

    final symbole = switch (resultat.outcome) {
      LookupOutcome.rateLimited => '⏳',
      LookupOutcome.invalid => '?',
      LookupOutcome.unknown => '?',
      LookupOutcome.known => resultat.isWarning ? '!' : '✓',
    };

    final mot = switch (resultat.outcome) {
      LookupOutcome.rateLimited => 'PATIENTE',
      LookupOutcome.invalid => 'NUMÉRO ILLISIBLE',
      LookupOutcome.unknown => 'PAS ENREGISTRÉ',
      // Le libellé du serveur, jamais un mot inventé ici : « Volé déclaré »,
      // « En location », « Litige en cours » sont des états métier.
      LookupOutcome.known =>
        resultat.isWarning ? statut!.label.toUpperCase() : 'RIEN À SIGNALER',
    };

    return Scaffold(
      backgroundColor: fond,
      body: SafeArea(
        child: Column(
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: <Widget>[
                  _Retour(couleur: encre, fond: panneau),
                  Text(
                    // La SAISIE, pas le numéro rendu par le serveur : le verdict
                    // public ne le contient pas, et c'est voulu.
                    saisie.toUpperCase(),
                    style: TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: encre,
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.only(bottom: 8),
                child: Column(
                  children: <Widget>[
                    const SizedBox(height: 26),
                    Container(
                      width: 130,
                      height: 130,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: encre, shape: BoxShape.circle),
                      child: Text(
                        symbole,
                        style: Djassa.affiche(72, couleur: fond, hauteur: 1),
                      ),
                    ),
                    const SizedBox(height: 18),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 24),
                      child: Text(
                        mot,
                        textAlign: TextAlign.center,
                        style: Djassa.affiche(44, couleur: encre, hauteur: 1),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 24),
                      child: Text(
                        resultat.message,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontFamily: Djassa.texte,
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          height: 1.4,
                          color: encre,
                        ),
                      ),
                    ),
                    const SizedBox(height: 14),
                    _QueFaire(
                      conseil: _conseil(resultat),
                      fond: encre,
                      couleur: fond,
                    ),
                    if (bien != null) ...<Widget>[
                      const SizedBox(height: 18),
                      _Details(bien: bien, panneau: panneau, couleur: encre),
                    ],
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 16, 18, 20),
              child: Column(
                children: <Widget>[
                  if (bien != null) ...<Widget>[
                    _ActionSombre(
                      libelle: 'Partager cette fiche',
                      onPressed: () async {
                        // LE LIEN NE PORTE QUE LA RÉFÉRENCE OPAQUE : il ne
                        // laisse rien deviner du numéro réel du bien, ni de son
                        // propriétaire.
                        await Clipboard.setData(
                          ClipboardData(text: bien.shareUri('https://preuve.click').toString()),
                        );

                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('Lien copié. Il ne contient pas le numéro du bien.'),
                            ),
                          );
                        }
                      },
                    ),
                    const SizedBox(height: 10),
                    _ActionContour(
                      libelle: 'C\'est mon bien — je réclame',
                      couleur: encre,
                      onPressed: () => _reclamer(context, bien.publicRef),
                    ),
                  ] else
                    _ActionContour(
                      libelle: 'Vérifier un autre bien',
                      couleur: encre,
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  const SizedBox(height: 12),
                  Text(
                    'Personne ne saura que tu as consulté ce bien.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: encre,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Ce qu'il faut FAIRE, pas ce qui est constaté.
  ///
  /// C'est la différence entre informer et servir : quelqu'un debout devant un
  /// vendeur n'a pas besoin d'un état, il a besoin de savoir s'il sort son
  /// argent.
  static String _conseil(LookupResult resultat) {
    return switch (resultat.outcome) {
      LookupOutcome.rateLimited =>
        'Tu as fait beaucoup de vérifications d\'affilée. Attends un moment, puis reprends.',
      LookupOutcome.invalid =>
        'Recompte le numéro, caractère par caractère. Le 1 et le I, le 0 et le O se confondent.',
      LookupOutcome.unknown =>
        'N\'achète pas sur cette base seule. Demande au vendeur de l\'enregistrer devant toi : '
            's\'il refuse, demande-toi pourquoi.',
      LookupOutcome.known => resultat.isWarning
          ? 'N\'achète pas. Ce bien est signalé : l\'acheter t\'expose à le perdre sans recours.'
          : 'Rien ne s\'oppose à l\'achat côté registre. Vérifie quand même la pièce d\'identité '
              'du vendeur et la carte grise.',
    };
  }
}

class _Retour extends StatelessWidget {
  const _Retour({required this.couleur, required this.fond});

  final Color couleur;
  final Color fond;

  @override
  Widget build(BuildContext context) {
    return TextButton(
      style: TextButton.styleFrom(
        backgroundColor: fond,
        foregroundColor: couleur,
        minimumSize: const Size(0, 44),
        padding: const EdgeInsets.symmetric(horizontal: 16),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      ),
      onPressed: () => Navigator.of(context).pop(),
      child: Text(
        '← Retour',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 14,
          fontWeight: FontWeight.w700,
          color: couleur,
        ),
      ),
    );
  }
}

class _QueFaire extends StatelessWidget {
  const _QueFaire({required this.conseil, required this.fond, required this.couleur});

  final String conseil;
  final Color fond;
  final Color couleur;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 18),
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: BoxDecoration(
        color: fond,
        borderRadius: BorderRadius.circular(Djassa.rayonPanneau),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('QUE FAIRE ?', style: Djassa.affiche(15, couleur: couleur)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              conseil,
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                height: 1.35,
                color: couleur,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Details extends StatelessWidget {
  const _Details({required this.bien, required this.panneau, required this.couleur});

  final PublicAsset bien;
  final Color panneau;
  final Color couleur;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 18),
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 18),
      decoration: BoxDecoration(
        color: panneau,
        borderRadius: BorderRadius.circular(Djassa.rayonPanneau),
      ),
      child: Column(
        children: <Widget>[
          _ligne('Le bien', bien.category),
          _ligne('Fiabilité', bien.trustLevel.label),
          if (bien.registeredAt != null) _ligne('Enregistré depuis', _depuis(bien.registeredAt!)),
          _ligne('Référence', bien.publicRef),
          // NI NOM, NI NUMÉRO, NI ANCIENNETÉ EXACTE DU COMPTE : le verdict
          // public expose l'ancienneté par TRANCHES, jamais une date qui,
          // recoupée, aiderait à identifier le déclarant.
        ],
      ),
    );
  }

  Widget _ligne(String intitule, String valeur) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            flex: 5,
            child: Text(
              intitule,
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 16,
                color: couleur,
              ),
            ),
          ),
          Expanded(
            flex: 6,
            child: Text(
              valeur,
              textAlign: TextAlign.right,
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 16,
                fontWeight: FontWeight.w700,
                color: couleur,
              ),
            ),
          ),
        ],
      ),
    );
  }

  static String _depuis(DateTime moment) {
    final jours = DateTime.now().difference(moment.toLocal()).inDays;

    if (jours < 7) {
      return 'Cette semaine';
    }

    if (jours < 31) {
      return 'Ce mois-ci';
    }

    if (jours < 365) {
      return '${(jours / 30).round()} mois';
    }

    final annees = (jours / 365).floor();

    return '$annees an${annees > 1 ? 's' : ''}';
  }
}

class _ActionSombre extends StatelessWidget {
  const _ActionSombre({required this.libelle, required this.onPressed});

  final String libelle;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return FilledButton(
      style: FilledButton.styleFrom(
        backgroundColor: Djassa.encre,
        foregroundColor: Djassa.creme,
        minimumSize: const Size.fromHeight(60),
        textStyle: const TextStyle(
          fontFamily: Djassa.titre,
          fontSize: 18,
          fontWeight: FontWeight.w800,
        ),
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(Djassa.rayon)),
        ),
      ),
      onPressed: onPressed,
      child: Text(libelle),
    );
  }
}

class _ActionContour extends StatelessWidget {
  const _ActionContour({
    required this.libelle,
    required this.couleur,
    required this.onPressed,
  });

  final String libelle;
  final Color couleur;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      style: OutlinedButton.styleFrom(
        backgroundColor: Colors.transparent,
        foregroundColor: couleur,
        minimumSize: const Size.fromHeight(Djassa.cibleSecondaire),
        side: BorderSide(color: couleur, width: Djassa.trait),
        textStyle: const TextStyle(
          fontFamily: Djassa.titre,
          fontSize: 16,
          fontWeight: FontWeight.w800,
        ),
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(Djassa.rayon)),
        ),
      ),
      onPressed: onPressed,
      child: Text(libelle, textAlign: TextAlign.center),
    );
  }
}
