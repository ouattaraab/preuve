import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Tableau de bord d'un loueur (EP-07) — la verticale de lancement.
///
/// IL OUVRE SUR CE QUI NE VA PAS, jamais sur un total. Un loueur qui gère
/// quarante motos n'a pas besoin qu'on lui rappelle qu'il en a quarante : il a
/// besoin de savoir laquelle est déclarée volée, et laquelle est en litige.
/// Trier par volume mettrait « Actif » en tête et enterrerait l'alerte.
///
/// LE MARQUAGE « EN LOCATION » SE FAIT EN MASSE, parce que c'est ainsi qu'un
/// parc travaille : un formulaire par véhicule ne serait jamais rempli. Et ce
/// marquage n'est pas cosmétique — un acheteur qui vérifie le numéro d'un
/// véhicule loué doit le lire AVANT de donner de l'argent à quelqu'un qui n'en
/// est pas propriétaire.
class FleetScreen extends StatefulWidget {
  const FleetScreen({required this.session, required this.societe, super.key});

  final PreuveSession session;
  final CompanyMembership societe;

  @override
  State<FleetScreen> createState() => _FleetScreenState();
}

class _FleetScreenState extends State<FleetScreen> {
  final TextEditingController _identifiants = TextEditingController();

  FleetDashboard? _tableau;
  bool _enCours = true;
  String? _erreur;
  String? _confirmation;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    _identifiants.dispose();
    super.dispose();
  }

  Future<void> _charger() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final tableau = await widget.session.fleet.dashboard(widget.societe.id);

      if (mounted) {
        setState(() => _tableau = tableau);
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

  Future<void> _marquer({required bool enLocation}) async {
    final saisie = _identifiants.text
        .split(RegExp(r'[\s,;]+'))
        .map((String s) => s.trim().toUpperCase())
        .where((String s) => s.isNotEmpty)
        .toList(growable: false);

    if (saisie.isEmpty) {
      setState(() => _erreur = 'Colle les numéros des véhicules, un par ligne.');

      return;
    }

    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      final resultat = await widget.session.fleet.markRented(
        widget.societe.id,
        identifiers: saisie,
        rented: enLocation,
      );

      if (mounted) {
        // LE COMPTE VIENT DU SERVEUR, jamais du nombre de lignes collées : un
        // véhicule volé refuse la bascule, et annoncer « 12 marqués » quand le
        // serveur en a marqué 9 ferait croire un parc à jour qui ne l'est pas.
        final marques = resultat['marked'] ?? resultat['count'];

        setState(() {
          _identifiants.clear();
          _confirmation = enLocation
              ? '${marques ?? saisie.length} véhicule(s) marqué(s) « En location ».'
              : '${marques ?? saisie.length} véhicule(s) rendu(s) disponible(s).';
        });
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      await _charger();
    }
  }

  @override
  Widget build(BuildContext context) {
    final tableau = _tableau;

    return Scaffold(
      appBar: BarrePreuve(titre: widget.societe.name),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _charger,
          color: Djassa.encre,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
            children: <Widget>[
              Text(widget.societe.name, style: Djassa.affiche(28)),
              Text(
                widget.societe.roleLabel,
                style: const TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: Djassa.etiquette,
                ),
              ),
              const SizedBox(height: 16),
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 14),
              ],
              if (_confirmation != null) ...<Widget>[
                EncadreConfirmation(_confirmation!),
                const SizedBox(height: 14),
              ],
              if (_enCours && tableau == null)
                const EnCours()
              else if (tableau != null) ...<Widget>[
                // LES ALERTES D'ABORD, ET SÉPARÉES DU RESTE.
                if (tableau.alerts.isNotEmpty) ...<Widget>[
                  Text('À traiter', style: Djassa.affiche(20)),
                  const SizedBox(height: 10),
                  ...tableau.alerts.map((FleetStatusCount s) => _Ligne(statut: s, alerte: true)),
                  // ET LESQUELS. Un décompte « Volé déclaré : 1 » envoie le
                  // loueur chercher dans son parc le véhicule concerné ; le
                  // serveur les nomme déjà.
                  if (tableau.needsAttention.isNotEmpty) ...<Widget>[
                    const SizedBox(height: 4),
                    ...tableau.needsAttention.map((FleetAlert a) => _Concerne(alerte: a)),
                  ],
                  const SizedBox(height: 20),
                ],
                Text('Le parc', style: Djassa.affiche(20)),
                const SizedBox(height: 10),
                ...tableau.statuses
                    .where((FleetStatusCount s) => !s.warning)
                    .map((FleetStatusCount s) => _Ligne(statut: s, alerte: false)),
                const SizedBox(height: 10),
                _Total(total: tableau.total, consultations: tableau.lookups30d),
                if (tableau.billing != null) ...<Widget>[
                  const SizedBox(height: 14),
                  _Facturation(billing: tableau.billing!),
                ],
              ],
              const SizedBox(height: 26),
              const Divider(color: Djassa.encre, thickness: 3),
              const SizedBox(height: 16),
              Text('Marquer « En location »', style: Djassa.affiche(20)),
              const SizedBox(height: 8),
              const Text(
                'Colle les numéros des véhicules, un par ligne. Un véhicule marqué en '
                'location est signalé comme non vendable à quiconque vérifie son numéro.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 15,
                  height: 1.5,
                  color: Djassa.sourdine,
                ),
              ),
              const SizedBox(height: 14),
              Container(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(Djassa.rayon),
                  boxShadow: Djassa.relief(),
                ),
                child: TextField(
                  controller: _identifiants,
                  maxLines: 5,
                  textCapitalization: TextCapitalization.characters,
                  autocorrect: false,
                  style: const TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: Djassa.encre,
                  ),
                  decoration: const InputDecoration(
                    hintText: '1M8GDM9AXKP042788\nJH4KA7561PC008269',
                  ),
                ),
              ),
              const SizedBox(height: 14),
              BoutonRelief(
                libelle: 'Marquer en location',
                enCours: _enCours,
                onPressed: () => _marquer(enLocation: true),
              ),
              const SizedBox(height: 10),
              BoutonRelief(
                libelle: 'Rendre disponible',
                principal: false,
                enCours: _enCours,
                onPressed: () => _marquer(enLocation: false),
              ),
              const SizedBox(height: 20),
              const Text(
                'L\'import d\'un parc entier se fait depuis un fichier, sur le web : '
                'au-delà de deux cents lignes il se déroule en arrière-plan, et un '
                'téléphone n\'est pas l\'endroit pour le suivre.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  color: Djassa.etiquette,
                  height: 1.5,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Un véhicule nommément concerné par une alerte.
///
/// PAR SA RÉFÉRENCE PUBLIQUE, jamais par son immatriculation : ce tableau
/// s'ouvre au comptoir d'une agence, et un écran s'y lit par-dessus l'épaule.
/// La référence suffit à retrouver le véhicule dans le parc, elle ne désigne
/// personne dehors.
class _Concerne extends StatelessWidget {
  const _Concerne({required this.alerte});

  final FleetAlert alerte;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8, left: 14),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Djassa.creme,
        border: Border.all(color: Djassa.sable, width: 2),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              alerte.publicRef,
              style: const TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: Djassa.encre,
              ),
            ),
          ),
          const SizedBox(width: 10),
          // LE LIBELLÉ DU SERVEUR, jamais le code (CT-04).
          Text(
            alerte.statusLabel,
            style: const TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: Djassa.alerte,
            ),
          ),
        ],
      ),
    );
  }
}

