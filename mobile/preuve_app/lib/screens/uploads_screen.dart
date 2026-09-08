import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Suivi des envois différés (ST-0206, CT-05).
///
/// CET ÉCRAN EXISTE PARCE QUE L'ENVOI ÉCHOUERA. Sur une 3G de bord de route, une
/// carte grise de plusieurs mégaoctets ne passe pas d'un trait ; ce qui doit
/// tenir, ce n'est pas l'envoi mais la possibilité de le REPRENDRE. Sans écran,
/// personne ne saurait qu'une pièce attend encore, ni pourquoi le niveau de
/// fiabilité de son bien ne monte pas.
///
/// LA REPRISE EST DÉCLENCHÉE PAR LA PERSONNE, jamais par un minuteur enfoui : un
/// réessai automatique en arrière-plan viderait le forfait de quelqu'un dans son
/// dos, et sur un réseau facturé au volume ce n'est pas un détail.
class UploadsScreen extends StatefulWidget {
  const UploadsScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<UploadsScreen> createState() => _UploadsScreenState();
}

class _UploadsScreenState extends State<UploadsScreen> {
  bool _enCours = false;
  String? _message;
  final Map<String, double> _progression = <String, double>{};

  Future<void> _envoyer() async {
    setState(() {
      _enCours = true;
      _message = null;
      _progression.clear();
    });

    final rapport = await widget.session.envois.drain(
      onProgress: (PendingUpload envoi, UploadState etat) {
        if (mounted) {
          setState(() => _progression[envoi.uuid] = etat.progress);
        }
      },
    );

    if (!mounted) {
      return;
    }

    setState(() {
      _enCours = false;
      _message = _bilan(rapport);
    });
  }

  /// Dit ce qui est parti, ce qui attend, et ce qui ne repartira pas.
  ///
  /// LA TROISIÈME CATÉGORIE EST LA PLUS IMPORTANTE : une pièce retirée de la
  /// file sans que personne ne le sache ferait croire un dossier complet.
  static String _bilan(DrainReport rapport) {
    final morceaux = <String>[];

    if (rapport.completed.isNotEmpty) {
      final n = rapport.completed.length;
      morceaux.add('$n pièce${n > 1 ? 's' : ''} envoyée${n > 1 ? 's' : ''}.');
    }

    if (rapport.networkInterrupted) {
      morceaux.add(
        'Le réseau s\'est coupé. Ce qui reste est conservé et repartira d\'où '
        'l\'envoi s\'est arrêté — rien n\'est perdu.',
      );
    }

    for (final String raison in rapport.abandoned.values) {
      morceaux.add(raison);
    }

    return morceaux.isEmpty ? 'Rien à envoyer.' : morceaux.join('\n\n');
  }

  @override
  Widget build(BuildContext context) {
    final attente = widget.session.envois.pending;

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Envois en attente', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          children: <Widget>[
            if (_message != null) ...<Widget>[
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Colors.white,
                  border: Border.all(color: Djassa.accent, width: 3),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  _message!,
                  style: const TextStyle(fontWeight: FontWeight.w700, height: 1.45),
                ),
              ),
              const SizedBox(height: 18),
            ],
            if (attente.isEmpty)
              const RienEncore(
                titre: 'Aucune pièce en attente',
                explication:
                    'Les photos et justificatifs que tu ajoutes partent d\'ici. S\'ils ne '
                    'passent pas du premier coup, ils restent en file et repartent d\'où '
                    'l\'envoi s\'est arrêté — sans jamais recommencer depuis le début.',
              )
            else ...<Widget>[
              Text(
                '${attente.length} pièce${attente.length > 1 ? 's' : ''} à envoyer',
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 14),
              ...attente.map(
                (PendingUpload envoi) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _Ligne(
                    envoi: envoi,
                    progression: _progression[envoi.uuid],
                    onRetirer: _enCours
                        ? null
                        : () async {
                            await widget.session.envois.forget(envoi.uuid);

                            if (mounted) {
                              setState(() {});
                            }
                          },
                  ),
                ),
              ),
              const SizedBox(height: 8),
              FilledButton(
                onPressed: _enCours ? null : _envoyer,
                child: _enCours
                    ? const SizedBox(
                        height: 24,
                        width: 24,
                        child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
                      )
                    : const Text('Envoyer maintenant'),
              ),
              const SizedBox(height: 14),
              const Text(
                'Lance l\'envoi quand tu es en Wi-Fi ou que le réseau est bon. Rien ne part '
                'tout seul : ton forfait ne se videra pas dans ton dos.',
                style: TextStyle(color: Djassa.sourdine, height: 1.5),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Ligne extends StatelessWidget {
  const _Ligne({required this.envoi, required this.progression, required this.onRetirer});

  final PendingUpload envoi;
  final double? progression;
  final VoidCallback? onRetirer;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.encre, width: 3),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            envoi.filename,
            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 4),
          Text(
            _poids(envoi.byteSize),
            style: const TextStyle(color: Djassa.sourdine),
          ),
          if (progression != null) ...<Widget>[
            const SizedBox(height: 10),
            LinearProgressIndicator(
              value: progression,
              minHeight: 8,
              backgroundColor: const Color(0xFFE4DBC8),
              color: Djassa.accent,
            ),
          ],
          if (onRetirer != null) ...<Widget>[
            const SizedBox(height: 4),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: onRetirer,
                child: const Text('Retirer de la file'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  static String _poids(int octets) {
    if (octets < 1024) {
      return '$octets o';
    }

    if (octets < 1024 * 1024) {
      return '${(octets / 1024).round()} Ko';
    }

    return '${(octets / (1024 * 1024)).toStringAsFixed(1)} Mo';
  }
}
