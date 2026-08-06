import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/lookup_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// Le point rouge de la cloche, sur l'accueil (ST-1001).
///
/// TANT QU'AUCUN PUSH N'EST BRANCHÉ, C'EST LE SEUL SIGNAL QUI RESTE. Le centre
/// de notifications existait, la cloche aussi — mais l'accueil ne demandait
/// jamais le décompte de non-lus, et le point rouge ne s'y allumait donc
/// jamais. L'utilisateur devait OUVRIR la cloche pour découvrir qu'on avait
/// consulté son bien ou qu'une cession l'attendait ; un signal qu'il faut aller
/// chercher n'est pas un signal.
void main() {
  Future<FauxTransport> accueil(WidgetTester tester, {required bool connecte, int nonLues = 0}) async {
    final transport = FauxTransport();

    if (connecte) {
      transport.enfile(<String, Object?>{
        'notifications': <Object?>[],
        'unread_count': nonLues,
        'pagination': <String, Object?>{'current_page': 1, 'last_page': 1, 'total': 0},
      });
    }

    final session = fauxSession(transport);

    if (connecte) {
      session.compte = const Account(id: 1, phone: '+2250700000001');
    }

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: LookupScreen(session: session),
    ));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('NE DEMANDE RIEN QUAND PERSONNE N\'EST CONNECTÉ', (WidgetTester tester) async {
    // La consultation est anonyme et le reste : interroger le compte ici
    // ferait un appel authentifié sur l'écran qui promet de n'en exiger aucun.
    final transport = await accueil(tester, connecte: false);

    expect(transport.appels, isEmpty);
  });

  testWidgets('ALLUME LA CLOCHE QUAND IL Y A DU NON-LU', (WidgetTester tester) async {
    await accueil(tester, connecte: true, nonLues: 3);

    expect(find.byKey(const Key('cloche-alerte')), findsOneWidget);
  });

  testWidgets('LAISSE LA CLOCHE ÉTEINTE QUAND TOUT EST LU', (WidgetTester tester) async {
    await accueil(tester, connecte: true);

    expect(find.byKey(const Key('cloche-alerte')), findsNothing);
  });

  testWidgets('N\'AFFICHE AUCUNE ERREUR SI LE DÉCOMPTE ÉCHOUE', (WidgetTester tester) async {
    // Un compteur est un ornement : son échec ne doit jamais abîmer l'écran de
    // consultation, qui est la promesse du produit.
    final transport = FauxTransport()..enfile(const NetworkFailure('Serveur indisponible.'));
    final session = fauxSession(transport)..compte = const Account(id: 1, phone: '+2250700000001');

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: LookupScreen(session: session),
    ));
    await tester.pumpAndSettle();

    expect(find.textContaining('indisponible'), findsNothing);
    expect(find.byKey(const Key('cloche-alerte')), findsNothing);
  });
}
