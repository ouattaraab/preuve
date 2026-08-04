import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';
import 'asset_screen.dart';
import 'kyc_screen.dart';
import 'notifications_screen.dart';
import 'register_screen.dart';
import 'transfers_screen.dart';
import 'uploads_screen.dart';

/// Mes biens — le point d'entrée de tout ce qui n'est pas la consultation.
///
/// CET ÉCRAN EXISTE PARCE QUE LES ACTIONS PASSENT PAR LE BIEN. Déclarer un vol,
/// céder, réclamer : chacune vise un bien précis, et personne ne retient un
/// numéro de châssis. La liste est donc moins un tableau de bord qu'un
/// trousseau de clés.
///
/// LES TRANSFERTS EN ATTENTE SONT REMONTÉS ICI, en tête. Un transfert expire au
/// bout de sept jours, et un acheteur qui ne sait pas qu'on lui a cédé un bien
/// ne le confirmera jamais : l'enterrer dans un onglet reviendrait à le laisser
/// mourir de lui-même.
class MyAssetsScreen extends StatefulWidget {
  const MyAssetsScreen({required this.session, super.key});

  final PreuveSession session;

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

      // LES DEUX APPELS SONT SÉPARÉS ET LE SECOND N'EST PAS BLOQUANT : un
      // échec sur les transferts ne doit pas priver quelqu'un de la liste de
      // ses biens, sur laquelle se trouve la déclaration de vol.
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
        // Même raison : un badge indisponible ne vaut pas de priver quelqu'un
        // de sa liste de biens.
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
        setState(() => _erreur = e.message);
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  Future<void> _enregistrer() async {
    final cree = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(
        builder: (_) => RegisterScreen(session: widget.session),
      ),
    );

    if (cree == true) {
      await _charger();
    }
  }

  @override
  Widget build(BuildContext context) {
    final inventaire = _inventaire;

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Mes biens', style: TextStyle(fontWeight: FontWeight.w800)),
        actions: <Widget>[
          TextButton(
            onPressed: () async {
              await Navigator.of(context).push<void>(
                MaterialPageRoute<void>(
                  builder: (_) => NotificationsScreen(session: widget.session),
                ),
              );

              await _charger();
            },
            child: Text(
              // Le nombre EST le message : « Alertes » seul ne dit pas s'il
              // faut y aller maintenant.
              _alertes > 0 ? 'Alertes ($_alertes)' : 'Alertes',
              style: TextStyle(
                fontWeight: FontWeight.w800,
                color: _alertes > 0 ? Djassa.alerte : Djassa.encre,
              ),
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _charger,
          color: Djassa.encre,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
            children: <Widget>[
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 14),
              ],
              if (_attentes.isNotEmpty) ...<Widget>[
                _BandeauTransferts(
                  attentes: _attentes,
                  onOuvrir: () async {
                    await Navigator.of(context).push<void>(
                      MaterialPageRoute<void>(
                        builder: (_) => TransfersScreen(session: widget.session),
                      ),
                    );

                    await _charger();
                  },
                ),
                const SizedBox(height: 20),
              ],
              if (_enCours && inventaire == null)
                const EnCours()
              else if (inventaire == null || inventaire.assets.isEmpty)
                const RienEncore(
                  titre: 'Aucun bien enregistré',
                  explication:
                      'Enregistrer un bien, c\'est ce qui permet de le déclarer volé plus '
                      'tard, et à un acheteur de vérifier qu\'il est bien à toi. Cela prend '
                      'moins de deux minutes.',
                )
              else
                ...inventaire.assets.map(
                  (OwnedAsset bien) => Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: _CarteBien(
                      bien: bien,
                      onOuvrir: () async {
                        await Navigator.of(context).push<void>(
                          MaterialPageRoute<void>(
                            builder: (_) => AssetScreen(
                              session: widget.session,
                              bien: bien,
                            ),
                          ),
                        );

                        await _charger();
                      },
                    ),
                  ),
                ),
              const SizedBox(height: 10),
              FilledButton(
                onPressed: _enregistrer,
                child: const Text('Enregistrer un bien'),
              ),
              if (inventaire?.quota != null) ...<Widget>[
                const SizedBox(height: 12),
                _Quota(quota: inventaire!.quota!),
              ],
              const SizedBox(height: 26),
              const Divider(color: Djassa.encre, thickness: 3),
              const SizedBox(height: 6),
              TextButton(
                onPressed: () async {
                  await Navigator.of(context).push<void>(
                    MaterialPageRoute<void>(
                      builder: (_) => UploadsScreen(session: widget.session),
                    ),
                  );

                  if (mounted) {
                    setState(() {});
                  }
                },
                child: Text(
                  // Le nombre en attente EST le message : « Envois » seul ne dit
                  // pas s'il reste quelque chose à faire partir.
                  widget.session.envois.pending.isEmpty
                      ? 'Envois en attente'
                      : 'Envois en attente (${widget.session.envois.pending.length})',
                ),
              ),
              TextButton(
                onPressed: () => Navigator.of(context).push<void>(
                  MaterialPageRoute<void>(
                    builder: (_) => KycScreen(session: widget.session),
                  ),
                ),
                child: const Text('Vérifier mon identité'),
              ),
              TextButton(
                onPressed: () async {
                  // Le navigateur est saisi AVANT la déconnexion : après elle,
                  // ce contexte peut ne plus être monté, et `mounted` porte sur
                  // l'état, pas sur lui. La déconnexion, elle, doit fermer
                  // l'écran quoi qu'il arrive — c'est souvent qu'on prête son
                  // téléphone.
                  final navigateur = Navigator.of(context);

                  await widget.session.fermer();
                  navigateur.pop();
                },
                child: const Text('Se déconnecter'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CarteBien extends StatelessWidget {
  const _CarteBien({required this.bien, required this.onOuvrir});

  final OwnedAsset bien;
  final VoidCallback onOuvrir;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onOuvrir,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Djassa.encre, width: 3),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              bien.label,
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            // LE NUMÉRO RESTE AFFICHÉ SOUS LE LIBELLÉ : c'est lui qui fait foi,
            // et c'est lui qu'on recopie sur une carte grise. Un nom de modèle
            // seul ferait confondre deux motos identiques d'un même parc.
            Text(
              bien.identifier,
              style: const TextStyle(
                color: Djassa.sourdine,
                letterSpacing: 1.1,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: <Widget>[
                PastilleStatut(bien.lifeStatus),
                PastilleStatut(bien.trustLevel),
              ],
            ),
          ],
        ),
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
          border: Border.all(color: Djassa.accent, width: 3),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              aMoi > 0
                  ? 'Un geste t\'attend sur ${aMoi > 1 ? '$aMoi transferts' : 'un transfert'}'
                  : '${attentes.length} transfert${attentes.length > 1 ? 's' : ''} en cours',
              style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            const Text(
              'Un transfert non confirmé expire au bout de sept jours, et le bien '
              'revient à son détenteur.',
              style: TextStyle(color: Djassa.sourdine, height: 1.4),
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
      style: const TextStyle(color: Djassa.sourdine, height: 1.4),
    );
  }
}
