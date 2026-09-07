import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/screens/asset_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// APRÈS UNE CESSION, LA FICHE DU BIEN NE PROPOSE PLUS DE LE CÉDER.
///
/// LE DÉFAUT QUE CE TEST FERME. Le serveur bascule le bien en « Transfert en
/// cours » dès l'initiation, mais la fiche gardait en mémoire son état d'avant :
/// elle continuait d'afficher « Céder ce bien », et un second appui engageait
/// une cession que le serveur refusait — le bouton qui échoue que CT-06
/// proscrit. La fiche relit donc l'état du bien au retour, et bascule sur le
/// message d'attente comme pour tout bien gelé.
///
/// LE STATUT VIENT DU SERVEUR (CT-04) : le test rend un bien passé en V-VTE, et
/// n'invente aucun libellé côté client.
void main() {
  Map<String, Object?> bienJson(String codeStatut, String labelStatut) => <String, Object?>{
        'id': 7,
        'public_ref': 'PRV-CEDE0001',
        'identifier': 'PREUVETESTCESS001',
        'category': 'moto',
        'life_status': <String, Object?>{
          'code': codeStatut, 'label': labelStatut, 'message': '', 'color': '#1F7A4C', 'warning': false,
        },
        'trust_level': <String, Object?>{
          'code': 'F1', 'label': 'Déclaré, non vérifié', 'message': '', 'color': '#5C6470', 'warning': false,
        },
        'attributes': <String, Object?>{'brand_model': 'Yamaha Cession'},
      };

  testWidgets('LA FICHE BASCULE SUR « TRANSFERT EN COURS » APRÈS L\'AVOIR CÉDÉ',
      (WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 3600);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    const OwnedAsset bien = OwnedAsset(
      id: 7,
      publicRef: 'PRV-CEDE0001',
      identifier: 'PREUVETESTCESS001',
      category: 'moto',
      lifeStatus: StatusView(
        code: 'V-ACT', label: 'Actif', message: '', color: '#1F7A4C', warning: false,
      ),
      trustLevel: StatusView(
        code: 'F1', label: 'Déclaré, non vérifié', message: '', color: '#5C6470', warning: false,
      ),
      attributes: <String, Object?>{'brand_model': 'Yamaha Cession'},
    );

    // Les réponses, dans l'ordre où l'écran les appelle :
    //  1. les pièces, au montage ;
    //  2. l'ouverture du transfert, quand on propose ;
    //  3. l'inventaire relu, qui rend le bien DÉSORMAIS en « Transfert en cours ».
    final transport = FauxTransport()
      ..enfile(<String, Object?>{'documents': <Object?>[]})
      ..enfile(<String, Object?>{
        'transfer': <String, Object?>{
          'id': 1,
          'status': 'initiated',
          'status_label': 'En attente de confirmation',
          'seller_confirmed': false,
          'buyer_confirmed': false,
        },
        'message': 'ok',
      })
      ..enfile(<String, Object?>{
        'assets': <Object?>[bienJson('V-VTE', 'Transfert en cours')],
        'pagination': <String, Object?>{'current_page': 1, 'last_page': 1, 'total': 1},
      });

    final PreuveSession session = fauxSession(transport)
      ..compte = const Account(id: 9, phone: '+2250700111222');

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: AssetScreen(session: session, bien: bien),
    ));
    await tester.pumpAndSettle();

    // Au départ, le bien est actif : la cession est proposée.
    expect(find.text('Céder ce bien'), findsOneWidget);

    await tester.tap(find.text('Céder ce bien'));
    await tester.pumpAndSettle();

    // Sur l'écran de cession, on renseigne l'acheteur et on propose.
    await tester.enterText(find.byType(TextField).at(0), '+2250799887766');
    await tester.enterText(find.byType(TextField).at(1), 'acheteur@exemple.ci');
    await tester.tap(find.text('Proposer le transfert'));
    await tester.pumpAndSettle();

    // De retour sur la fiche : elle a relu l'état, le bien est gelé.
    expect(find.text('Céder ce bien'), findsNothing);
    expect(find.textContaining('transfert est en cours'), findsOneWidget);

    // La relecture a bien eu lieu, sur la route de l'inventaire.
    expect(transport.appels, contains('GET /assets'));
  });

  testWidgets('SANS CESSION, LA FICHE PROPOSE TOUJOURS DE CÉDER',
      (WidgetTester tester) async {
    // Garde-fou : le basculement ne doit se produire QUE parce que la cession a
    // réussi, pas par un rechargement fortuit.
    tester.view.physicalSize = const Size(1080, 3600);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    const OwnedAsset bien = OwnedAsset(
      id: 7,
      publicRef: 'PRV-CEDE0001',
      identifier: 'PREUVETESTCESS001',
      category: 'moto',
      lifeStatus: StatusView(
        code: 'V-ACT', label: 'Actif', message: '', color: '#1F7A4C', warning: false,
      ),
      trustLevel: StatusView(
        code: 'F1', label: 'Déclaré, non vérifié', message: '', color: '#5C6470', warning: false,
      ),
      attributes: <String, Object?>{},
    );

    final transport = FauxTransport()..enfile(<String, Object?>{'documents': <Object?>[]});
    final PreuveSession session = fauxSession(transport)
      ..compte = const Account(id: 9, phone: '+2250700111222');

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: AssetScreen(session: session, bien: bien),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Céder ce bien'), findsOneWidget);
    expect(find.textContaining('transfert est en cours'), findsNothing);
  });
}
