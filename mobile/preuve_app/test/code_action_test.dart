import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/ui/code_action.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// La demande de code d'un geste engageant (CT-06).
///
/// DEUX DÉFAUTS SE CACHAIENT AU MÊME ENDROIT, et aucun ne se voyait sans
/// quitter l'application :
///
/// 1. La destination était lue dans `phone`, en dur. Depuis qu'un compte
///    s'ouvre par adresse, ce champ peut être vide : son titulaire demandait un
///    code pour rien, le serveur refusait, et il ne pouvait accomplir AUCUN
///    geste engageant — pas même déclarer le vol de son bien.
///
/// 2. Après un règlement, l'application redemandait un code alors que le
///    serveur venait d'en émettre un au moment du webhook. Le délai de soixante
///    secondes entre deux envois répondait « trop de demandes » — un refus
///    juste après un paiement, ce qui se lit comme une escroquerie.
void main() {
  Future<FauxTransport> ouvrir(
    WidgetTester tester, {
    required Account compte,
    bool dejaEnvoye = false,
  }) async {
    final transport = FauxTransport()..enfile(<String, Object?>{'expires_in': 300});
    final PreuveSession session = fauxSession(transport)..compte = compte;

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: Scaffold(
        body: Builder(
          builder: (BuildContext context) => Center(
            child: TextButton(
              onPressed: () => demanderCodeAction(
                context,
                session: session,
                motif: OtpPurpose.sensitiveAction,
                titre: 'Déclarer ce bien volé',
                consequence: 'Le bien devient invendable immédiatement.',
                dejaEnvoye: dejaEnvoye,
              ),
              child: const Text('déclencher'),
            ),
          ),
        ),
      ),
    ));

    await tester.tap(find.text('déclencher'));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('DEMANDE LE CODE À L\'ADRESSE QUAND LE COMPTE N\'A PAS DE NUMÉRO',
      (WidgetTester tester) async {
    const Account parAdresse = Account(id: 7, phone: '', email: 'awa@exemple.ci');

    final transport = await ouvrir(tester, compte: parAdresse);

    expect(transport.dernierCorps['identifier'], equals('awa@exemple.ci'));
    expect(find.textContaining('awa@exemple.ci'), findsOneWidget);
  });

  testWidgets('PRÉFÈRE LE NUMÉRO QUAND LE COMPTE A LES DEUX', (WidgetTester tester) async {
    const Account lesDeux = Account(id: 8, phone: '+2250700111222', email: 'awa@exemple.ci');

    final transport = await ouvrir(tester, compte: lesDeux);

    expect(transport.dernierCorps['identifier'], equals('+2250700111222'));
  });

  testWidgets('NE REDEMANDE PAS DE CODE QUAND IL VIENT D\'ÊTRE ENVOYÉ',
      (WidgetTester tester) async {
    // LE test du parcours payant. Le jour où il tombe, celui qui vient de payer
    // reçoit « trop de demandes » au lieu du champ de saisie.
    const Account compte = Account(id: 9, phone: '+2250700111222');

    final transport = await ouvrir(tester, compte: compte, dejaEnvoye: true);

    expect(transport.appels, isEmpty);
    expect(find.textContaining('dès la confirmation du paiement'), findsOneWidget);
  });

  testWidgets('OUVRE QUAND MÊME LA SAISIE APRÈS UN PAIEMENT', (WidgetTester tester) async {
    // Sauter la demande ne doit pas sauter la feuille : sans champ, le code
    // reçu ne sert à rien.
    const Account compte = Account(id: 9, phone: '+2250700111222');

    await ouvrir(tester, compte: compte, dejaEnvoye: true);

    expect(find.text('Valider'), findsOneWidget);
  });

  testWidgets('LE DIT PLUTÔT QUE D\'ENVOYER DU VIDE, SI AUCUNE COORDONNÉE',
      (WidgetTester tester) async {
    const Account sansRien = Account(id: 10, phone: '');

    final transport = await ouvrir(tester, compte: sansRien);

    expect(transport.appels, isEmpty);
    expect(find.textContaining('ni numéro ni adresse'), findsOneWidget);
  });
}
