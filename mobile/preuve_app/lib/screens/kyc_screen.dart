import 'dart:async';

import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/fichiers.dart';
import '../data/session.dart';
import '../data/vivacite.dart';
import '../ui/code_action.dart';
import '../ui/photo_choice.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Vérification d'identité (ST-0103) : pièce recto, verso, et un selfie.
///
/// LA FRICTION EST MAXIMALE ICI, ET ELLE EST PROPORTIONNÉE (CT-06). Ce n'est
/// jamais exigé pour consulter, ni pour enregistrer un bien, ni pour DÉCLARER UN
/// VOL — une victime n'a pas à prouver qui elle est avant de pouvoir signaler
/// qu'on lui a pris sa moto. Ça l'est pour céder la propriété d'un bien, parce
/// qu'un transfert opéré depuis un compte non vérifié serait le moyen le plus
/// simple de blanchir un bien volé.
///
/// LES TROIS FICHIERS PARTENT ENSEMBLE ET SANS REPRISE : c'est le contrat du
/// serveur. L'écran le dit AVANT de lancer l'envoi, plutôt que de le laisser
/// découvrir à la troisième coupure.
class KycScreen extends StatefulWidget {
  const KycScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<KycScreen> createState() => _KycScreenState();
}

class _KycScreenState extends State<KycScreen> {
  KycStatus? _etat;
  PhotoLocale? _recto;
  PhotoLocale? _verso;
  PhotoLocale? _selfie;

  /// Les deux prises où le visage a tourné. FACULTATIVES : un appareil qui ne
  /// sait pas les produire ne doit pas priver quelqu'un de sa vérification.
  PhotoLocale? _gauche;
  PhotoLocale? _droite;

  /// Guide la prise de vue, et ne certifie rien. Voir `GuideDeVivacite`.
  final GuideDeVivacite _guide = GuideDeVivacite();

