import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Rapport détaillé d'un bien qu'on ne détient pas (ST-0801).
///
/// GRATUIT OU PAYANT, C'EST LE SERVEUR QUI TRANCHE. Le tarif est un réglage
/// d'exploitation : un montant écrit dans l'application réclamerait de l'argent
/// le jour où l'administrateur vient de rendre le rapport gratuit, sur des
/// téléphones qui ne se mettent pas à jour.
///
/// LE PAIEMENT SE FAIT DANS LE NAVIGATEUR DU TÉLÉPHONE, jamais dans une vue web
/// embarquée. Une page de carte bancaire affichée à l'intérieur de
/// l'application prive l'utilisateur de la barre d'adresse — le seul endroit où
/// il peut vérifier qu'il est chez l'opérateur et non sur une imitation.
///
/// LE RETOUR NE PROUVE RIEN. C'est le webhook signé de l'opérateur qui accorde
/// l'accès ; l'écran ne fait que RELIRE. Sans cette règle, revenir dans
/// l'application suffirait à obtenir un rapport sans payer.
///
/// LE RAPPORT NE NOMME PERSONNE : nombre de détenteurs et dates de transfert,
/// jamais les identités (règle métier absolue n° 4). L'écran le dit, plutôt que
/// de laisser chercher un nom qui n'existera pas.
class ReportScreen extends StatefulWidget {
  const ReportScreen({
    required this.session,
    required this.reference,
    super.key,
  });

  final PreuveSession session;

  /// La référence PUBLIQUE, seule forme qu'un acheteur possède.
  final String reference;

  @override
  State<ReportScreen> createState() => _ReportScreenState();
}

class _ReportScreenState extends State<ReportScreen> {
  Map<String, Object?>? _rapport;
  ReportOrder? _commande;
  bool _enCours = false;
  String? _erreur;

  Future<void> _demander() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final commande = await widget.session.reports.order(widget.reference);

      if (!mounted) {
        return;
      }

      setState(() => _commande = commande);

      if (commande.free && commande.accessToken != null) {
        await _lire(commande.accessToken!);

        return;
      }

      final url = commande.checkoutUrl;

