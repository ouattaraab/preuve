import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import 'theme.dart';

/// Bouton à RELIEF DUR — la signature de la maquette.
///
/// L'ombre est décalée et sans flou. Un `elevation` de Material rendrait un
/// dégradé générique ; ce trait net est ce qui fait reconnaître l'application
/// d'un coup d'œil, et il tient au soleil là où un dégradé disparaît.
///
/// L'ombre disparaît quand le bouton est désactivé : un relief sur une cible
/// qui ne répond pas promet une action qui n'arrivera pas.
class BoutonRelief extends StatelessWidget {
  const BoutonRelief({
    required this.libelle,
    required this.onPressed,
    this.principal = true,
    this.couleurFond,
    this.couleurTexte,
    this.enCours = false,
    this.icone,
    super.key,
  });

  final String libelle;
  final VoidCallback? onPressed;

  /// Principal : accent sur encre, 64 dp. Secondaire : blanc, 56 dp.
  final bool principal;

  final Color? couleurFond;
  final Color? couleurTexte;
  final bool enCours;

  /// Un caractère devant le libellé (« ▣ Je scanne »), comme la maquette.
  final String? icone;

  @override
  Widget build(BuildContext context) {
    final actif = onPressed != null && !enCours;
    final fond = couleurFond ?? (principal ? Djassa.accent : Colors.white);
    final texte = couleurTexte ?? (principal ? Djassa.creme : Djassa.encre);

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(Djassa.rayon),
        boxShadow: actif ? Djassa.relief() : null,
      ),
      child: FilledButton(
        style: FilledButton.styleFrom(
          backgroundColor: fond,
          foregroundColor: texte,
          disabledBackgroundColor: fond,
          disabledForegroundColor: texte,
          minimumSize: Size.fromHeight(
            principal ? Djassa.cible : Djassa.cibleSecondaire,
          ),
          textStyle: TextStyle(
            fontFamily: Djassa.titre,
            fontSize: principal ? 22 : 17,
            fontWeight: FontWeight.w800,
          ),
          shape: const RoundedRectangleBorder(
            side: BorderSide(color: Djassa.encre, width: Djassa.trait),
            borderRadius: BorderRadius.all(Radius.circular(Djassa.rayon)),
          ),
        ),
        onPressed: actif ? onPressed : null,
        child: enCours
            ? SizedBox(
                height: 24,
                width: 24,
                child: CircularProgressIndicator(strokeWidth: 3, color: texte),
              )
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (icone != null) ...<Widget>[
                    Text(icone!, style: const TextStyle(fontSize: 18)),
                    const SizedBox(width: 10),
                  ],
                  Flexible(child: Text(libelle, textAlign: TextAlign.center)),
                ],
              ),
      ),
    );
  }
}

/// Champ de saisie à relief, comme la maquette.
class ChampRelief extends StatelessWidget {
  const ChampRelief({
    required this.controller,
    required this.indication,
    this.libelle,
    this.erreur,
    this.clavier,
    this.majuscules = false,
    this.autofocus = false,
    this.tailleTexte = 19,
    this.onSoumis,
    this.formateurs,
    super.key,
  });

  final TextEditingController controller;
  final String indication;
  final String? libelle;
  final String? erreur;
  final TextInputType? clavier;
  final bool majuscules;
  final bool autofocus;
  final double tailleTexte;
  final ValueChanged<String>? onSoumis;
  final List<TextInputFormatter>? formateurs;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (libelle != null) ...<Widget>[
          Text(
            libelle!,
            style: const TextStyle(
              fontFamily: Djassa.texte,
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: Djassa.etiquette,
            ),
          ),
          const SizedBox(height: 8),
        ],
        Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(Djassa.rayon),
            boxShadow: Djassa.relief(),
          ),
          child: TextField(
            controller: controller,
            autofocus: autofocus,
            keyboardType: clavier,
            textCapitalization:
                majuscules ? TextCapitalization.characters : TextCapitalization.sentences,
            autocorrect: !majuscules,
            enableSuggestions: !majuscules,
            onSubmitted: onSoumis,
            inputFormatters: formateurs,
            style: TextStyle(
              fontFamily: Djassa.texte,
              fontSize: tailleTexte,
              fontWeight: FontWeight.w700,
              color: Djassa.encre,
            ),
            decoration: InputDecoration(hintText: indication),
          ),
        ),
        if (erreur != null) ...<Widget>[
          const SizedBox(height: 10),
          EncadreErreur(erreur!),
        ],
      ],
    );
  }
}

/// Un message d'erreur qui se voit à deux mètres, en plein soleil.
///
/// PARTAGÉ PARCE QUE LA FORME EST UNE PROMESSE : un refus qui change d'aspect
/// d'un écran à l'autre finit par ne plus être lu comme un refus.
class EncadreErreur extends StatelessWidget {
  const EncadreErreur(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFFDE7E4),
        border: Border.all(color: Djassa.alerte, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Text(
        message,
        style: const TextStyle(
          fontFamily: Djassa.texte,
          fontWeight: FontWeight.w700,
          height: 1.4,
          color: Djassa.encre,
        ),
      ),
    );
  }
}

