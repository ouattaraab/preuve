import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/screens/transfers_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// LE CODE D'UNE CESSION SE DEMANDE SUR LA ROUTE DE LA CESSION.
///
/// LE DÉFAUT QUE CES TESTS FERMENT. L'écran passait par `/auth/otp/request`,
/// qui indexe le défi sur la coordonnée DU COMPTE qui se présente ; le serveur,
/// à la confirmation, le cherche sur celle DU TRANSFERT — l'adresse vers
/// laquelle le vendeur a ouvert la cession. Dès qu'une adresse était donnée —
/// le cas recommandé, et le seul par lequel l'acheteur est réellement prévenu —
/// les deux différaient : aucun code saisi n'était jamais reconnu, l'acheteur
/// s'acharnait jusqu'au verrouillage, et la cession expirait au bout de sept
/// jours sur un bien déjà payé et emporté.
///
/// IL NE SE VOYAIT PAS SANS QUITTER L'APPLICATION : le chemin appelé était
/// valide, la réponse était un succès, et rien n'échouait avant la saisie du
/// code par quelqu'un d'autre, sur un autre appareil.
void main() {
  Map<String, Object?> transfertEntrant() => <String, Object?>{
        'id': 42,
        'status': 'initiated',
        'status_label': 'En attente de confirmation',
        'role': 'buyer',
        'seller_confirmed': true,
        'buyer_confirmed': false,
        'expires_at': DateTime.now().add(const Duration(days: 5)).toIso8601String(),
        'asset': <String, Object?>{
          'public_ref': 'PRV-A1D08C05',
          'category': 'moto',
          'life_status': <String, Object?>{
            'code': 'V-VTE',
            'label': 'Transfert en cours',
            'message': '',
            'color': '#1D4ED8',
            'warning': false,
          },
        },
      };

  Future<FauxTransport> ouvrir(
    WidgetTester tester, {
    required Map<String, Object?> reponseCode,
  }) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    final transport = FauxTransport()
      ..enfile(<String, Object?>{
        'transfers': <Object?>[transfertEntrant()],
      })
      ..enfile(reponseCode);

    // Compte inscrit par NUMÉRO, avec une adresse personnelle : c'est la
    // configuration ordinaire, et celle où la coordonnée du compte et celle du
    // transfert n'ont aucune raison de coïncider.
    final PreuveSession session = fauxSession(transport)
      ..compte = const Account(id: 9, phone: '+2250700111222', email: 'perso@exemple.ci');

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: TransfersScreen(session: session),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Accepter ce bien'));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('DEMANDE LE CODE À LA ROUTE DU TRANSFERT, PAS À CELLE DU COMPTE',
      (WidgetTester tester) async {
    final transport = await ouvrir(tester, reponseCode: <String, Object?>{
      'sent_to': 'a•••••••@exemple.ci',
      'fresh': true,
      'expires_in': 300,
    });

    expect(transport.appels, contains('POST /transfers/42/code'));
    // Le jour où celui-ci réapparaît, plus aucune cession n'aboutit.
    expect(
      transport.appels.where((String a) => a.contains('otp/request')),
      isEmpty,
    );
  });

  testWidgets('N\'ENVOIE PAS LE CAMP AU SERVEUR', (WidgetTester tester) async {
    // Le serveur le calcule : l'accepter en paramètre laisserait un vendeur
    // faire partir des codes chez son acheteur, puis l'en inonder.
    final transport = await ouvrir(tester, reponseCode: <String, Object?>{
      'sent_to': 'a•••••••@exemple.ci',
      'fresh': true,
    });

    expect(transport.dernierCorps, isEmpty);
  });

  testWidgets('DIT OÙ LE CODE EST PARTI, TEL QUE LE SERVEUR LE MASQUE',
      (WidgetTester tester) async {
    // L'acheteur inscrit par téléphone attend un SMS ; le code arrive dans une
    // boîte. Sans cette phrase, il cherche au mauvais endroit.
    await ouvrir(tester, reponseCode: <String, Object?>{
      'sent_to': 'a•••••••@exemple.ci',
      'fresh': true,
    });

    expect(find.textContaining('a•••••••@exemple.ci'), findsOneWidget);
    expect(find.textContaining('perso@exemple.ci'), findsNothing);
  });

  testWidgets('N\'ANNONCE PAS UN SECOND MESSAGE QUAND LE PREMIER TIENT ENCORE',
      (WidgetTester tester) async {
    // Le code de l'ouverture de la cession est encore valable : promettre un
    // nouvel envoi ferait attendre un courriel qui n'arrivera pas.
    await ouvrir(tester, reponseCode: <String, Object?>{
      'sent_to': 'a•••••••@exemple.ci',
      'fresh': false,
    });

    expect(find.textContaining('est encore valable'), findsOneWidget);
    expect(find.text('Valider'), findsOneWidget);
  });
}