      if (url != null) {
        // `externalApplication` : le navigateur du système, avec sa barre
        // d'adresse. C'est elle qui permet de vérifier chez qui l'on paie.
        final ouverte = await launchUrl(
          Uri.parse(url),
          mode: LaunchMode.externalApplication,
        );

        if (!ouverte && mounted) {
          setState(() => _erreur =
              'Impossible d\'ouvrir la page de paiement. Copie ce lien dans ton navigateur :\n$url');
        }
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

  Future<void> _lire(String jeton) async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final rapport = await widget.session.reports.read(jeton);

      if (mounted) {
        setState(() => _rapport = rapport);
      }
    } on PreuveException catch (e) {
      if (mounted) {
        // Un paiement non encore confirmé rend 404 : ce n'est pas une panne,
        // c'est un délai. Le dire évite de faire recommencer un paiement déjà
        // effectué — la faute la plus coûteuse possible à cet endroit.
        setState(() => _erreur = e is NotFound
            ? 'Le paiement n\'est pas encore confirmé par l\'opérateur. Attends un moment, '
                'puis touche « J\'ai payé ». Ne recommence pas le paiement.'
            : messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final rapport = _rapport;
    final commande = _commande;

    return Scaffold(
      appBar: const BarrePreuve(titre: 'Rapport détaillé'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          children: <Widget>[
            Text(widget.reference, style: Djassa.affiche(24)),
            const SizedBox(height: 14),
            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 16),
            ],
            if (rapport != null)
              _Rapport(donnees: rapport)
            else if (_enCours)
              const EnCours()
            else if (commande == null)
              ..._avantCommande()
            else if (commande.needsPayment)
              ..._apresRedirection(commande)
            else
              const EnCours(),
          ],
        ),
      ),
    );
  }

  List<Widget> _avantCommande() {
    return <Widget>[
      const Text(
        'Le rapport détaillé montre l\'historique complet du bien : ses changements '
        'de statut, le NOMBRE de détenteurs successifs et les dates de transfert.',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 16,
          height: 1.5,
          color: Djassa.sourdine,
        ),
      ),
      const SizedBox(height: 12),
      const Text(
        'Il ne nomme personne, et cela ne changera pas : ni le détenteur actuel, ni '
        'les précédents. C\'est la même protection qui fait que personne ne saura que '
        'tu as consulté ce bien.',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 15,
          height: 1.5,
          color: Djassa.sourdine,
        ),
      ),
      const SizedBox(height: 20),
      // LE MONTANT N'EST PAS ÉCRIT ICI : il vient du serveur avec la commande.
      // L'annoncer avant de le connaître ferait promettre un prix que
      // l'administrateur a pu changer ce matin.
      BoutonRelief(
        libelle: 'Voir le rapport',
        onPressed: _demander,
      ),
      const SizedBox(height: 12),
      const Text(
        'S\'il est payant, le montant s\'affichera avant tout paiement.',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 14,
          color: Djassa.etiquette,
        ),
      ),
    ];
  }

  List<Widget> _apresRedirection(ReportOrder commande) {
    return <Widget>[
      EncadreConfirmation(
        commande.amountFcfa == null
            ? 'La page de paiement est ouverte dans ton navigateur.'
            : 'Montant à régler : ${commande.amountFcfa} FCFA. La page de paiement est '
                'ouverte dans ton navigateur.',
      ),
      const SizedBox(height: 16),
      const Text(
        'Règle le montant, puis reviens ici. Le rapport s\'ouvrira dès que l\'opérateur '
        'aura confirmé — cela peut prendre quelques secondes.',
        style: TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 16,
          height: 1.5,
          color: Djassa.sourdine,
        ),
      ),
      const SizedBox(height: 18),
      BoutonRelief(
        libelle: 'J\'ai payé',
        onPressed: () {
          final jeton = commande.accessToken;

          // Le jeton n'existe qu'après confirmation : tant qu'il manque, on
          // relance la commande, qui retrouvera le paiement en cours plutôt que
          // d'en ouvrir un second.
          if (jeton != null) {
            _lire(jeton);
          } else {
            _demander();
          }
        },
      ),
      const SizedBox(height: 10),
      BoutonRelief(
        libelle: 'Rouvrir la page de paiement',
        principal: false,
        onPressed: () => launchUrl(
          Uri.parse(commande.checkoutUrl!),
          mode: LaunchMode.externalApplication,
        ),
      ),
    ];
  }
}

class _Rapport extends StatelessWidget {
  const _Rapport({required this.donnees});

  final Map<String, Object?> donnees;

  @override
  Widget build(BuildContext context) {
    final historique = donnees['history'];
    final lignes = historique is List ? historique.whereType<Map<String, Object?>>() : const <Map<String, Object?>>[];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text('Historique du bien', style: Djassa.affiche(20)),
        const SizedBox(height: 12),
        if (lignes.isEmpty)
          const Text(
            'Aucun changement de statut depuis l\'enregistrement.',
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontWeight: FontWeight.w700,
              color: Djassa.sourdine,
            ),
          )
        else
          ...lignes.map(
            (Map<String, Object?> l) => Container(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
              decoration: BoxDecoration(
                color: Colors.white,
                border: Border.all(color: Djassa.encre, width: 2),
                borderRadius: BorderRadius.circular(Djassa.rayon),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    '${l['to_status_label'] ?? l['to_status'] ?? ''}',
                    style: const TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  if (l['at'] != null)
                    Text(
                      '${l['at']}',
                      style: const TextStyle(
                        fontFamily: Djassa.texte,
                        fontSize: 13,
                        color: Djassa.etiquette,
                      ),
                    ),
                ],
              ),
            ),
          ),
        const SizedBox(height: 18),
        const Text(
          '🔒 Ce rapport ne nomme personne, et n\'a jamais nommé personne : ni le '
          'détenteur actuel, ni les précédents. Seul leur NOMBRE et les dates '
          'apparaissent.',
          style: TextStyle(
            fontFamily: Djassa.texte,
            fontSize: 13,
            fontWeight: FontWeight.w700,
            height: 1.5,
            color: Djassa.etiquette,
          ),
        ),
      ],
    );
  }
}
