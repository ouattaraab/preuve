import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/screens/my_assets_screen.dart';
import 'package:preuve_app/screens/shell_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// L'ÉCRAN NOIR À LA DÉCONNEXION (06/08/2026).
///
/// `MyAssetsScreen` vit de deux façons : empilé depuis l'accueil, et comme
/// TROISIÈME ONGLET du shell, où il n'est empilé sur rien. Le `Navigator.pop()`
/// de la déconnexion y retirait la dernière route de la pile, et il ne restait
/// plus rien à afficher.
void main() {
  Map<String, Object?> inventaireVide() => <String, Object?>{
        'assets': <Object?>[],
        'pagination': <String, Object?>{'page': 1, 'per_page': 20, 'total': 0, 'last_page': 1},
        'quota': <String, Object?>{'free_quota': 3, 'used': 0, 'remaining': 3, 'over_quota': false},
      };

  PreuveSession sessionOuverte(FauxTransport transport) {
    return fauxSession(transport)
      ..compte = const Account(id: 1, phone: '+2250700000001', fullName: 'Awa');
  }

  testWidgets('LA DÉCONNEXION DEPUIS L\'ONGLET NE LAISSE PAS D\'ÉCRAN NOIR',
      (WidgetTester tester) async {
    final transport = FauxTransport()
      ..enfile(inventaireVide())
      ..enfile(<String, Object?>{'transfers': <Object?>[]})
      ..enfile(<String, Object?>{'unread_count': 0, 'notifications': <Object?>[]})
      ..enfile(<String, Object?>{});

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: MyAssetsScreen(
        session: sessionOuverte(transport),
        // Ce que passe le shell : revenir à la consultation.
        onDeconnexion: () {},
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Déconnexion'));
    await tester.pumpAndSettle();

    // L'ÉCRAN EXISTE ENCORE. Un `pop()` ici viderait la pile de navigation, et
    // Flutter n'affiche alors plus rien du tout.
    expect(find.byType(MyAssetsScreen), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('LE SHELL REVIENT À LA CONSULTATION', (WidgetTester tester) async {
    // C'est le seul écran qui ait du sens une fois déconnecté : il ne demande
    // aucun compte.
    final transport = FauxTransport()
      ..enfile(inventaireVide())
      ..enfile(<String, Object?>{'transfers': <Object?>[]})
      ..enfile(<String, Object?>{'unread_count': 0, 'notifications': <Object?>[]})
      ..enfile(<String, Object?>{});

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: ShellScreen(session: sessionOuverte(transport)),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Mes biens'));
    await tester.pumpAndSettle();
    expect(find.byType(MyAssetsScreen), findsOneWidget);

    await tester.tap(find.text('Déconnexion'));
    await tester.pumpAndSettle();

    expect(find.text('JE VÉRIFIE'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
