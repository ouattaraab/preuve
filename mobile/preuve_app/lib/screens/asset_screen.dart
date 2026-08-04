import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/fichiers.dart';
import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/photo_choice.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';
import 'transfer_propose_screen.dart';
import 'uploads_screen.dart';

/// Fiche d'un bien détenu, et les gestes qu'on peut y faire.
///
/// LA DÉCLARATION DE VOL EST LE PREMIER BOUTON, toujours. C'est le parcours le
/// plus urgent du produit : quelqu'un vient de se faire prendre sa moto, et
/// chaque écran de plus est une minute pendant laquelle le bien peut être
/// revendu. Elle ne se cache jamais derrière un menu.
///
/// LES ACTIONS IMPOSSIBLES SONT ABSENTES, pas grisées sans explication. Un bien
/// en transfert ou en litige n'accepte plus les gestes ordinaires : les
/// proposer ferait promettre à l'écran ce que le serveur refusera, et le refus
/// paraîtrait arbitraire.
class AssetScreen extends StatefulWidget {
  const AssetScreen({required this.session, required this.bien, super.key});

  final PreuveSession session;
  final OwnedAsset bien;

  @override
  State<AssetScreen> createState() => _AssetScreenState();
}

class _AssetScreenState extends State<AssetScreen> {
  late OwnedAsset _bien = widget.bien;
  bool _enCours = false;
  String? _erreur;
  String? _confirmation;

