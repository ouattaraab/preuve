import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';
import 'asset_screen.dart';
import 'fleet_screen.dart';
import 'kyc_screen.dart';
import 'notifications_screen.dart';
import 'register_screen.dart';
import 'transfers_screen.dart';
import 'uploads_screen.dart';
import 'watch_screen.dart';

/// Mes biens — le point d'entrée de tout ce qui n'est pas la consultation.
///
/// CET ÉCRAN EXISTE PARCE QUE LES ACTIONS PASSENT PAR LE BIEN. Déclarer un vol,
/// céder, réclamer : chacune vise un bien précis, et personne ne retient un
/// numéro de châssis. La liste est donc moins un tableau de bord qu'un
/// trousseau de clés.
///
/// « JE DÉCLARE LE VOL » EST SUR LA CARTE, pas derrière un écran de détail.
/// C'est le parcours le plus urgent du produit : quelqu'un vient de se faire
/// prendre sa moto, et chaque écran de plus est une minute pendant laquelle le
/// bien peut être revendu.
///
/// LES TRANSFERTS EN ATTENTE SONT REMONTÉS ICI, en tête. Un transfert expire au
/// bout de sept jours, et un acheteur qui ne sait pas qu'on lui a cédé un bien
/// ne le confirmera jamais.
class MyAssetsScreen extends StatefulWidget {
  const MyAssetsScreen({required this.session, this.onDeconnexion, super.key});

  final PreuveSession session;

  /// Ce qu'il faut faire une fois la session fermée.
  ///
  /// CET ÉCRAN VIT DE DEUX FAÇONS, et c'est ce qui a produit un écran NOIR à la
  /// déconnexion : il est empilé depuis l'accueil, mais il est aussi le
  /// troisième ONGLET du shell, où il n'est empilé sur rien. Un
  /// `Navigator.pop()` y retirait la dernière route de la pile et ne laissait
  /// plus rien à afficher.
  ///
  /// Le shell passe donc de quoi revenir à son premier onglet ; l'usage empilé
  /// laisse ce paramètre nul et l'écran se dépile, comme avant.
  final VoidCallback? onDeconnexion;

  @override
  State<MyAssetsScreen> createState() => _MyAssetsScreenState();
}

class _MyAssetsScreenState extends State<MyAssetsScreen> {
  Inventory? _inventaire;
  List<PendingTransfer> _attentes = const <PendingTransfer>[];
  int _alertes = 0;
  bool _enCours = true;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final inventaire = await widget.session.assets.mine();

      // LES APPELS ANNEXES NE SONT PAS BLOQUANTS : un échec sur les transferts
      // ou les alertes ne doit pas priver quelqu'un de la liste de ses biens,
      // sur laquelle se trouve la déclaration de vol.
      List<PendingTransfer> attentes;

      try {
        attentes = await widget.session.transfers.mine();
      } on PreuveException {
        attentes = const <PendingTransfer>[];
      }

      var alertes = 0;

      try {
        alertes = (await widget.session.notifications.feed()).unreadCount;
      } on PreuveException {
        alertes = 0;
      }

