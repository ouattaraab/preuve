import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import 'theme.dart';

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
        border: Border.all(color: Djassa.alerte, width: 3),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: const TextStyle(fontWeight: FontWeight.w700, height: 1.4),
      ),
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
        // Fond neutre plutôt qu'une teinte dérivée : la couleur du serveur est
        // inconnue à l'avance, et l'éclaircir par transparence donnerait un
        // contraste imprévisible — parfois illisible en plein soleil, ce qui
        // est exactement la condition d'usage de cette application.
        color: Colors.white,
        border: Border.all(color: couleur, width: 2),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        // Le libellé, jamais le code : « Volé déclaré », jamais « V-VOL ».
        statut.label,
        style: TextStyle(color: couleur, fontWeight: FontWeight.w800, fontSize: 15),
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
      padding: const EdgeInsets.symmetric(vertical: 40, horizontal: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            titre,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 8),
          Text(
            explication,
            style: const TextStyle(color: Djassa.sourdine, height: 1.5),
          ),
        ],
      ),
    );
  }
}
