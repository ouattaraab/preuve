import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Les transferts qui attendent un geste, dans les deux sens.
///
/// CET ÉCRAN EST LA SEULE PORTE DE L'ACHETEUR. Son invitation est un code reçu
/// par SMS — délibérément, pour ne pas payer deux messages — et ce code ne
/// porte aucun numéro de transfert. Sans cette liste, il n'a rien à confirmer
/// et le transfert expire tout seul au bout de sept jours.
///
/// LE CAMP VIENT DU SERVEUR, JAMAIS D'UNE DÉDUCTION LOCALE : confondre les deux
/// ferait confirmer une vente à quelqu'un qui croyait accepter un bien.
class TransfersScreen extends StatefulWidget {
  const TransfersScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<TransfersScreen> createState() => _TransfersScreenState();
}

class _TransfersScreenState extends State<TransfersScreen> {
  List<PendingTransfer> _transferts = const <PendingTransfer>[];
  bool _enCours = true;
  String? _erreur;
  String? _confirmation;

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
      final transferts = await widget.session.transfers.mine();

      if (mounted) {
        setState(() => _transferts = transferts);
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

  Future<void> _confirmer(PendingTransfer transfert) async {
    final vendeur = transfert.role == TransferRole.seller;

    final code = await demanderCodeAction(
      context,
      session: widget.session,
      motif: OtpPurpose.transfer,
      titre: vendeur ? 'Confirmer la cession' : 'Accepter ce bien',
      consequence: vendeur
          ? 'Une fois vos deux confirmations réunies, le bien change de mains et ne '
              'sera plus le tien.'
          : 'Le bien passera à ton nom. Il repartira au niveau « Déclaré » : les '
              'documents du vendeur prouvaient SA propriété, pas la tienne.',
    );

    if (code == null || code.isEmpty || !mounted) {
      return;
    }

    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      await widget.session.transfers.confirm(
        transfert.id,
        code: code,
        // Le camp tel que le SERVEUR l'a donné, jamais recalculé ici.
        role: transfert.role,
      );

      if (mounted) {
        setState(() => _confirmation = vendeur
            ? 'Ta confirmation est enregistrée.'
            : 'C\'est fait : le bien est à ton nom.');
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      await _charger();
    }
  }

  Future<void> _annuler(PendingTransfer transfert) async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      await widget.session.transfers.cancel(transfert.id);

      if (mounted) {
        setState(() => _confirmation = 'Le transfert est annulé. Le bien reste le tien.');
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
    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Transferts', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          children: <Widget>[
            if (_confirmation != null) ...<Widget>[
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Colors.white,
                  border: Border.all(color: Djassa.accent, width: 3),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  _confirmation!,
                  style: const TextStyle(fontWeight: FontWeight.w700, height: 1.4),
                ),
              ),
              const SizedBox(height: 16),
            ],
            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 16),
            ],
            if (_enCours)
              const EnCours()
            else if (_transferts.isEmpty)
              const RienEncore(
                titre: 'Aucun transfert en cours',
                explication:
                    'Quand quelqu\'un te cède un bien, il apparaît ici. Tu reçois aussi un '
                    'code par SMS : c\'est lui qu\'il faudra saisir pour accepter.',
              )
            else
              ..._transferts.map(
                (PendingTransfer t) => Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: _CarteTransfert(
                    transfert: t,
                    onConfirmer: () => _confirmer(t),
                    onAnnuler: t.role == TransferRole.seller ? () => _annuler(t) : null,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _CarteTransfert extends StatelessWidget {
  const _CarteTransfert({
    required this.transfert,
    required this.onConfirmer,
    this.onAnnuler,
  });

  final PendingTransfer transfert;
  final VoidCallback onConfirmer;
  final VoidCallback? onAnnuler;

  @override
  Widget build(BuildContext context) {
    final vendeur = transfert.role == TransferRole.seller;
    final bien = transfert.asset;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: 3),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            vendeur ? 'Tu cèdes ce bien' : 'On te cède ce bien',
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 6),
          // LA VUE PUBLIQUE, DONC PAS LE NUMÉRO COMPLET. Un transfert part vers
          // un numéro saisi à la main : un chiffre de travers, et la fiche
          // détaillée d'un véhicule atterrirait chez un inconnu.
          if (bien != null) ...<Widget>[
            Text(
              '${bien.category} · ${bien.publicRef}',
              style: const TextStyle(color: Djassa.sourdine, letterSpacing: 1.1),
            ),
            const SizedBox(height: 10),
            PastilleStatut(bien.lifeStatus),
            const SizedBox(height: 12),
          ],
          Text(
            transfert.statusLabel,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          if (transfert.expiresAt != null) ...<Widget>[
            const SizedBox(height: 4),
            Text(
              _echeance(transfert.expiresAt!),
              style: const TextStyle(color: Djassa.sourdine),
            ),
          ],
          const SizedBox(height: 14),
          if (transfert.awaitsMe)
            FilledButton(
              onPressed: onConfirmer,
              child: Text(vendeur ? 'Confirmer la cession' : 'Accepter ce bien'),
            )
          else
            Text(
              vendeur
                  ? 'Ta part est faite. On attend la confirmation de l\'acheteur.'
                  : 'Ta part est faite. On attend la confirmation du vendeur.',
              style: const TextStyle(color: Djassa.sourdine, height: 1.4),
            ),
          if (onAnnuler != null) ...<Widget>[
            const SizedBox(height: 6),
            TextButton(
              onPressed: onAnnuler,
              child: const Text('Annuler le transfert'),
            ),
          ],
        ],
      ),
    );
  }

  /// Une échéance en jours, pas une date : « expire dans 3 jours » se comprend
  /// d'un coup d'œil là où « 11/08/2026 » demande un calcul.
  static String _echeance(DateTime quand) {
    final restant = quand.difference(DateTime.now());

    if (restant.isNegative) {
      return 'Expiré.';
    }

    if (restant.inHours < 24) {
      return 'Expire dans moins de 24 heures.';
    }

    final jours = restant.inDays;

    return 'Expire dans $jours jour${jours > 1 ? 's' : ''}.';
  }
}