      if (mounted) {
        setState(() {
          _inventaire = inventaire;
          _attentes = attentes;
          _alertes = alertes;
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

  Future<void> _ouvrir(Widget ecran) async {
    await Navigator.of(context).push<void>(MaterialPageRoute<void>(builder: (_) => ecran));

    await _charger();
  }

  Future<void> _declarerVol(OwnedAsset bien) async {
    final code = await demanderCodeAction(
      context,
      session: widget.session,
      motif: OtpPurpose.sensitiveAction,
      titre: 'Déclarer ${bien.label} volé',
      consequence:
          'Le bien devient invendable immédiatement : toute personne qui vérifie le '
          'numéro verra « Volé déclaré ». Tu pourras lever l\'alerte toi-même si tu '
          'le retrouves.',
    );

    if (code == null || code.isEmpty || !mounted) {
      return;
    }

    setState(() => _enCours = true);

    try {
      await widget.session.lifecycle.declareStolen(bien.id, code);
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
    final inventaire = _inventaire;
    final biens = inventaire?.assets ?? const <OwnedAsset>[];
    final consultations = biens.fold<int>(0, (int t, OwnedAsset b) => t + b.lookups30d);

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _charger,
          color: Djassa.encre,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 24, 22, 40),
            children: <Widget>[
              _Salutation(
                nom: widget.session.compte?.fullName ?? 'Bienvenue',
                alerte: _alertes > 0,
                onCloche: () => _ouvrir(NotificationsScreen(session: widget.session)),
                onDeconnexion: () async {
                  final NavigatorState navigateur = Navigator.of(context);

                  await widget.session.fermer();

                  if (!mounted) {
                    return;
                  }

                  // L'HÔTE DÉCIDE, parce que lui seul sait où l'on est. Et
                  // `canPop` garde le cas empilé : dépiler la dernière route
                  // laisse un écran noir, ce qui est exactement ce qui arrivait.
                  final VoidCallback? retour = widget.onDeconnexion;

                  if (retour != null) {
                    retour();
                  } else if (navigateur.canPop()) {
                    navigateur.pop();
                  }
                },
              ),
              const SizedBox(height: 14),
              Row(
                children: <Widget>[
                  Expanded(
                    child: _Tuile(
                      nombre: '${biens.length}',
                      libelle: 'biens protégés',
                      sombre: true,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _Tuile(
                      nombre: '$consultations',
                      libelle: 'consultations / 30 j',
                      sombre: false,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 14),
              ],
              BoutonRelief(
                libelle: '＋ Enregistrer un bien',
                onPressed: () async {
                  final cree = await Navigator.of(context).push<bool>(
                    MaterialPageRoute<bool>(
                      builder: (_) => RegisterScreen(session: widget.session),
                    ),
                  );

                  if (cree == true) {
                    await _charger();
                  }
                },
              ),
              const SizedBox(height: 14),
              // LA FLOTTE N'APPARAÎT QUE POUR QUI EN A UNE. L'afficher à tout le
              // monde ferait chercher une fonction qui n'existe pas pour lui ;
              // la cacher à un loueur lui ferait croire que la plateforme ne
              // sait pas gérer un parc.
              ...(widget.session.compte?.companies ?? const <CompanyMembership>[]).map(
                (CompanyMembership societe) => Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: _CarteFlotte(
                    societe: societe,
                    onOuvrir: () => _ouvrir(
                      FleetScreen(session: widget.session, societe: societe),
                    ),
                  ),
                ),
              ),
              if (_attentes.isNotEmpty) ...<Widget>[
                _BandeauTransferts(
                  attentes: _attentes,
                  onOuvrir: () => _ouvrir(TransfersScreen(session: widget.session)),
                ),
                const SizedBox(height: 14),
              ],
              if (_enCours && inventaire == null)
                const EnCours()
              else if (biens.isEmpty)
                const RienEncore(
                  titre: 'Aucun bien enregistré',
                  explication:
                      'Enregistrer un bien, c\'est ce qui permet de le déclarer volé plus '
                      'tard, et à un acheteur de vérifier qu\'il est bien à toi. Cela prend '
                      'moins de deux minutes.',
                )
              else
                ...biens.map(
                  (OwnedAsset bien) => Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: _CarteBien(
                      bien: bien,
                      onOuvrir: () => _ouvrir(
                        AssetScreen(session: widget.session, bien: bien),
                      ),
                      onDeclarerVol: () => _declarerVol(bien),
                    ),
                  ),
                ),
              const SizedBox(height: 4),
              _QuiRegarde(biens: biens),
              const SizedBox(height: 16),
              if (inventaire?.quota != null) ...<Widget>[
                _Quota(quota: inventaire!.quota!),
                const SizedBox(height: 16),
              ],
              const Divider(color: Djassa.encre, thickness: 2),
              TextButton(
                onPressed: () => _ouvrir(UploadsScreen(session: widget.session)),
                child: Text(
                  // Le nombre en attente EST le message : « Envois » seul ne dit
                  // pas s'il reste quelque chose à faire partir.
                  widget.session.envois.pending.isEmpty
                      ? 'Envois en attente'
                      : 'Envois en attente (${widget.session.envois.pending.length})',
                ),
              ),
              TextButton(
                onPressed: () => _ouvrir(KycScreen(session: widget.session)),
                child: const Text('Vérifier mon identité'),
              ),
              // LA VEILLE N'AVAIT AUCUNE PORTE. Le service, la détection de
              // pics et les notifications existaient depuis EP-04 ; personne
              // ne pouvait poser une veille depuis l'application.
              TextButton(
                onPressed: () => _ouvrir(WatchScreen(session: widget.session)),
                child: const Text('Être prévenu si un bien est consulté'),
              ),
              const SizedBox(height: 8),
              const Text(
                'Un vol déclaré, c\'est un bien que plus personne n\'achète.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
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

class _Salutation extends StatelessWidget {
  const _Salutation({
    required this.nom,
    required this.alerte,
    required this.onCloche,
    required this.onDeconnexion,
  });

  final String nom;
  final bool alerte;
  final VoidCallback onCloche;
  final VoidCallback onDeconnexion;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              const Text(
                'Bonjour 👋',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: Djassa.etiquette,
                ),
              ),
              const SizedBox(height: 2),
              Text(nom, style: Djassa.affiche(28), maxLines: 1, overflow: TextOverflow.ellipsis),
            ],
          ),
        ),
        EnteteMarque(onCloche: onCloche, alerte: alerte, motMarque: false),
        const SizedBox(width: 8),
        OutlinedButton(
          style: OutlinedButton.styleFrom(
            minimumSize: const Size(0, 44),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            side: const BorderSide(color: Djassa.encre, width: 2),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
            textStyle: const TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 13,
              fontWeight: FontWeight.w700,
            ),
          ),
          onPressed: onDeconnexion,
          child: const Text('Déconnexion'),
        ),
      ],
    );
  }
}