class _Ligne extends StatelessWidget {
  const _Ligne({required this.statut, required this.alerte});

  final FleetStatusCount statut;
  final bool alerte;

  @override
  Widget build(BuildContext context) {
    final couleur = alerte ? Djassa.alerte : Djassa.encre;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: couleur, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
        boxShadow: alerte ? Djassa.relief(couleur) : null,
      ),
      child: Row(
        children: <Widget>[
          Text('${statut.count}', style: Djassa.affiche(26, couleur: couleur)),
          const SizedBox(width: 14),
          Expanded(
            child: Text(
              // Le LIBELLÉ du serveur, jamais le code (CT-04).
              statut.label,
              style: const TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 16,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({required this.total, required this.consultations});

  final int total;
  final int consultations;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: BoxDecoration(
        color: Djassa.encre,
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text('$total', style: Djassa.affiche(26, couleur: Djassa.ambre)),
                const Text(
                  'véhicules protégés',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: Djassa.creme,
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text('$consultations', style: Djassa.affiche(26, couleur: Djassa.ambre)),
                const Text(
                  'consultations / 30 j',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: Djassa.creme,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Facturation extends StatelessWidget {
  const _Facturation({required this.billing});

  final Map<String, Object?> billing;

  @override
  Widget build(BuildContext context) {
    // LE MONTANT VIENT DU SERVEUR. Les paliers sont des réglages
    // d'exploitation : un calcul local annoncerait un dû que la plateforme
    // n'applique plus, sur des téléphones qui ne se mettent pas à jour.
    final du = billing['monthly_fcfa'];
    final facturables = billing['billable'];

    if (du is! int) {
      return const SizedBox.shrink();
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            du == 0 ? 'Rien à payer ce mois-ci' : '$du FCFA ce mois-ci',
            style: Djassa.affiche(20),
          ),
          const SizedBox(height: 4),
          Text(
            facturables is int
                ? '$facturables véhicule(s) facturable(s) — les premiers restent gratuits.'
                : 'Les premiers véhicules restent gratuits.',
            style: const TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 14,
              color: Djassa.sourdine,
              height: 1.4,
            ),
          ),
        ],
      ),
    );
  }
}
