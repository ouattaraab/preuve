import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// La liste publique des biens volés (ST-0805).
///
/// SANS COMPTE, comme la consultation, et pour la même raison : elle ne sert
/// que si on la parcourt. Un garagiste à qui l'on apporte une moto n'ouvrira
/// pas de compte pour vérifier une intuition.
///
/// ELLE NE MONTRE QUE CE QUI A ÉTÉ PUBLIÉ. Déclarer un vol rend le bien
/// invendable pour qui vérifie son numéro ; y figurer est un geste distinct que
/// le détenteur pose lui-même. L'écran doit le DIRE, sans quoi une liste courte
/// se lirait comme « il y a peu de vols » — le contresens le plus coûteux.
class StolenScreen extends StatefulWidget {
  const StolenScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<StolenScreen> createState() => _StolenScreenState();
}

class _StolenScreenState extends State<StolenScreen> {
  final TextEditingController _recherche = TextEditingController();

  StolenPage? _page;
  bool _enCours = true;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    _recherche.dispose();
    super.dispose();
  }

  Future<void> _charger({int page = 1}) async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final StolenPage resultat = await widget.session.voles.browse(
        query: _recherche.text,
        page: page,
      );

      if (mounted) {
        setState(() => _page = resultat);
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
    final StolenPage? page = _page;

    return Scaffold(
      appBar: const BarrePreuve(titre: 'Biens volés'),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _charger,
          color: Djassa.encre,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
            children: <Widget>[
              Text.rich(
                TextSpan(
                  children: <TextSpan>[
                    const TextSpan(text: 'Biens déclarés\n'),
                    TextSpan(
                      text: 'volés',
                      style: Djassa.affiche(34, couleur: Djassa.alerte, hauteur: 1.05),
                    ),
                  ],
                ),
                style: Djassa.affiche(34, hauteur: 1.05),
              ),
              const SizedBox(height: 10),
              const Text(
                'Si l\'on t\'en propose un, n\'achète pas. Si tu en reconnais un, '
                'préviens la police.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 16,
                  height: 1.45,
                  color: Djassa.sourdine,
                ),
              ),
              const SizedBox(height: 18),
              ChampRelief(
                controller: _recherche,
                // UN FRAGMENT SUFFIT : quelqu'un qui croit reconnaître une moto
                // n'a souvent qu'un bout de plaque.
                indication: 'Un numéro, ou juste un bout',
                majuscules: true,
                onSoumis: (_) => _charger(),
              ),
              const SizedBox(height: 12),
              BoutonRelief(
                libelle: 'CHERCHER',
                enCours: _enCours,
                onPressed: _charger,
              ),
              const SizedBox(height: 18),
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 14),
              ],
              if (_enCours && page == null)
                const EnCours()
              else if (page == null || page.items.isEmpty)
                const RienEncore(
                  titre: 'Aucun bien ici',
                  // ON NE RASSURE PAS SUR UNE ABSENCE. Un bien absent de cette
                  // liste n'est pas un bien sain : son détenteur n'a peut-être
                  // pas demandé la publication.
                  explication: 'Cette liste ne montre que les biens dont le détenteur a demandé '
                      'la publication. Un bien absent d\'ici peut très bien être volé — '
                      'vérifie son numéro, c\'est gratuit.',
                )
              else ...<Widget>[
                Text(
                  '${page.total} bien(s) signalé(s)',
                  style: Djassa.affiche(20),
                ),
                const SizedBox(height: 12),
                ...page.items.map((StolenAsset bien) => _Carte(bien: bien)),
                if (page.hasMore) ...<Widget>[
                  const SizedBox(height: 8),
                  BoutonRelief(
                    libelle: 'Page suivante',
                    principal: false,
                    enCours: _enCours,
                    onPressed: () => _charger(page: page.page + 1),
                  ),
                ],
              ],
              const SizedBox(height: 22),
              const Text(
                'Le détenteur d\'un bien n\'est jamais nommé ici, ni ailleurs. Cette liste '
                'sert à retrouver des biens, pas à désigner des victimes.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  height: 1.5,
                  color: Djassa.etiquette,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Carte extends StatelessWidget {
  const _Carte({required this.bien});

  final StolenAsset bien;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
        boxShadow: Djassa.relief(),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                // L'IDENTIFIANT EN GRAND : c'est la seule chose qui permet de
                // reconnaître le bien qu'on a sous les yeux.
                child: Text(bien.identifier, style: Djassa.affiche(22)),
              ),
              const SizedBox(width: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                decoration: BoxDecoration(
                  color: Djassa.alerte,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: const Text(
                  'VOLÉ',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: Djassa.creme,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            bien.description,
            style: const TextStyle(fontFamily: Djassa.texte, fontSize: 16, color: Djassa.encre),
          ),
          const SizedBox(height: 6),
          Text(
            bien.daysSince == null
                ? 'Déclaré volé'
                : 'Déclaré il y a ${bien.daysSince} jour(s)',
            style: const TextStyle(fontFamily: Djassa.texte, fontSize: 14, color: Djassa.etiquette),
          ),
          if (bien.consolidated) ...<Widget>[
            const SizedBox(height: 4),
            const Text(
              'Plainte déposée et constatée',
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: Color(0xFF1F7A4C),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
