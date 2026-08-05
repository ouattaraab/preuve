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
/// premier motif de « bien introuvable ». Le bouton fut grisé, puis conditionné
/// à une clé de fournisseur payant. Il ne l'est plus : la lecture se fait SUR
/// L'APPAREIL, donc hors ligne, sans clé, et sans que la photo parte.
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

  testWidgets('OFFERT ET ACTIF, sans aucun compte', (WidgetTester tester) async {
    await ouvrir(tester);

    expect(leBouton(), findsOneWidget);

    final BoutonRelief bouton = tester.widget<BoutonRelief>(
      find.ancestor(of: leBouton(), matching: find.byType(BoutonRelief)),
    );

    expect(bouton.onPressed, isNotNull,
        reason: 'Un bouton grisé sans explication est ce que ce projet s\'interdit.');
  });

  testWidgets('OFFERT MÊME SANS FOURNISSEUR CÔTÉ SERVEUR', (WidgetTester tester) async {
    // C'est tout l'intérêt de la lecture embarquée : elle ne dépend d'aucune
    // clé. Conditionner le bouton à `scan_available` cacherait désormais une
    // fonction qui marche parfaitement sans elle.
    await ouvrir(tester, annonce: const AppRelease(scanAvailable: false));

    expect(leBouton(), findsOneWidget);
  });

  testWidgets('OFFERT MÊME SANS RÉSEAU au lancement', (WidgetTester tester) async {
    // L'annonce du serveur n'est jamais revenue. La lecture, elle, marche hors
    // ligne — c'est justement le cas où elle sert le plus.
    await ouvrir(tester);

    expect(leBouton(), findsOneWidget);
  });

  testWidgets('LA VÉRIFICATION RESTE OFFERTE, et gratuite', (WidgetTester tester) async {
    await ouvrir(tester, annonce: const AppRelease(scanAvailable: false));

    expect(find.text('JE VÉRIFIE'), findsOneWidget);
    expect(find.text('Gratuit · Sans compte'), findsOneWidget);
  });
}
