import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/login_screen.dart';
import 'package:preuve_app/screens/signup_screen.dart';
import 'package:preuve_app/ui/theme.dart';

import 'faux.dart';

/// S'inscrire et se connecter PAR NUMÉRO OU PAR ADRESSE (06/08/2026).
///
/// Aucune passerelle SMS n'est branchée : le code part par courriel. N'accepter
/// qu'un numéro fermait le produit à quiconque n'avait pas déjà un compte —
/// c'est-à-dire à tout le monde.
/// Le champ portant cette indication.
Finder champ(WidgetTester tester, String indication) {
  return find.byWidgetPredicate(
    (Widget w) => w is TextField && w.decoration?.hintText == indication,
  );
}

void main() {
  Future<FauxTransport> ouvrir(WidgetTester tester, Widget ecran) async {
    final transport = FauxTransport();

    await tester.pumpWidget(MaterialApp(theme: Djassa.build(), home: ecran));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('LA CONNEXION ACCEPTE LES DEUX, et le dit', (WidgetTester tester) async {
    final transport = FauxTransport()..enfile(<String, Object?>{'expires_in': 300});

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: LoginScreen(session: fauxSession(transport)),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Téléphone ou e-mail'), findsOneWidget);
    expect(find.textContaining('part par e-mail'), findsOneWidget);
  });

  testWidgets('SE CONNECTE AVEC UNE ADRESSE', (WidgetTester tester) async {
    final transport = FauxTransport()..enfile(<String, Object?>{'expires_in': 300});

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: LoginScreen(session: fauxSession(transport)),
    ));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, 'awa@exemple.ci');
    await tester.tap(find.text('Recevoir mon code'));
    await tester.pumpAndSettle();

    // L'IDENTIFIANT PART SOUS LES DEUX NOMS : `identifier` pour un serveur à
    // jour, `phone` pour un serveur qui ne l'est pas encore.
    expect(transport.dernierCorps['identifier'], equals('awa@exemple.ci'));
    expect(transport.dernierCorps['phone'], equals('awa@exemple.ci'));
  });

  testWidgets('L\'INSCRIPTION REND LE NUMÉRO FACULTATIF, en disant pourquoi',
      (WidgetTester tester) async {
    await ouvrir(tester, SignupScreen(session: fauxSession(FauxTransport())));

    expect(find.textContaining('FACULTATIF'), findsOneWidget);
    // Et elle dit à quoi le numéro servira, plutôt que de le laisser deviner.
    expect(find.textContaining('CÉDER un bien'), findsOneWidget);
  });

  testWidgets('S\'INSCRIT AVEC LA SEULE ADRESSE', (WidgetTester tester) async {
    final transport = FauxTransport()..enfile(<String, Object?>{'expires_in': 300});

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: SignupScreen(session: fauxSession(transport)),
    ));
    await tester.pumpAndSettle();

    // CIBLÉ PAR SON INDICATION et non par son rang : un champ ajouté au
    // formulaire décalerait tous les indices, et le test se mettrait à saisir
    // dans le mauvais endroit sans le dire.
    await tester.enterText(champ(tester, 'awa@exemple.ci'), 'awa@exemple.ci');
    // Le bouton est sous la ligne de flottaison : sans ce défilement, le tap
    // ne porte pas et le test échoue pour une raison sans rapport.
    await tester.ensureVisible(find.text('Je crée mon compte'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Je crée mon compte'));
    await tester.pumpAndSettle();

    // Sans numéro, c'est l'adresse qui identifie le compte.
    expect(transport.dernierCorps['identifier'], equals('awa@exemple.ci'));
    expect(transport.dernierCorps['email'], equals('awa@exemple.ci'));
  });

  testWidgets('REFUSE SANS ADRESSE, avant tout aller-retour', (WidgetTester tester) async {
    // C'est elle qui reçoit le code : partir sans elle ferait attendre pour
    // apprendre ce qu'on savait déjà.
    final transport = FauxTransport();

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: SignupScreen(session: fauxSession(transport)),
    ));
    await tester.pumpAndSettle();

    await tester.enterText(champ(tester, '+225 07 00 00 00 00'), '0700000001');
    await tester.ensureVisible(find.text('Je crée mon compte'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Je crée mon compte'));
    await tester.pumpAndSettle();

    expect(find.textContaining('adresse e-mail est nécessaire'), findsOneWidget);
    expect(transport.appels, isEmpty);
  });
}