  /// Exécute un geste protégé par un code à usage unique.
  ///
  /// LE CODE EST DEMANDÉ AVANT L'APPEL, jamais après un premier refus : faire
  /// échouer une déclaration de vol pour la redemander ensuite ferait perdre
  /// les secondes qui comptent le plus.
  Future<void> _geste({
    required OtpPurpose motif,
    required String titre,
    required String consequence,
    required Future<PublicAsset> Function(String code) action,
    required String succes,
  }) async {
    final code = await demanderCodeAction(
      context,
      session: widget.session,
      motif: motif,
      titre: titre,
      consequence: consequence,
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
      final apres = await action(code);

      if (mounted) {
        setState(() {
          // Le statut rendu par le serveur remplace celui qu'on affichait :
          // c'est lui qui fait foi, et supposer le résultat ferait afficher un
          // « Volé déclaré » sur une déclaration qui n'a pas abouti.
          _bien = _avecStatut(_bien, apres.lifeStatus);
          _confirmation = succes;
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

  static OwnedAsset _avecStatut(OwnedAsset bien, StatusView statut) {
    return OwnedAsset(
      id: bien.id,
      publicRef: bien.publicRef,
      identifier: bien.identifier,
      category: bien.category,
      lifeStatus: statut,
      trustLevel: bien.trustLevel,
      attributes: bien.attributes,
      registeredAt: bien.registeredAt,
      stolenDeclaredAt: bien.stolenDeclaredAt,
    );
  }

  Future<void> _declarerVol() => _geste(
        motif: OtpPurpose.sensitiveAction,
        titre: 'Déclarer ce bien volé',
        consequence:
            'Le bien devient invendable immédiatement : toute personne qui vérifie le '
            'numéro verra « Volé déclaré ». Tu pourras lever l\'alerte toi-même si tu '
            'le retrouves.',
        action: (String code) => widget.session.lifecycle.declareStolen(_bien.id, code),
        succes: 'C\'est fait. Le bien est signalé volé : tout acheteur qui vérifie le '
            'numéro le verra.',
      );

  Future<void> _leverVol() => _geste(
        motif: OtpPurpose.sensitiveAction,
        titre: 'Lever l\'alerte de vol',
        consequence:
            'Le bien redevient vendable. L\'épisode reste consigné dans son historique : '
            'une levée n\'efface pas ce qui a eu lieu, et un acheteur a le droit de le '
            'savoir.',
        action: (String code) => widget.session.lifecycle.clearStolen(_bien.id, code),
        succes: 'L\'alerte est levée.',
      );

  Future<void> _finDeVie() => _geste(
        motif: OtpPurpose.sensitiveAction,
        titre: 'Déclarer ce bien hors d\'usage',
        consequence:
            'À faire quand le bien est détruit ou définitivement hors service. '
            'Ce n\'est pas réversible depuis l\'application.',
        action: (String code) => widget.session.lifecycle.declareEndOfLife(_bien.id, code),
        succes: 'Le bien est déclaré hors d\'usage.',
      );

  /// Ajoute un justificatif, qui fera monter la fiabilité du bien (ST-0207).
  ///
  /// LA PIÈCE PART EN FILE, JAMAIS EN DIRECT (ST-0206, CT-05). Une carte grise
  /// pèse plusieurs mégaoctets, et un envoi immédiat qui échoue au bord d'une
  /// route perdrait à la fois la photo et le geste. Mise en file, elle repartira
  /// d'où la coupure a eu lieu — et l'écran le dit, sans quoi la personne
  /// croirait avoir échoué.
  Future<void> _ajouterJustificatif(String docType, String titre) async {
    final photo = await choisirPhoto(context, titre: titre);

    if (photo == null || !mounted) {
      return;
    }

    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      await widget.session.envois.enqueue(
        await preparerEnvoi(photo: photo, assetId: _bien.id, docType: docType),
      );

      if (mounted) {
        setState(() => _confirmation =
            'Pièce ajoutée à la file d\'envoi. Lance l\'envoi depuis « Envois en '
            'attente » quand le réseau est bon : si la connexion coupe, elle '
            'repartira d\'où elle s\'est arrêtée.');
      }
    } on Object catch (e) {
      // Fichier illisible, coffre plein : la photo ne part pas, et le dire vaut
      // mieux que de laisser croire qu'elle attend.
      if (mounted) {
        setState(() => _erreur = 'Cette pièce n\'a pas pu être mise en file ($e). '
            'Reprends la photo.');
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  Future<void> _ceder() async {
    final fait = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(
        builder: (_) => TransferProposeScreen(session: widget.session, bien: _bien),
      ),
    );

    if (fait == true && mounted) {
      setState(() => _confirmation =
          'Le transfert est engagé. L\'acheteur a reçu un code ; il a sept jours pour '
          'confirmer, sinon le bien te revient.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: Text(_bien.label, style: const TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                _bien.identifier,
                style: const TextStyle(
                  fontSize: 21,
                  letterSpacing: 1.2,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 14),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: <Widget>[
                  PastilleStatut(_bien.lifeStatus),
                  PastilleStatut(_bien.trustLevel),
                ],
              ),
              const SizedBox(height: 18),
              if (_confirmation != null) ...<Widget>[
                _Confirmation(_confirmation!),
                const SizedBox(height: 14),
              ],
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 14),
              ],
              if (_enCours) const EnCours() else ..._actions(),
              const SizedBox(height: 26),
              const Divider(color: Djassa.encre, thickness: 3),
              const SizedBox(height: 16),
              const Text(
                'Référence à partager',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              SelectableText(
                _bien.publicRef,
                style: const TextStyle(fontSize: 19, letterSpacing: 1.2),
              ),
              const SizedBox(height: 8),
              // LA RÉFÉRENCE OPAQUE, JAMAIS LE NUMÉRO : le lien qu'un vendeur
              // partage ne doit rien laisser deviner du numéro réel, sans quoi
              // chaque partage publierait une ligne de l'annuaire des biens
              // enregistrés.
              const Text(
                'Donne cette référence à un acheteur : il verra le statut du bien sans '
                'apprendre ton identité, et sans avoir besoin du numéro de châssis.',
                style: TextStyle(color: Djassa.sourdine, height: 1.5),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _actions() {
    // Un bien gelé — transfert engagé, litige en cours — n'accepte plus rien
    // d'ordinaire : la seule chose honnête est de dire pourquoi.
    if (_bien.isFrozen) {
      return <Widget>[
        RienEncore(
          titre: 'Aucune action possible pour l\'instant',
          explication: _bien.lifeStatus.code == 'V-LIT'
              ? 'La propriété de ce bien est contestée. Tant qu\'un agent n\'a pas '
                  'tranché, il ne peut être ni cédé, ni déclaré hors d\'usage.'
              : 'Un transfert est en cours sur ce bien. Il aboutira à la confirmation '
                  'de l\'acheteur, ou reviendra vers toi à son expiration.',
        ),
      ];
    }

    if (_bien.isStolen) {
      return <Widget>[
        FilledButton(
          onPressed: _leverVol,
          child: const Text('J\'ai retrouvé ce bien'),
        ),
        const SizedBox(height: 12),
        const Text(
          'Tant que l\'alerte est active, ce bien est invendable. Ne la lève que si tu '
          'l\'as réellement récupéré.',
          style: TextStyle(color: Djassa.sourdine, height: 1.5),
        ),
      ];
    }

    return <Widget>[
      // EN TÊTE, ET EN ROUGE. Le geste le plus urgent du produit ne se cherche
      // pas dans un menu.
      FilledButton(
        style: FilledButton.styleFrom(
          backgroundColor: Djassa.alerte,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(Djassa.cible),
          textStyle: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
          shape: const RoundedRectangleBorder(
            side: BorderSide(color: Djassa.encre, width: 3),
            borderRadius: BorderRadius.all(Radius.circular(12)),
          ),
        ),
        onPressed: _declarerVol,
        child: const Text('Déclarer volé'),
      ),
      const SizedBox(height: 12),
      OutlinedButton(
        style: _contour,
        onPressed: _ceder,
        child: const Text('Céder ce bien'),
      ),
      const SizedBox(height: 12),
      TextButton(
        onPressed: _finDeVie,
        child: const Text('Déclarer hors d\'usage'),
      ),
      const SizedBox(height: 22),
      const Divider(color: Djassa.encre, thickness: 3),
      const SizedBox(height: 16),
      const Text(
        'Renforcer la confiance',
        style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      // DIT CE QUE ÇA CHANGE, sinon personne ne le fait. Verser une pièce est un
      // effort ; l'acheteur d'en face, lui, verra la différence.
      const Text(
        'Ajoute ta carte grise ou ta facture : le bien passe de « Déclaré » à '
        '« Documenté », et un acheteur qui vérifie le numéro voit la différence.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 12),
      OutlinedButton(
        style: _contour,
        onPressed: () => _ajouterJustificatif('registration_card', 'Photo de la carte grise'),
        child: const Text('Ajouter la carte grise'),
      ),
      const SizedBox(height: 10),
      OutlinedButton(
        style: _contour,
        onPressed: () => _ajouterJustificatif('invoice', 'Photo de la facture'),
        child: const Text('Ajouter une facture'),
      ),
      const SizedBox(height: 10),
      OutlinedButton(
        style: _contour,
        onPressed: () => _ajouterJustificatif('photo', 'Photo du bien'),
        child: const Text('Ajouter une photo du bien'),
      ),
      const SizedBox(height: 10),
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
          widget.session.envois.pending.isEmpty
              ? 'Envois en attente'
              : 'Envois en attente (${widget.session.envois.pending.length})',
        ),
      ),
    ];
  }
}

/// Bouton secondaire, à la même hauteur de cible que le principal : on saisit
/// debout, parfois avec des gants de mécanicien.
final ButtonStyle _contour = OutlinedButton.styleFrom(
  foregroundColor: Djassa.encre,
  minimumSize: const Size.fromHeight(Djassa.cible),
  textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
  side: const BorderSide(color: Djassa.encre, width: 3),
  shape: const RoundedRectangleBorder(
    borderRadius: BorderRadius.all(Radius.circular(12)),
  ),
);

class _Confirmation extends StatelessWidget {
  const _Confirmation(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.accent, width: 3),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: const TextStyle(fontWeight: FontWeight.w700, height: 1.4),
      ),
    );
  }
}
