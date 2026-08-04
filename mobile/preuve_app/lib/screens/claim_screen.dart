import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/fichiers.dart';
import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/photo_choice.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Réclamer un bien enregistré par quelqu'un d'autre (EP-05).
///
/// C'EST LE SEUL RECOURS D'UNE VICTIME, et il commence toujours par la
/// RÉFÉRENCE PUBLIQUE : elle ne connaît pas l'identifiant interne du bien qu'on
/// lui a pris, et c'est voulu — le publier permettrait de balayer le registre.
///
/// OUVRIR LE DOSSIER ET Y VERSER DES PIÈCES SONT LIBRES. C'est le DÉPÔT qui
/// peut coûter, parce que c'est là que le bien est gelé et le détenteur
/// prévenu : le moment où la réclamation commence à coûter à quelqu'un d'autre.
/// L'écran ne demande donc jamais de payer pour constituer un dossier.
///
/// LE MONTANT N'EST JAMAIS ÉCRIT ICI. Un administrateur peut le mettre à zéro —
/// c'est ce qui empêche le filtre anti-nuisance de devenir un filtre
/// anti-pauvres — et une somme embarquée réclamerait de l'argent que la
/// plateforme vient précisément de cesser de demander.
class ClaimScreen extends StatefulWidget {
  const ClaimScreen({
    required this.session,
    required this.publicRef,
    super.key,
  });

  final PreuveSession session;

  /// Référence opaque du bien contesté, lue sur le verdict ou dans le refus
  /// reçu en tentant de l'enregistrer.
  final String publicRef;

  @override
  State<ClaimScreen> createState() => _ClaimScreenState();
}

class _ClaimScreenState extends State<ClaimScreen> {
  Claim? _dossier;
  bool _enCours = false;
  String? _erreur;
  String? _fraisDus;
  final Set<EvidenceKind> _piecesVersees = <EvidenceKind>{};

  Future<void> _ouvrir() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final dossier = await widget.session.claims.openByReference(widget.publicRef);

