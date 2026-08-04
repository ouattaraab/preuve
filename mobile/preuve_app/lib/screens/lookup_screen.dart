import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:preuve_core/preuve_core.dart';

import '../ui/theme.dart';
import 'verdict_screen.dart';

/// Écran d'accueil : un champ, un bouton.
///
/// DEUX INTERACTIONS, PAS TROIS (CT-01). Aucun choix de type de bien à faire
/// d'abord : la normalisation reconnaît seule un châssis, une plaque ou un
/// IMEI, et demander à l'acheteur de trancher lui ferait porter une erreur qui
/// n'est pas la sienne.
///
/// AUCUN COMPTE N'EST DEMANDÉ ICI, ni pour la première consultation ni pour la
/// centième (règle métier absolue n° 1). Toute condition ajoutée à cet écran
/// trahirait la promesse du produit.
class LookupScreen extends StatefulWidget {
  const LookupScreen({required this.lookups, super.key});

  final LookupService lookups;

  @override
  State<LookupScreen> createState() => _LookupScreenState();
}

class _LookupScreenState extends State<LookupScreen> {
  final TextEditingController _controller = TextEditingController();
  bool _enCours = false;
  String? _erreurLocale;

  @override
  void dispose() {
    _controller.dispose();
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
      final resultat = await widget.lookups.check(saisie);

      if (!mounted) {
        return;
      }

      await Navigator.of(context).push<void>(
        MaterialPageRoute<void>(
          builder: (_) => VerdictScreen(resultat: resultat, saisie: saisie),
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 40),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const Text(
                'Ce bien est-il volé ?',
                style: TextStyle(fontSize: 34, fontWeight: FontWeight.w800, height: 1.15),
              ),
              const SizedBox(height: 10),
              const Text(
                'Vérifie avant de payer. C\'est gratuit, anonyme, et personne ne saura '
                'que tu as cherché.',
                style: TextStyle(fontSize: 19, color: Djassa.sourdine, height: 1.4),
              ),
              const SizedBox(height: 24),
              TextField(
                controller: _controller,
                autofocus: true,
                textCapitalization: TextCapitalization.characters,
                autocorrect: false,
                enableSuggestions: false,
                textInputAction: TextInputAction.search,
                onSubmitted: (_) => _enCours ? null : _verifier(),
                inputFormatters: <TextInputFormatter>[
                  // Majuscules dès la frappe : l'utilisateur voit la forme
                  // exacte qui sera comparée au registre.
                  TextInputFormatter.withFunction(
                    (_, next) => next.copyWith(text: next.text.toUpperCase()),
                  ),
                ],
                style: const TextStyle(fontSize: 22, letterSpacing: 1.2),
                decoration: const InputDecoration(
                  labelText: 'Numéro de châssis, plaque ou IMEI',
                  hintText: '1M8GDM9AXKP042788',
                ),
              ),
              if (_erreurLocale != null) ...<Widget>[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFDE7E4),
                    border: Border.all(color: Djassa.alerte, width: 3),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    _erreurLocale!,
                    style: const TextStyle(fontWeight: FontWeight.w700, height: 1.4),
                  ),
                ),
              ],
              const SizedBox(height: 14),
              FilledButton(
                onPressed: _enCours ? null : _verifier,
                child: _enCours
                    ? const SizedBox(
                        height: 24,
                        width: 24,
                        child: CircularProgressIndicator(strokeWidth: 3, color: Djassa.encre),
                      )
                    : const Text('Vérifier'),
              ),
              const SizedBox(height: 34),
              const Divider(color: Djassa.encre, thickness: 3),
              const SizedBox(height: 18),
              const Text(
                'Où trouver le numéro ?',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 10),
              const Text(
                '• Voiture, moto — le numéro de châssis (VIN) est sur la carte grise, '
                'et gravé sur le cadre.\n'
                '• Téléphone — compose *#06# pour afficher l\'IMEI.\n'
                '• La plaque d\'immatriculation fonctionne aussi.',
                style: TextStyle(height: 1.6),
              ),
              const SizedBox(height: 24),
              const Text(
                'Un numéro inconnu n\'est pas un feu vert',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              const Text(
                'Si le bien n\'est pas enregistré, cela ne veut pas dire qu\'il est propre : '
                'cela veut dire que personne ne l\'a encore déclaré. Demande au vendeur de '
                'l\'enregistrer devant toi — un vendeur honnête n\'a rien à y perdre.',
                style: TextStyle(height: 1.5, color: Djassa.sourdine),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
