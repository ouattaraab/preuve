import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/screens/lookup_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_app/ui/widgets.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// « Je scanne » sur l'accueil : le raccourci de celui qui n'a PAS de compte.
///
/// Recopier dix-sept caractères de châssis debout devant un vendeur est le
/// premier motif de « bien introuvable ». Le bouton était grisé depuis le
/// début : le raccourci existait, mais pas pour l'acheteur anonyme — celui que
/// le produit sert d'abord.
///
/// IL N'APPARAÎT QUE SI LE SERVEUR ANNONCE POUVOIR LIRE. Sans fournisseur
/// d'extraction, il ferait prendre une photo, l'enverrait, et rendrait un échec
/// que l'utilisateur attribuerait à sa photo — il recommencerait.
void main() {
  Future<void> ouvrir(WidgetTester tester, {AppRelease? annonce}) async {
    final PreuveSession session = fauxSession(FauxTransport())..annonce = annonce;

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: LookupScreen(session: session),
    ));
    await tester.pumpAndSettle();
  }

  Finder leBouton() => find.text('Je scanne');

  testWidgets('OFFERT ET ACTIF quand le serveur sait lire', (WidgetTester tester) async {
    await ouvrir(tester, annonce: const AppRelease(scanAvailable: true));

    expect(leBouton(), findsOneWidget);

    final BoutonRelief bouton = tester.widget<BoutonRelief>(
      find.ancestor(of: leBouton(), matching: find.byType(BoutonRelief)),
    );

    expect(bouton.onPressed, isNotNull,
        reason: 'Un bouton grisé sans explication est ce que ce projet s\'interdit.');
  });

  testWidgets('ABSENT quand aucun fournisseur n\'est branché', (WidgetTester tester) async {
    await ouvrir(tester, annonce: const AppRelease(scanAvailable: false));

    expect(leBouton(), findsNothing);
  });

  testWidgets('ABSENT tant que le serveur n\'a rien annoncé', (WidgetTester tester) async {
    // Réseau absent au lancement : on reste prudent plutôt que d'annoncer une
    // capacité qu'on n'a pas vérifiée.
    await ouvrir(tester);

    expect(leBouton(), findsNothing);
  });

  testWidgets('LA VÉRIFICATION RESTE OFFERTE dans tous les cas',
      (WidgetTester tester) async {
    // Le raccourci peut manquer ; la promesse du produit, jamais.
    await ouvrir(tester, annonce: const AppRelease(scanAvailable: false));

    expect(find.text('JE VÉRIFIE'), findsOneWidget);
    expect(find.text('Gratuit · Sans compte'), findsOneWidget);
  });
}
