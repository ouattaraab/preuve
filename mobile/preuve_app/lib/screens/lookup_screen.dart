import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/fichiers.dart';
import '../data/ocr.dart';
import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/photo_choice.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';
import 'login_screen.dart';
import 'my_assets_screen.dart';
import 'notifications_screen.dart';
import 'stolen_screen.dart';
import 'verdict_screen.dart';

/// Écran d'accueil : un champ, un bouton.
///
/// DEUX INTERACTIONS, PAS TROIS (CT-01). Aucun choix de type de bien à faire
/// d'abord : la normalisation reconnaît seule un châssis, une plaque ou un
/// IMEI, et demander à l'acheteur de trancher lui ferait porter une erreur qui
/// n'est pas la sienne.
///
/// AUCUN COMPTE N'EST DEMANDÉ ICI, ni pour la première consultation ni pour la
/// centième (règle métier absolue n° 1). La pastille « Gratuit · Sans compte »
/// le dit à l'écran, parce que c'est la première question que se pose quelqu'un
/// à qui l'on propose une moto sur un parking.
class LookupScreen extends StatefulWidget {
  const LookupScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<LookupScreen> createState() => _LookupScreenState();
}

class _LookupScreenState extends State<LookupScreen> {
  final TextEditingController _controller = TextEditingController();
  bool _enCours = false;
  bool _scanEnCours = false;
  String? _erreurLocale;

  /// Le moteur de lecture, gardé le temps de l'écran : le charger à chaque
  /// scan coûterait plusieurs centaines de millisecondes à chaque fois.
  final LecteurEmbarque _lecteur = LecteurEmbarque();

  /// Ce que la lecture a donné, dit à l'utilisateur.
  ///
  /// TOUJOURS AFFICHÉ, RÉUSSITE COMME ÉCHEC. Un scan qui ne remplit rien sans
  /// rien dire laisse croire à une panne ; et un scan réussi doit demander une
  /// relecture, parce qu'un numéro mal lu est PIRE qu'un numéro non lu —
  /// personne ne recompte dix-sept caractères qu'une machine a proposés.
  String? _messageScan;

  @override
  void dispose() {
    _controller.dispose();
    // Le modèle occupe plusieurs mégaoctets de mémoire vive : le laisser
    // ouvert derrière soi sur un téléphone d'entrée de gamme se paie.
    unawaited(_lecteur.fermer());
    super.dispose();
  }

  Future<void> _verifier() async {
    final saisie = _controller.text;
    final controle = IdentifierNormalizer.check(saisie);

    // CONTRÔLE LOCAL D'ABORD : un aller-retour en 3G coûte plusieurs secondes,
    // et refuser sur place un châssis mal recopié évite d'attendre pour
    // apprendre ce que le clavier savait déjà.
    if (controle != LocalCheck.acceptable) {
      setState(() => _erreurLocale = _messageLocal(controle));

      return;
    }

    setState(() {
      _erreurLocale = null;
      _enCours = true;
    });

    try {
      final resultat = await widget.session.lookups.check(saisie);

      if (!mounted) {
        return;
      }

      await Navigator.of(context).push<void>(
        MaterialPageRoute<void>(
          builder: (_) => VerdictScreen(
            resultat: resultat,
            saisie: saisie,
            session: widget.session,
          ),
        ),
      );
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreurLocale = e.message);
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  /// Lit le numéro sur une carte grise, SANS COMPTE.
  ///
  /// C'EST LE RACCOURCI, JAMAIS LE CHEMIN. Le numéro lu atterrit dans le champ
  /// et rien de plus : c'est l'utilisateur qui lance la vérification, après
  /// avoir relu. Une lecture qui déclencherait elle-même la consultation
  /// ferait afficher un verdict sur un numéro que personne n'a validé — et
  /// « pas enregistré » sur un caractère de travers ferait renoncer à un achat
  /// parfaitement sain, ou l'inverse.
  Future<void> _scanner() async {
    final PhotoLocale? photo = await choisirPhoto(context, titre: 'Carte grise du bien');

    if (photo == null || !mounted) {
      return;
    }

    setState(() {
      _scanEnCours = true;
      _erreurLocale = null;
      _messageScan = null;
    });

    try {
      // LA PHOTO EST LUE ICI, SUR LE TÉLÉPHONE, et ne part jamais. Une carte
      // grise porte le nom et l'adresse de son propriétaire ; il n'a jamais
      // fallu l'envoyer pour en extraire dix-sept caractères. Seuls les mots
      // partent — quelques centaines d'octets, ce qui change tout en 3G.
      final List<String> mots = await _lecteur.lire(photo.chemin);

      if (!mounted) {
        return;
      }

      if (mots.isEmpty) {
        setState(() => _messageScan =
            'Rien de lisible sur cette photo. Rapproche-toi du numéro, ou tape-le à la main.');

        return;
      }

      // LA SÉLECTION EST FAITE PAR LE SERVEUR, pas ici : le chiffre de contrôle
      // du VIN et l'ordre de priorité sont des règles métier, et embarquées
      // dans l'application elles se périmeraient sur des téléphones qui ne se
      // mettent pas à jour.
      final ScanResult lecture = await widget.session.scans.readWordsForLookup(mots);

      if (!mounted) {
        return;
      }

      setState(() {
        if (lecture.hasIdentifier) {
          _controller.text = lecture.identifier!.toUpperCase();
          _messageScan = 'Numéro lu sur la carte grise. RELIS-LE avant de vérifier : '
              'une machine se trompe, et un caractère de travers change le verdict.';
        } else {
          _messageScan = 'La lecture n\'a rien donné de sûr. Tape le numéro à la main : '
              'mieux vaut le taper que recopier une valeur approximative.';
        }
      });
    } on RateLimited catch (e) {
      // LE RACCOURCI EST PLAFONNÉ, PAS LA VÉRIFICATION. Le scan coûte de
      // l'argent à chaque appel ; taper le numéro n'en coûte aucun, et reste
      // ouvert. L'écran doit le dire, sans quoi le refus paraît fermer le
      // produit entier.
      if (mounted) {
        setState(() => _messageScan = e.message);
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreurLocale = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _scanEnCours = false);
      }
    }
  }

