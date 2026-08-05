import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/lookup_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_app/ui/widgets.dart';

import 'faux.dart';

/// « Je scanne » sur l'accueil : le raccourci de celui qui n'a PAS de compte.
///
/// Recopier dix-sept caractères de châssis debout devant un vendeur est le
/// premier motif de « bien introuvable ». Le bouton était grisé depuis le
/// début : le raccourci existait, mais pas pour l'acheteur anonyme — celui que
/// le produit sert d'abord.
void main() {
  Future<void> ouvrir(WidgetTester tester, FauxTransport transport) async {
    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: LookupScreen(session: fauxSession(transport)),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('LE BOUTON EST ACTIF, sans aucun compte', (WidgetTester tester) async {
    await ouvrir(tester, FauxTransport());

    final BoutonRelief bouton = tester.widget<BoutonRelief>(
      find.ancestor(of: find.text('Je scanne'), matching: find.byType(BoutonRelief)),
    );

    expect(bouton.onPressed, isNotNull,
        reason: 'Un bouton grisé sans explication est ce que ce projet s\'interdit.');
  });

  testWidgets('la pastille « Sans compte » reste vraie', (WidgetTester tester) async {
    // Si le scan exigeait une connexion, cette pastille mentirait au moment
    // exact où elle compte.
    await ouvrir(tester, FauxTransport());

    expect(find.text('Gratuit · Sans compte'), findsOneWidget);
  });
}