/// Bandeau de confirmation, même forme que l'erreur mais en accent.
class EncadreConfirmation extends StatelessWidget {
  const EncadreConfirmation(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Djassa.accent, width: Djassa.trait),
        borderRadius: BorderRadius.circular(Djassa.rayon),
      ),
      child: Text(
        message,
        style: const TextStyle(
          fontFamily: Djassa.texte,
          fontWeight: FontWeight.w700,
          height: 1.4,
          color: Djassa.encre,
        ),
      ),
    );
  }
}

/// En-tête de la maquette : le mot-marque, une pastille, la cloche.
class EnteteMarque extends StatelessWidget {
  const EnteteMarque({
    required this.onCloche,
    this.pastille,
    this.alerte = false,
    this.motMarque = true,
    super.key,
  });

  /// Le mot-marque n'apparaît que sur l'accueil : ailleurs, la page a déjà son
  /// titre, et le répéter volerait la place de ce qu'on est venu lire.
  final bool motMarque;

  final VoidCallback onCloche;

  /// « Gratuit · Sans compte » sur l'accueil. Rien ailleurs.
  final String? pastille;

  /// Point rouge sur la cloche : il y a quelque chose à lire.
  final bool alerte;

  @override
  Widget build(BuildContext context) {
    if (!motMarque) {
      return _Cloche(onTap: onCloche, alerte: alerte);
    }

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: <Widget>[
        // Le point orange après « Preuve » est la marque. Il ne s'omet pas.
        Text.rich(
          TextSpan(
            children: <TextSpan>[
              const TextSpan(text: 'Preuve'),
              TextSpan(
                text: '.',
                style: Djassa.affiche(24, couleur: Djassa.accent),
              ),
            ],
          ),
          style: Djassa.affiche(24),
        ),
        Row(
          children: <Widget>[
            if (pastille != null) ...<Widget>[
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: Djassa.encre,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  pastille!,
                  style: const TextStyle(
                    fontFamily: Djassa.texte,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: Djassa.creme,
                  ),
                ),
              ),
              const SizedBox(width: 8),
            ],
            _Cloche(onTap: onCloche, alerte: alerte),
          ],
        ),
      ],
    );
  }
}

class _Cloche extends StatelessWidget {
  const _Cloche({required this.onTap, required this.alerte});

  final VoidCallback onTap;
  final bool alerte;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      customBorder: const CircleBorder(),
      child: Stack(
        children: <Widget>[
          Container(
            width: 44,
            height: 44,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(color: Djassa.encre, width: 2),
            ),
            child: const Text('🔔', style: TextStyle(fontSize: 18)),
          ),
          if (alerte)
            Positioned(
              top: 2,
              right: 2,
              child: Container(
                width: 11,
                height: 11,
                decoration: BoxDecoration(
                  color: Djassa.alerte,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 2),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Barre supérieure des écrans secondaires : retour et titre.
class BarrePreuve extends StatelessWidget implements PreferredSizeWidget {
  const BarrePreuve({required this.titre, this.actions, super.key});

  final String titre;
  final List<Widget>? actions;

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context) {
    return AppBar(
      backgroundColor: Djassa.creme,
      surfaceTintColor: Djassa.creme,
      elevation: 0,
      centerTitle: true,
      iconTheme: const IconThemeData(color: Djassa.encre),
      title: Text(titre, style: Djassa.affiche(26)),
      actions: actions,
    );
  }
}

/// Pastille d'un statut, telle que le SERVEUR veut qu'il soit montré.
///
/// LE LIBELLÉ ET LA COULEUR NE SONT JAMAIS DÉCIDÉS ICI (CT-04). Une table
/// locale se périmerait au premier statut ajouté côté serveur, sur des
/// téléphones qui ne se mettent pas à jour — et l'écran afficherait alors un
/// statut inventé sur un bien réel.
class PastilleStatut extends StatelessWidget {
  const PastilleStatut(this.statut, {super.key});

  final StatusView statut;

  @override
  Widget build(BuildContext context) {
    final couleur = Djassa.depuisServeur(statut.color);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: couleur,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: Djassa.encre, width: 2),
      ),
      child: Text(
        // Le libellé, jamais le code : « Volé déclaré », jamais « V-VOL ».
        statut.label,
        style: TextStyle(
          fontFamily: Djassa.texte,
          color: Djassa.surFond(couleur),
          fontWeight: FontWeight.w700,
          fontSize: 14,
        ),
      ),
    );
  }
}

/// Bandeau d'attente, plein largeur.
class EnCours extends StatelessWidget {
  const EnCours({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(vertical: 40),
      child: Center(
        child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
      ),
    );
  }
}

/// Un écran vide qui EXPLIQUE, plutôt qu'une page blanche.
class RienEncore extends StatelessWidget {
  const RienEncore({required this.titre, required this.explication, super.key});

  final String titre;
  final String explication;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(titre, style: Djassa.affiche(24)),
          const SizedBox(height: 8),
          Text(
            explication,
            style: const TextStyle(
              fontFamily: Djassa.texte,
              color: Djassa.sourdine,
              height: 1.5,
              fontSize: 16,
            ),
          ),
        ],
      ),
    );
  }
}
