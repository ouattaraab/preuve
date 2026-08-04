import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Céder un bien à un acheteur, par son numéro de téléphone.
///
/// LE VENDEUR PROPOSE, L'ACHETEUR CONFIRME, ET LE TRANSFERT EXPIRE. Sans double
/// validation, un vendeur pourrait se décharger d'un bien litigieux sur
/// quelqu'un qui n'en saurait rien ; sans expiration, un transfert oublié
/// resterait indéfiniment en suspens sur un bien qu'on croit vendu.
///
/// AUCUN CODE N'EST DEMANDÉ ICI. Le serveur en envoie un à l'ACHETEUR — c'est
/// l'invitation elle-même — et le vendeur confirmera sa part ensuite, depuis la
/// liste des transferts. Réclamer un code au vendeur avant même d'avoir engagé
/// le transfert lui en ferait demander un pour rien si l'acheteur refuse.
class TransferProposeScreen extends StatefulWidget {
  const TransferProposeScreen({required this.session, required this.bien, super.key});

  final PreuveSession session;
  final OwnedAsset bien;

  @override
  State<TransferProposeScreen> createState() => _TransferProposeScreenState();
}

class _TransferProposeScreenState extends State<TransferProposeScreen> {
  final TextEditingController _telephone = TextEditingController();

  bool _enCours = false;
  String? _erreur;

  @override
  void dispose() {
    _telephone.dispose();
    super.dispose();
  }

  Future<void> _proposer() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      await widget.session.transfers.propose(
        widget.bien.id,
        buyerPhone: _telephone.text.trim(),
      );

      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } on InvalidRequest catch (e) {
      if (mounted) {
        // Le serveur refuse ici pour des raisons qui tiennent au métier —
        // identité non vérifiée, transfert déjà en cours, numéro identique au
        // sien — et son texte les dit mieux qu'une reformulation.
        setState(() => _erreur = e.forField('buyer_phone') ?? e.message);
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
    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Céder ce bien', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                widget.bien.label,
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 4),
              Text(
                widget.bien.identifier,
                style: const TextStyle(color: Djassa.sourdine, letterSpacing: 1.1),
              ),
              const SizedBox(height: 22),
              const Text(
                'Numéro de l\'acheteur',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              const Text(
                'Il recevra un code par SMS. Le bien ne change de mains que lorsque '
                'vous avez confirmé tous les deux.',
                style: TextStyle(color: Djassa.sourdine, height: 1.5),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _telephone,
                autofocus: true,
                keyboardType: TextInputType.phone,
                style: const TextStyle(fontSize: 22),
                decoration: const InputDecoration(
                  labelText: 'Téléphone',
                  hintText: '+225 01 01 18 16 86',
                ),
              ),
              if (_erreur != null) ...<Widget>[
                const SizedBox(height: 14),
                EncadreErreur(_erreur!),
              ],
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _enCours ? null : _proposer,
                child: _enCours
                    ? const SizedBox(
                        height: 24,
                        width: 24,
                        child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
                      )
                    : const Text('Proposer le transfert'),
              ),
              const SizedBox(height: 26),
              const Divider(color: Djassa.encre, thickness: 3),
              const SizedBox(height: 16),
              const Text(
                'Ce qu\'il faut savoir',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 10),
              // LA BAISSE DE FIABILITÉ EST ANNONCÉE AVANT, pas constatée après.
              // Les justificatifs appuyaient la propriété du VENDEUR : sans
              // cette phrase, la jauge qui retombe passera pour un défaut de
              // l'application, et l'acheteur s'en plaindra à tort.
              const Text(
                '• Le bien repart au niveau « Déclaré » chez l\'acheteur. Les documents '
                'que tu avais fournis prouvaient TA propriété, pas la sienne : il devra '
                'refaire cette étape.\n'
                '• Sans confirmation de sa part sous sept jours, le transfert expire et '
                'le bien te revient.\n'
                '• Tu peux annuler tant qu\'il n\'a pas confirmé.',
                style: TextStyle(height: 1.6),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