class _CarteFlotte extends StatelessWidget {
  const _CarteFlotte({required this.societe, required this.onOuvrir});

  final CompanyMembership societe;
  final VoidCallback onOuvrir;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onOuvrir,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Djassa.encre,
          borderRadius: BorderRadius.circular(Djassa.rayon),
        ),
        child: Row(
          children: <Widget>[
            const Text('🚚', style: TextStyle(fontSize: 26)),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(societe.name, style: Djassa.affiche(19, couleur: Djassa.ambre)),
                  Text(
                    'Mon parc · ${societe.roleLabel}',
                    style: const TextStyle(
                      fontFamily: Djassa.texte,
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: Djassa.creme,
                    ),
                  ),
                ],
              ),
            ),
            const Text('→', style: TextStyle(fontSize: 22, color: Djassa.creme)),
          ],
        ),
      ),
    );
  }
}

class _Tuile extends StatelessWidget {
  const _Tuile({required this.nombre, required this.libelle, required this.sombre});

  final String nombre;
  final String libelle;
  final bool sombre;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: sombre ? Djassa.encre : Colors.white,
        border: sombre ? null : Border.all(color: Djassa.encre, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            nombre,
            style: Djassa.affiche(28, couleur: sombre ? Djassa.ambre : Djassa.accent),
          ),
          Text(
            libelle,
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: sombre ? Djassa.creme : Djassa.sourdine,
            ),
          ),
        ],
      ),
    );
  }
}

class _CarteBien extends StatelessWidget {
  const _CarteBien({
    required this.bien,
    required this.onOuvrir,
    required this.onDeclarerVol,
  });

  final OwnedAsset bien;
  final VoidCallback onOuvrir;
  final VoidCallback onDeclarerVol;