  /// Un message qui dit QUOI FAIRE, pas ce qui est faux.
  ///
  /// « Chiffre de contrôle invalide » n'aide personne au bord d'une route :
  /// ce qu'il faut dire, c'est qu'un caractère a probablement été mal recopié.
  static String _messageLocal(LocalCheck controle) {
    return switch (controle) {
      LocalCheck.tooShort => 'Ce numéro est trop court. Vérifie que tu l\'as saisi en entier.',
      LocalCheck.vinChecksumFailed =>
        'Ce numéro de châssis comporte une erreur. Recompte les 17 caractères : '
            'le 1 et le I, le 0 et le O se confondent facilement.',
      LocalCheck.imeiChecksumFailed =>
        'Cet IMEI comporte une erreur. Compose *#06# sur le téléphone pour le réafficher.',
      LocalCheck.acceptable => '',
    };
  }

  /// Ouvre « Mes biens », en passant par la connexion si nécessaire.
  ///
  /// LA CONNEXION N'EST DEMANDÉE QU'ICI, au moment où elle sert à quelque chose
  /// (CT-06). La placer devant la consultation trahirait la promesse du
  /// produit : vérifier un bien ne demande rien, ni compte, ni trace.
  Future<void> _ouvrirMesBiens() async {
    final session = widget.session;

    if (!session.estConnecte) {
      final compte = await Navigator.of(context).push<Account>(
        MaterialPageRoute<Account>(
          builder: (_) => LoginScreen(session: session),
        ),
      );

      if (compte == null) {
        return;
      }

      session.compte = compte;
    }

    if (!mounted) {
      return;
    }

    await Navigator.of(context).push<void>(
      MaterialPageRoute<void>(builder: (_) => MyAssetsScreen(session: session)),
    );

    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _ouvrirAlertes() async {
    final session = widget.session;

    if (!session.estConnecte) {
      await _ouvrirMesBiens();

      return;
    }

    await Navigator.of(context).push<void>(
      MaterialPageRoute<void>(builder: (_) => NotificationsScreen(session: session)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(22, 28, 22, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              EnteteMarque(
                pastille: 'Gratuit · Sans compte',
                onCloche: _ouvrirAlertes,
              ),
              const SizedBox(height: 34),
              // TROIS LIGNES, ET LE VERBE EN ACCENT. La coupure est voulue : à
              // 46 points, une seule ligne ne tiendrait pas, et laisser le
              // moteur couper mettrait « vérifie ! » n'importe où.
              Text.rich(
                TextSpan(
                  children: <TextSpan>[
                    const TextSpan(text: 'Avant\nd\'acheter,\n'),
                    TextSpan(
                      text: 'vérifie !',
                      style: Djassa.affiche(46, couleur: Djassa.accent, hauteur: 1.02),
                    ),
                  ],
                ),
                style: Djassa.affiche(46, hauteur: 1.02),
              ),
              const SizedBox(height: 10),
              const Text(
                'Moto, voiture, téléphone… tape le numéro, tu sais tout de suite.',
                style: TextStyle(
                  fontFamily: Djassa.texte,
                  fontSize: 17,
                  height: 1.45,
                  color: Djassa.sourdine,
                ),
              ),
              const SizedBox(height: 26),
              ChampRelief(
                controller: _controller,
                indication: 'Plaque, châssis ou IMEI',
                erreur: _erreurLocale,
                majuscules: true,
                onSoumis: (_) => _enCours ? null : _verifier(),
                formateurs: <TextInputFormatter>[
                  // Majuscules dès la frappe : l'utilisateur voit la forme
                  // exacte qui sera comparée au registre.
                  TextInputFormatter.withFunction(
                    (_, TextEditingValue next) => next.copyWith(text: next.text.toUpperCase()),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              BoutonRelief(
                libelle: 'JE VÉRIFIE',
                enCours: _enCours,
                onPressed: _verifier,
              ),
              // LE RACCOURCI DE CELUI QUI N'A PAS DE COMPTE. Recopier dix-sept
              // caractères de châssis debout devant un vendeur est le premier
              // motif de « bien introuvable » — et c'est justement l'acheteur
              // anonyme, celui que le produit sert d'abord, qui n'y avait pas
              // droit.
              //
              // TOUJOURS OFFERT, parce que la lecture se fait SUR L'APPAREIL.
              // Il fut un temps conditionné à une clé de fournisseur côté
              // serveur ; le conditionner encore cacherait une fonction qui
              // marche parfaitement sans elle, et hors ligne.
              const SizedBox(height: 12),
              BoutonRelief(
                libelle: 'Je scanne',
                icone: '▣',
                principal: false,
                enCours: _scanEnCours,
                onPressed: _scanner,
              ),
              if (_messageScan != null) ...<Widget>[
                const SizedBox(height: 12),
                EncadreConfirmation(_messageScan!),
              ],
              const SizedBox(height: 12),
              // LA LISTE DES BIENS VOLÉS, DEPUIS L'ACCUEIL. C'est le second
              // geste utile du produit : celui qui n'a pas de numéro sous les
              // yeux peut quand même reconnaître un bien.
              BoutonRelief(
                libelle: 'Voir les biens volés',
                icone: '⚠️',
                principal: false,
                onPressed: () => Navigator.of(context).push<void>(MaterialPageRoute<void>(
                  builder: (_) => StolenScreen(session: widget.session),
                )),
              ),
              const SizedBox(height: 18),
              const _BandeauCompteur(),
              const SizedBox(height: 22),
              const _Exemples(),
            ],
          ),
        ),
      ),
    );
  }
}

/// Le compteur de vols rendus invendables.
///
/// IL N'EST PAS DÉCORATIF : il répond à la seule question qui décide de
/// l'usage — « est-ce que ça sert à quelque chose ? ». Le chiffre viendra du
/// serveur ; en attendant, l'écran n'en invente aucun et parle de la
/// plateforme, pas d'un total qu'on ne mesure pas encore.
class _BandeauCompteur extends StatelessWidget {
  const _BandeauCompteur();

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
          Text('⚡', style: Djassa.affiche(22, couleur: Djassa.ambre)),
          const SizedBox(width: 12),
          const Expanded(
            child: Text(
              'Une déclaration de vol rend le bien invendable dans la seconde, '
              'partout en Côte d\'Ivoire.',
              style: TextStyle(
                fontFamily: Djassa.texte,
                fontSize: 14,
                fontWeight: FontWeight.w700,
                height: 1.35,
                color: Djassa.creme,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Où trouver le numéro. La maquette propose des exemples à essayer ; ici, ce
/// qui manque vraiment à quelqu'un devant une moto, c'est de savoir OÙ REGARDER.
class _Exemples extends StatelessWidget {
  const _Exemples();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        const Text(
          'OÙ TROUVER LE NUMÉRO',
          style: TextStyle(
            fontFamily: Djassa.texte,
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: Djassa.etiquette,
            letterSpacing: 0.5,
          ),
        ),
        const SizedBox(height: 10),
        const Wrap(
          spacing: 7,
          runSpacing: 7,
          children: <Widget>[
            _Puce('Moto, voiture : sur la carte grise'),
            _Puce('Gravé sur le cadre'),
            _Puce('Téléphone : compose *#06#'),
            _Puce('La plaque marche aussi'),
          ],
        ),
        const SizedBox(height: 22),
        Text('Un numéro inconnu n\'est pas un feu vert', style: Djassa.affiche(20)),
        const SizedBox(height: 8),
        const Text(
          'Si le bien n\'est pas enregistré, cela ne veut pas dire qu\'il est propre : '
          'cela veut dire que personne ne l\'a encore déclaré. Demande au vendeur de '
          'l\'enregistrer devant toi — un vendeur honnête n\'a rien à y perdre.',
          style: TextStyle(
            fontFamily: Djassa.texte,
            height: 1.5,
            fontSize: 15,
            color: Djassa.sourdine,
          ),
        ),
      ],
    );
  }
}

class _Puce extends StatelessWidget {
  const _Puce(this.libelle);

  final String libelle;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minHeight: 40),
      alignment: Alignment.center,
      padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
      decoration: BoxDecoration(
        color: Djassa.creme,
        border: Border.all(color: Djassa.encre, width: 2),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        libelle,
        style: const TextStyle(
          fontFamily: Djassa.texte,
          fontSize: 13,
          fontWeight: FontWeight.w700,
          color: Djassa.encre,
        ),
      ),
    );
  }
}
