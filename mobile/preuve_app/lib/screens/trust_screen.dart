import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Ce qu'il manque à un bien pour monter d'un cran (ST-0401).
///
/// LE DÉTENTEUR VOYAIT UNE PASTILLE, ET AUCUN LEVIER. « Déclaré » s'affichait
/// sur sa fiche sans que rien ne dise ce qu'il fallait faire pour la faire
/// monter, ni pourquoi cela vaudrait la peine. Il restait donc au niveau le
/// plus bas — et c'est le niveau de fiabilité qui donne sa valeur au rapport
/// vendu aux acheteurs : un registre de biens tous « déclarés » ne vaut pas
/// cher.
///
/// LE BÉNÉFICE AVANT L'EFFORT. La liste de ce qui manque, seule, se lit comme
/// une liste de corvées. Ce que le palier apporte vient donc en premier, dans
/// les mots du serveur — qui connaît les règles en vigueur, versionnées, là où
/// une phrase écrite ici se périmerait sur des téléphones qui ne se mettent pas
/// à jour.
///
/// LE MOTIF D'UN REFUS EST MONTRÉ. Une pièce refusée sans raison ne se corrige
/// pas : on la redépose à l'identique, un agent la refuse à nouveau, et les
/// deux côtés perdent leur temps.
class TrustScreen extends StatefulWidget {
  const TrustScreen({required this.session, required this.bien, super.key});

  final PreuveSession session;
  final OwnedAsset bien;

  @override
  State<TrustScreen> createState() => _TrustScreenState();
}

class _TrustScreenState extends State<TrustScreen> {
  TrustProgress? _etat;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final TrustProgress etat = await widget.session.fiabilite.forAsset(widget.bien.id);

      if (mounted) {
        setState(() => _etat = etat);
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final TrustProgress? etat = _etat;

    return Scaffold(
      appBar: const BarrePreuve(titre: 'Niveau de confiance'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 40),
          children: <Widget>[
            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 14),
            ],

            if (etat == null)
              const EnCours()
            else ...<Widget>[
              Text(widget.bien.identifier, style: Djassa.affiche(24)),
              const SizedBox(height: 14),

              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: Djassa.encre,
                  borderRadius: BorderRadius.circular(Djassa.rayon),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    const Text(
                      'NIVEAU ACTUEL',
                      style: TextStyle(
                        fontFamily: Djassa.texte,
                        fontSize: 12,
                        letterSpacing: 1.2,
                        fontWeight: FontWeight.w700,
                        color: Djassa.creme,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(etat.currentLabel, style: Djassa.affiche(26, couleur: Djassa.ambre)),
                  ],
                ),
              ),
              const SizedBox(height: 18),

              if (!etat.canProgress) ...<Widget>[
                // AU SOMMET, ON NE PROPOSE RIEN. Inventer une étape ferait
                // courir après un palier qui n'existe pas.
                const RienEncore(
                  titre: 'Rien de plus à faire',
                  explication: 'Ce bien porte le niveau de confiance le plus élevé. C\'est ce '
                      'qu\'un acheteur voit de mieux avant d\'acheter.',
                ),
              ] else ...<Widget>[
                Text('Passer à « ${etat.nextLabel} »', style: Djassa.affiche(21)),
                const SizedBox(height: 8),

                // LE BÉNÉFICE D'ABORD : sans lui, on demande un effort pour
                // une pastille de couleur.
                if (etat.benefit != null)
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      border: Border.all(color: const Color(0xFF1F7A4C), width: Djassa.trait),
                      borderRadius: BorderRadius.circular(Djassa.rayon),
                    ),
                    child: Text(
                      etat.benefit!,
                      style: const TextStyle(
                        fontFamily: Djassa.texte,
                        fontSize: 16,
                        height: 1.5,
                        color: Djassa.encre,
                      ),
                    ),
                  ),
                const SizedBox(height: 16),

                Text('Ce qu\'il manque', style: Djassa.affiche(19)),
                const SizedBox(height: 8),
                if (etat.missing.isEmpty)
                  const Text(
                    'Rien : le passage se fera au prochain contrôle.',
                    style: TextStyle(fontFamily: Djassa.texte, fontSize: 16, height: 1.5),
                  )
                else
                  ...etat.missing.map((String manque) => Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            const Text('•  ', style: TextStyle(fontSize: 17, height: 1.45)),
                            Expanded(
                              child: Text(
                                manque,
                                style: const TextStyle(
                                  fontFamily: Djassa.texte,
                                  fontSize: 16,
                                  height: 1.45,
                                  color: Djassa.encre,
                                ),
                              ),
                            ),
                          ],
                        ),
                      )),
              ],

              const SizedBox(height: 22),
              Text('Pièces déposées', style: Djassa.affiche(19)),
              const SizedBox(height: 8),

              if (etat.documents.isEmpty)
                const Text(
                  'Aucune pièce pour l\'instant. Une carte grise ou une facture suffit à '
                  'faire monter ce bien d\'un cran.',
                  style: TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 16,
                    height: 1.5,
                    color: Djassa.sourdine,
                  ),
                )
              else
                ...etat.documents.map(_LignePiece.new),
            ],
          ],
        ),
      ),
    );
  }
}

class _LignePiece extends StatelessWidget {
  const _LignePiece(this.piece);

  final TrustDocument piece;

  @override
  Widget build(BuildContext context) {
    final Color couleur = piece.isAccepted
        ? const Color(0xFF1F7A4C)
        : (piece.isRejected ? Djassa.alerte : Djassa.ambre);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  piece.typeLabel,
                  style: const TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: couleur,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  piece.statusLabel,
                  style: const TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: Colors.white,
                  ),
                ),
              ),
            ],
          ),
          // LE MOTIF DU REFUS, MONTRÉ AU DÉPOSANT. Sans lui, la pièce est
          // redéposée à l'identique et refusée à nouveau.
          if (piece.reason != null && piece.reason!.isNotEmpty) ...<Widget>[
            const SizedBox(height: 8),
            Text(
              piece.reason!,
              style: const TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 14,
                height: 1.45,
                color: Djassa.alerte,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
