import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/verdict_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// Le plafond de consultations ne doit JAMAIS être une impasse.
///
/// Il protège le registre du balayage automatique, mais il tombe sur le seul
/// parcours que le produit promet gratuit et sans compte, et il tombe FERMÉ.
/// Devant une moto sur un parking, personne n'attendra une heure : sans porte
/// de sortie, la protection punit exactement celui qu'elle sert.
///
/// La clé du défi était JETÉE par `LookupService.check`. L'écran affichait
/// « PATIENTE » sans issue, alors que le serveur venait d'indiquer par où
/// passer.
void main() {
  Future<void> afficher(WidgetTester tester, LookupResult resultat) async {
    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: VerdictScreen(
        resultat: resultat,
        saisie: '1M8GDM9AXKP042788',
        session: fauxSession(FauxTransport()),
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('OFFRE UNE PORTE DE SORTIE quand le serveur propose un défi',
      (WidgetTester tester) async {
    await afficher(
      tester,
      const LookupResult(
        outcome: LookupOutcome.rateLimited,
        message: 'Trop de vérifications dans l\'heure.',
        captchaSiteKey: '0x4AAA',
      ),
    );

    expect(find.text('Continuer la vérification'), findsOneWidget);
    // Et le conseil dit qu'on peut continuer TOUT DE SUITE, pas d'attendre.
    expect(find.textContaining('tout de suite'), findsOneWidget);

    // L'ÉCRAN NE SE CONTREDIT PAS. « PATIENTE » en grand au-dessus d'un bouton
    // qui fait passer immédiatement ferait renoncer quelqu'un qui pouvait
    // passer — le mot est lu bien avant le bouton.
    expect(find.text('PATIENTE'), findsNothing);
    expect(find.text('UN CONTRÔLE'), findsOneWidget);
  });

  testWidgets('N\'OFFRE RIEN quand aucun défi n\'est configuré',
      (WidgetTester tester) async {
    // Un bouton qui n'aboutit à rien est pire que pas de bouton : on
    // recommence, puis on conclut que l'application est cassée.
    await afficher(
      tester,
      const LookupResult(
        outcome: LookupOutcome.rateLimited,
        message: 'Réessaie dans un moment.',
      ),
    );

    expect(find.text('Continuer la vérification'), findsNothing);
    expect(find.textContaining('Attends un moment'), findsOneWidget);
    // Là, l'attente est bien la seule issue : l'écran doit le dire.
    expect(find.text('PATIENTE'), findsOneWidget);
  });

  testWidgets('aucun autre verdict n\'ouvre cette porte', (WidgetTester tester) async {
    for (final LookupOutcome issue in <LookupOutcome>[
      LookupOutcome.unknown,
      LookupOutcome.invalid,
    ]) {
      await afficher(tester, LookupResult(outcome: issue, message: 'Verdict.'));

      expect(find.text('Continuer la vérification'), findsNothing);
    }
  });
}