  @override
  Widget build(BuildContext context) {
    // Le contour reprend la couleur du statut quand il alarme : la carte se
    // repère alors dans la liste sans avoir à la lire.
    final contour = bien.isStolen ? Djassa.alerte : Djassa.encre;

    return InkWell(
      onTap: onOuvrir,
      child: Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: contour, width: Djassa.trait),
          borderRadius: BorderRadius.circular(Djassa.rayonPanneau),
          boxShadow: Djassa.relief(contour),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(bien.label, style: Djassa.affiche(19)),
                      const SizedBox(height: 2),
                      // LE NUMÉRO RESTE AFFICHÉ SOUS LE LIBELLÉ : c'est lui qui
                      // fait foi, et lui qu'on recopie sur une carte grise. Un
                      // nom de modèle seul ferait confondre deux motos
                      // identiques d'un même parc.
                      Text(
                        bien.identifier,
                        style: const TextStyle(
                          fontFamily: Djassa.texte,
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: Djassa.etiquette,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                PastilleStatut(bien.lifeStatus),
              ],
            ),
            if (!bien.isStolen && !bien.isFrozen) ...<Widget>[
              const SizedBox(height: 12),
              BoutonRelief(
                libelle: '🚨 Je déclare le vol',
                couleurFond: Djassa.alerte,
                couleurTexte: Djassa.creme,
                principal: false,
                onPressed: onDeclarerVol,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _QuiRegarde extends StatelessWidget {
  const _QuiRegarde({required this.biens});

  final List<OwnedAsset> biens;

  @override
  Widget build(BuildContext context) {
    final regardes = biens.where((OwnedAsset b) => b.lookups30d > 0).toList(growable: false);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayonPanneau),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('Qui regarde mes biens ?', style: Djassa.affiche(18)),
          const SizedBox(height: 10),
          if (regardes.isEmpty)
            const Text(
              'Aucune consultation ces trente derniers jours.',
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: Djassa.sourdine,
              ),
            )
          else
            ...regardes.map(
              (OwnedAsset b) => Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        '${b.label} · consulté',
                        style: const TextStyle(
                          fontFamily: Djassa.texte,
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          height: 1.35,
                        ),
                      ),
                    ),
                    Text(
                      '${b.lookups30d} fois / 30 j',
                      style: const TextStyle(
                        fontFamily: Djassa.texte,
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: Djassa.etiquette,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          const SizedBox(height: 12),
          // LA PHRASE EST AUSSI IMPORTANTE QUE LE CHIFFRE. Sans elle, quelqu'un
          // cherchera « qui » — et l'absence passerait pour un défaut plutôt que
          // pour la garantie qu'elle est.
          const Text(
            '🔒 L\'identité des personnes qui consultent n\'est jamais partagée. Toi non '
            'plus, tu restes anonyme quand tu vérifies.',
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 12,
              fontWeight: FontWeight.w700,
              height: 1.4,
              color: Djassa.etiquette,
            ),
          ),
        ],
      ),
    );
  }
}

class _BandeauTransferts extends StatelessWidget {
  const _BandeauTransferts({required this.attentes, required this.onOuvrir});

  final List<PendingTransfer> attentes;
  final VoidCallback onOuvrir;

  @override
  Widget build(BuildContext context) {
    final aMoi = attentes.where((PendingTransfer t) => t.awaitsMe).length;

    return InkWell(
      onTap: onOuvrir,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Djassa.accent, width: Djassa.trait),
          borderRadius: BorderRadius.circular(Djassa.rayon),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              aMoi > 0
                  ? 'Un geste t\'attend sur ${aMoi > 1 ? '$aMoi transferts' : 'un transfert'}'
                  : '${attentes.length} transfert${attentes.length > 1 ? 's' : ''} en cours',
              style: Djassa.affiche(19),
            ),
            const SizedBox(height: 6),
            const Text(
              'Un transfert non confirmé expire au bout de sept jours, et le bien '
              'revient à son détenteur.',
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 14,
                height: 1.4,
                color: Djassa.sourdine,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Quota extends StatelessWidget {
  const _Quota({required this.quota});

  final Map<String, Object?> quota;

  @override
  Widget build(BuildContext context) {
    // LA PHRASE VIENT DU SERVEUR, et pas seulement les chiffres. Le nombre de
    // places gratuites et le prix de la suivante sont des réglages
    // d'exploitation : une formulation composée ici annoncerait, le jour où
    // l'un d'eux change, un tarif que la plateforme n'applique plus — sur des
    // téléphones qui ne se mettent pas à jour.
    final message = quota['message'];

    if (message is! String || message.isEmpty) {
      return const SizedBox.shrink();
    }

    return Text(
      message,
      style: const TextStyle(
        fontFamily: Djassa.texte,
        fontSize: 14,
        color: Djassa.sourdine,
        height: 1.4,
      ),
    );
  }
}
