import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import '../ui/theme.dart';

/// Le verdict, plein écran.
///
/// UN MOT QUI TRANCHE, lisible à deux mètres : c'est la signature de la
/// direction DJASSA, et c'est la seule chose qu'on lit debout dans un marché.
/// Le détail vient après, pour qui veut le lire.
///
/// LES TROIS ISSUES NE SE CONFONDENT JAMAIS. Un identifiant inconnu n'est ni un
/// bon ni un mauvais signe : l'afficher en vert ferait acheter un bien volé que
/// personne n'a déclaré. Il a donc sa propre couleur et son propre mot.
class VerdictScreen extends StatelessWidget {
  const VerdictScreen({required this.resultat, required this.saisie, super.key});

  final LookupResult resultat;
  final String saisie;

  @override
  Widget build(BuildContext context) {
    final statut = resultat.asset?.lifeStatus;

    final enseigne = switch (resultat.outcome) {
      LookupOutcome.rateLimited => 'PATIENTE',
      LookupOutcome.invalid => 'NUMÉRO ILLISIBLE',
      LookupOutcome.unknown => 'PAS ENREGISTRÉ',
      LookupOutcome.known => resultat.isWarning ? 'ATTENTION' : 'RIEN À SIGNALER',
    };

    final couleur = switch (resultat.outcome) {
      LookupOutcome.rateLimited || LookupOutcome.invalid => const Color(0xFF5C6470),
      // Orange et non vert : ne pas rassurer sur une absence d'information.
      LookupOutcome.unknown => const Color(0xFFC77700),
      LookupOutcome.known => Djassa.depuisServeur(statut?.color ?? ''),
    };

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Résultat', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Container(
                padding: const EdgeInsets.symmetric(vertical: 34, horizontal: 20),
                decoration: BoxDecoration(
                  color: couleur,
                  border: Border.all(color: Djassa.encre, width: 3),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Column(
                  children: <Widget>[
                    Text(
                      enseigne,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Color(0xFFFFF6E8),
                        fontSize: 40,
                        height: 1.05,
                        fontWeight: FontWeight.w800,
                        letterSpacing: -0.5,
                      ),
                    ),
                    if (statut != null) ...<Widget>[
                      const SizedBox(height: 8),
                      // CT-04 : le LIBELLÉ, jamais le code. « Volé déclaré »,
                      // pas « V-VOL ».
                      Text(
                        statut.label,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Color(0xFFFFF6E8),
                          fontSize: 20,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 18),
              Text(
                resultat.message,
                style: const TextStyle(fontSize: 19, height: 1.45),
              ),
              if (resultat.isKnown) ..._detail(context, resultat.asset!),
              const SizedBox(height: 26),
              const Text(
                'Nous ne disons pas qui a enregistré ce bien, ni son numéro complet. '
                'Le propriétaire ne saura pas non plus que tu l\'as consulté.',
                style: TextStyle(color: Djassa.sourdine, height: 1.5),
              ),
              const SizedBox(height: 26),
              FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Vérifier un autre bien'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _detail(BuildContext context, PublicAsset bien) {
    final lien = bien.shareUri('https://preuve.click').toString();

    return <Widget>[
      const SizedBox(height: 22),
      Container(
        decoration: BoxDecoration(
          color: const Color(0xFFFFF6E8),
          border: Border.all(color: Djassa.encre, width: 3),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          children: <Widget>[
            _ligne('Référence publique', bien.publicRef),
            _ligne('Type de bien', bien.category),
            _ligne('Niveau de vérification', bien.trustLevel.label),
            if (bien.registeredAt != null)
              _ligne('Enregistré le', _date(bien.registeredAt!), dernier: true),
          ],
        ),
      ),
      const SizedBox(height: 18),
      OutlinedButton.icon(
        // Le lien partagé ne porte QUE la référence opaque : il ne laisse rien
        // deviner du numéro réel du bien, ni de son propriétaire.
        onPressed: () async {
          await Clipboard.setData(ClipboardData(text: lien));

          if (context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Lien copié. Il ne contient pas le numéro du bien.')),
            );
          }
        },
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(Djassa.cible),
          side: const BorderSide(color: Djassa.encre, width: 3),
          foregroundColor: Djassa.encre,
        ),
        icon: const Icon(Icons.link),
        label: const Text('Copier le lien de cette fiche'),
      ),
    ];
  }

  static Widget _ligne(String intitule, String valeur, {bool dernier = false}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        border: dernier
            ? null
            : const Border(bottom: BorderSide(color: Color(0xFFE4DBC8), width: 2)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            flex: 5,
            child: Text(intitule, style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          Expanded(flex: 6, child: Text(valeur)),
        ],
      ),
    );
  }

  static String _date(DateTime moment) {
    const mois = <String>[
      'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
      'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    final local = moment.toLocal();

    return '${local.day} ${mois[local.month - 1]} ${local.year}';
  }
}