      if (mounted) {
        setState(() => _dossier = dossier);
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

  /// Verse une pièce, avec son document quand il y en a un.
  ///
  /// LE DOCUMENT EST DEMANDÉ D'ABORD, ET UN ABANDON N'ÉCRIT RIEN. Enregistrer la
  /// nature de preuve avant d'avoir la photo laisserait au dossier une pièce
  /// annoncée et vide : l'agent la compterait dans la grille, la victime croirait
  /// son dossier appuyé, et il ne le serait pas.
  ///
  /// DEUX NATURES SE DÉCLARENT SANS FICHIER — l'ancienneté du compte, que la
  /// plateforme constate elle-même, et l'antériorité documentaire, qui qualifie
  /// une pièce déjà versée. Leur réclamer une photo n'aurait aucun sens.
  Future<void> _verser(EvidenceKind nature) async {
    final dossier = _dossier;

    if (dossier == null) {
      return;
    }

    final avecDocument = nature != EvidenceKind.accountHistory;
    PhotoLocale? photo;

    if (avecDocument) {
      photo = await choisirPhoto(context, titre: nature.label);

      if (photo == null || !mounted) {
        return;
      }
    }

    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      await widget.session.claims.addEvidence(
        dossier.id,
        evidenceType: nature,
        file: photo == null ? null : await enUnSeulMorceau(photo, champ: 'file'),
      );

      if (mounted) {
        setState(() => _piecesVersees.add(nature));
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _erreur = 'Cette pièce n\'a pas pu être lue ($e). Reprends la photo.');
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  Future<void> _deposer() async {
    final dossier = _dossier;

    if (dossier == null) {
      return;
    }

    setState(() {
      _enCours = true;
      _erreur = null;
      _fraisDus = null;
    });

    try {
      final depose = await widget.session.claims.submit(dossier.id);

      if (mounted) {
        setState(() => _dossier = depose);
      }
    } on PaymentRequired catch (e) {
      // LE MONTANT SE LIT DANS LE REFUS, jamais dans le code. Un refus qui ne
      // dit pas combien ne laisse que l'abandon — c'est-à-dire une victime qui
      // renonce à son recours.
      if (mounted) {
        setState(() => _fraisDus = e.message);
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
    final dossier = _dossier;

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Ce bien est à moi', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          children: <Widget>[
            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 16),
            ],
            if (_fraisDus != null) ...<Widget>[
              EncadreErreur(_fraisDus!),
              const SizedBox(height: 16),
            ],
            if (dossier == null) ..._avantOuverture() else ..._dossierOuvert(dossier),
          ],
        ),
      ),
    );
  }

  List<Widget> _avantOuverture() {
    return <Widget>[
      const Text(
        'Quelqu\'un a enregistré ce bien avant toi',
        style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, height: 1.2),
      ),
      const SizedBox(height: 10),
      const Text(
        'Cela ne veut pas dire que tu as perdu ton bien. Un agent examinera les pièces '
        'des deux côtés et tranchera sur ce qu\'elles montrent, pas sur qui a enregistré '
        'le premier.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 20),
      Text(
        'Bien concerné : ${widget.publicRef}',
        style: const TextStyle(fontWeight: FontWeight.w700, letterSpacing: 1.1),
      ),
      const SizedBox(height: 20),
      const Text(
        'Ouvrir le dossier et y verser tes pièces ne coûte rien. Tu décideras ensuite '
        'de le déposer ou non.',
        style: TextStyle(height: 1.5),
      ),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: _enCours ? null : _ouvrir,
        child: _enCours
            ? const SizedBox(
                height: 24,
                width: 24,
                child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
              )
            : const Text('Ouvrir un dossier'),
      ),
      const SizedBox(height: 22),
      const Text(
        'Ton identité ne sera jamais communiquée au détenteur actuel, ni la sienne à '
        'toi. Seul l\'agent qui instruit le dossier voit les deux.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
    ];
  }

  List<Widget> _dossierOuvert(Claim dossier) {
    // Le dossier a quitté le brouillon : il est entre les mains d'un agent, et
    // il n'y a plus rien à faire ici que d'attendre.
    if (dossier.status != 'draft') {
      return <Widget>[
        RienEncore(
          titre: 'Dossier déposé',
          explication:
              '${dossier.statusLabel}. Le bien est gelé le temps de l\'instruction : '
              'personne ne peut le vendre, ni toi ni le détenteur actuel. Tu seras '
              'prévenu de la décision.',
        ),
      ];
    }

    return <Widget>[
      const Text(
        'Ce qui compte, ce sont les pièces',
        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      const Text(
        'Ajoute ce que tu as. Les dates portées sur les pièces priment sur la date '
        'd\'enregistrement : un document plus ancien que l\'enregistrement contesté '
        'pèse lourd.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 18),
      // La liste et son ORDRE viennent du cœur, où ils suivent la grille
      // d'arbitrage : présenter la photo avant la carte grise ferait verser
      // d'abord ce qui pèse le moins.
      ...EvidenceKind.values.map(
        (EvidenceKind nature) => Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _Nature(
            nature: nature,
            versee: _piecesVersees.contains(nature),
            onVerser: _enCours ? null : () => _verser(nature),
          ),
        ),
      ),
      const SizedBox(height: 10),
      FilledButton(
        onPressed: _enCours || _piecesVersees.isEmpty ? null : _deposer,
        child: const Text('Déposer le dossier'),
      ),
      const SizedBox(height: 14),
      const Text(
        'Au dépôt, le bien est gelé et le détenteur actuel est prévenu qu\'une '
        'réclamation le vise — sans savoir qui l\'a déposée. C\'est aussi à ce moment '
        'que des frais de dossier peuvent être demandés ; ils te sont remboursés si la '
        'réclamation aboutit.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
    ];
  }
}

class _Nature extends StatelessWidget {
  const _Nature({
    required this.nature,
    required this.versee,
    required this.onVerser,
  });

  final EvidenceKind nature;
  final bool versee;
  final VoidCallback? onVerser;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: versee ? null : onVerser,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: versee ? Djassa.accent : Colors.white,
          border: Border.all(color: Djassa.encre, width: 3),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              nature.label,
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, height: 1.3),
            ),
            const SizedBox(height: 4),
            Text(
              versee ? 'Annoncée dans le dossier.' : nature.explication,
              style: const TextStyle(color: Djassa.sourdine, height: 1.4),
            ),
          ],
        ),
      ),
    );
  }
}