  bool _enCours = true;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    unawaited(_guide.fermer());
    super.dispose();
  }

  Future<void> _charger() async {
    try {
      final etat = await widget.session.kyc.status();

      if (mounted) {
        setState(() => _etat = etat);
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

  Future<void> _choisir(String quoi) async {
    final photo = await choisirPhoto(context, titre: quoi);

    if (photo == null || !mounted) {
      return;
    }

    setState(() {
      switch (quoi) {
        case 'Recto de la pièce':
          _recto = photo;
        case 'Verso de la pièce':
          _verso = photo;
        default:
          _selfie = photo;
      }
    });
  }

  /// Une prise de la séquence, vérifiée sur place.
  ///
  /// LE REFUS ARRIVE ICI, PAS APRÈS L'ENVOI. Un dossier refusé pour une photo
  /// où l'on ne voit rien coûte une semaine d'attente à quelqu'un qui n'a rien
  /// fait de mal, et un examen pour rien à l'agent. Le dire tout de suite, avec
  /// ce qu'il faut refaire, est le seul moment où c'est encore gratuit.
  Future<void> _prendreLaSequence(ConsigneVivacite consigne) async {
    final PhotoLocale? photo = await choisirPhoto(context, titre: consigne.titre);

    if (photo == null || !mounted) {
      return;
    }

    final Vivacite issue = await _guide.verifier(photo.chemin, consigne);

    if (!mounted) {
      return;
    }

    if (issue != Vivacite.conforme) {
      setState(() => _erreur = GuideDeVivacite.message(issue, consigne));

      return;
    }

    setState(() {
      _erreur = null;

      switch (consigne) {
        case ConsigneVivacite.gauche:
          _gauche = photo;
        case ConsigneVivacite.droite:
          _droite = photo;
        case ConsigneVivacite.face:
          _selfie = photo;
      }
    });
  }

  Future<void> _envoyer() async {
    final recto = _recto;
    final verso = _verso;
    final selfie = _selfie;

    if (recto == null || verso == null || selfie == null) {
      return;
    }

    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final PhotoLocale? gauche = _gauche;
      final PhotoLocale? droite = _droite;

      final etat = await widget.session.kyc.submit(
        idFront: await enUnSeulMorceau(recto, champ: 'id_front'),
        idBack: await enUnSeulMorceau(verso, champ: 'id_back'),
        selfie: await enUnSeulMorceau(selfie, champ: 'selfie'),
        // AUCUN SCORE NE PART AVEC. Ce que l'appareil calcule sur lui-même
        // n'est pas vérifiable, et un chiffre affiché à un agent lui ferait
        // cesser de regarder. Seules les images partent ; c'est un humain qui
        // tranche, sur trois prises au lieu d'une.
        livenessLeft:
            gauche == null ? null : await enUnSeulMorceau(gauche, champ: 'liveness[left]'),
        livenessRight:
            droite == null ? null : await enUnSeulMorceau(droite, champ: 'liveness[right]'),
      );

      if (mounted) {
        setState(() {
          _etat = etat;
          _recto = null;
          _verso = null;
          _selfie = null;
          _gauche = null;
          _droite = null;
        });
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _erreur = 'Une des images n\'a pas pu être lue ($e). Reprends-la.');
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final etat = _etat;

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Vérifier mon identité', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          children: <Widget>[
            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 16),
            ],
            if (_enCours && etat == null)
              const EnCours()
            else if (etat != null && etat.isVerified)
              const RienEncore(
                titre: 'Identité vérifiée',
                explication:
                    'Tu peux céder un bien et déposer une réclamation. Tes documents ne '
                    'sont visibles que des agents ; ton numéro de pièce n\'est conservé '
                    'que sous forme d\'empreinte, et ne peut être restitué à personne.',
              )
            else if (etat != null && etat.isPending)
              const RienEncore(
                titre: 'Dossier en cours d\'examen',
                explication:
                    'Un agent vérifie la concordance entre ta pièce et ta photo. Tu seras '
                    'prévenu du résultat. Rien d\'autre ne t\'est demandé pour l\'instant.',
              )
            else
              ..._formulaire(etat),
          ],
        ),
      ),
    );
  }

  List<Widget> _formulaire(KycStatus? etat) {
    final complet = _recto != null && _verso != null && _selfie != null;

    return <Widget>[
      if (etat?.rejectionReason != null) ...<Widget>[
        // LE MOTIF DU REFUS EST RENDU. Un dossier refusé sans raison se redépose
        // à l'identique, et se fait refuser à l'identique.
        EncadreErreur('Dossier refusé : ${etat!.rejectionReason}'),
        const SizedBox(height: 18),
      ],
      const Text(
        'Pourquoi cette étape',
        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      const Text(
        'Elle n\'est demandée que pour céder un bien ou déposer une réclamation — '
        'jamais pour consulter, enregistrer, ou déclarer un vol. Un transfert opéré '
        'depuis un compte non vérifié serait le moyen le plus simple de revendre un '
        'bien volé.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 22),
      _Piece(
        titre: 'Recto de la pièce',
        explication: 'CNI, passeport ou permis. Le texte doit être lisible.',
        photo: _recto,
        onChoisir: () => _choisir('Recto de la pièce'),
      ),
      const SizedBox(height: 12),
      _Piece(
        titre: 'Verso de la pièce',
        explication: 'Même document, de l\'autre côté.',
        photo: _verso,
        onChoisir: () => _choisir('Verso de la pièce'),
      ),
      const SizedBox(height: 12),
      _Piece(
        titre: 'Photo de toi, maintenant',
        explication:
            'Une prise de vue, pas un document : c\'est elle qui permet de rapprocher '
            'ton visage de ta pièce.',
        photo: _selfie,
        onChoisir: () => _choisir('Photo de toi'),
      ),
      const SizedBox(height: 22),
      // LA SÉQUENCE, ET CE QU'ELLE VAUT. Elle rend beaucoup plus difficile la
      // fraude la plus simple — brandir une photo imprimée devant l'objectif,
      // qui ne tourne pas la tête. Elle n'arrête pas quelqu'un qui rejoue une
      // vidéo. Le dire est ce qui empêche l'agent de s'en contenter, et c'est
      // écrit noir sur blanc dans sa file de revue.
      const Text(
        'Deux prises de plus',
        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      const Text(
        'Elles montrent à l\'agent que c\'est bien toi, en chair et en os, et pas une '
        'photo tenue devant l\'objectif. Facultatives — mais un dossier qui les porte '
        'se tranche plus vite.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 14),
      _Piece(
        titre: ConsigneVivacite.gauche.titre,
        explication: ConsigneVivacite.gauche.explication,
        photo: _gauche,
        onChoisir: () => _prendreLaSequence(ConsigneVivacite.gauche),
      ),
      const SizedBox(height: 12),
      _Piece(
        titre: ConsigneVivacite.droite.titre,
        explication: ConsigneVivacite.droite.explication,
        photo: _droite,
        onChoisir: () => _prendreLaSequence(ConsigneVivacite.droite),
      ),
      const SizedBox(height: 20),
      FilledButton(
        onPressed: _enCours || !complet ? null : _envoyer,
        child: _enCours
            ? const SizedBox(
                height: 24,
                width: 24,
                child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
              )
            : const Text('Envoyer mon dossier'),
      ),
      const SizedBox(height: 14),
      // DIT LA CONTRAINTE AVANT L'ENVOI. Les trois images partent d'un seul
      // tenant : une coupure oblige à tout renvoyer, et le découvrir trois fois
      // de suite est ce qui fait abandonner.
      const Text(
        'Les trois images partent en une fois. Lance l\'envoi en Wi-Fi ou avec un bon '
        'réseau : contrairement aux justificatifs d\'un bien, celui-ci ne se reprend '
        'pas là où il s\'est arrêté.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
      const SizedBox(height: 14),
      const Text(
        'Ton numéro de pièce n\'est jamais conservé en clair : la plateforme n\'en '
        'garde qu\'une empreinte, et ne peut donc le restituer à personne — pas même '
        'sur réquisition.',
        style: TextStyle(color: Djassa.sourdine, height: 1.5),
      ),
    ];
  }
}

class _Piece extends StatelessWidget {
  const _Piece({
    required this.titre,
    required this.explication,
    required this.photo,
    required this.onChoisir,
  });

  final String titre;
  final String explication;
  final PhotoLocale? photo;
  final VoidCallback onChoisir;

  @override
  Widget build(BuildContext context) {
    final choisie = photo != null;

    return InkWell(
      onTap: onChoisir,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: choisie ? Djassa.accent : Colors.white,
          border: Border.all(color: Djassa.encre, width: 3),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              titre,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            Text(
              choisie ? 'Ajoutée. Touche pour la remplacer.' : explication,
              style: const TextStyle(color: Djassa.sourdine, height: 1.4),
            ),
          ],
        ),
      ),
    );
  }
}
