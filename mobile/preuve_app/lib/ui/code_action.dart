import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import 'theme.dart';

/// Demande un code à usage unique pour un geste engageant, et le rend.
///
/// LA FRICTION EST ICI PARCE QUE LE RISQUE EST ICI (CT-06). Consulter ne
/// demande rien ; déclarer un vol rend un bien invendable dans la seconde, et
/// céder la propriété la fait changer de mains. Un téléphone déverrouillé
/// laissé sur une table ne doit pas suffire à l'un ni à l'autre.
///
/// LE MOTIF ACCOMPAGNE LA DEMANDE, et ce n'est pas décoratif : un code obtenu
/// pour se connecter ne doit pas pouvoir autoriser un transfert. Le serveur le
/// vérifie ; encore faut-il le lui dire.
///
/// Rend `null` si la personne renonce — un abandon n'est pas une erreur, et ne
/// doit rien afficher.
Future<String?> demanderCodeAction(
  BuildContext context, {
  required PreuveSession session,
  required OtpPurpose motif,
  required String titre,
  required String consequence,
}) async {
  final compte = session.compte;

  if (compte == null) {
    return null;
  }

  final messager = ScaffoldMessenger.of(context);

  try {
    await session.auth.requestCode(compte.phone, motif);
  } on PreuveException catch (e) {
    messager.showSnackBar(SnackBar(content: Text(e.message)));

    return null;
  }

  if (!context.mounted) {
    return null;
  }

  return showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Djassa.creme,
    builder: (BuildContext feuille) => _FeuilleCode(
      titre: titre,
      consequence: consequence,
      telephone: compte.phone,
    ),
  );
}

class _FeuilleCode extends StatefulWidget {
  const _FeuilleCode({
    required this.titre,
    required this.consequence,
    required this.telephone,
  });

  final String titre;
  final String consequence;
  final String telephone;

  @override
  State<_FeuilleCode> createState() => _FeuilleCodeState();
}

class _FeuilleCodeState extends State<_FeuilleCode> {
  final TextEditingController _code = TextEditingController();

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      // Remonte la feuille au-dessus du clavier : sans cela, le champ à
      // remplir se retrouve caché par ce qui sert à le remplir.
      padding: EdgeInsets.fromLTRB(
        20,
        20,
        20,
        20 + MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            widget.titre,
            style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 8),
          // CE QUE LE GESTE VA FAIRE, dit avant de le faire. Une confirmation
          // qui ne rappelle pas sa conséquence ne confirme rien.
          Text(
            widget.consequence,
            style: const TextStyle(color: Djassa.sourdine, height: 1.5),
          ),
          const SizedBox(height: 16),
          Text(
            'Un code vient d\'être envoyé au ${widget.telephone}.',
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _code,
            autofocus: true,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 26, letterSpacing: 8),
            decoration: const InputDecoration(labelText: 'Code reçu'),
          ),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(_code.text.trim()),
            child: const Text('Valider'),
          ),
          const SizedBox(height: 6),
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Annuler'),
          ),
        ],
      ),
    );
  }
}

/// Traduit un refus du serveur en phrase utile, et rien de plus.
///
/// LE MESSAGE DU SERVEUR PRIME sur le nôtre quand il en donne un : il connaît
/// la raison exacte du refus, l'application ne fait que la relayer. Ce qui est
/// ajouté ici, c'est la CONDUITE — ce qu'il reste à faire — pour les refus dont
/// le texte seul ne le dit pas.
String messageDeRefus(PreuveException erreur) {
  return switch (erreur) {
    PaymentRequired() => '${erreur.message} Le règlement se fait depuis '
        'l\'écran de paiement ; ta demande, elle, reste valable.',
    UpgradeRequired() => '${erreur.message} La consultation d\'un bien, elle, '
        'continue de fonctionner.',
    ReadOnlyPlatform() => '${erreur.message} Réessaie dans un moment : ce que '
        'tu as déjà enregistré reste protégé.',
    NotAuthenticated() => 'Ta session a expiré. Reconnecte-toi pour continuer.',
    _ => erreur.message,
  };
}
