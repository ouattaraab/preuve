import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/trust_screen.dart';
import 'package:preuve_app/screens/watch_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// Deux capacités serveur qui n'avaient aucun appelant (ST-0401, ST-0403).
///
/// LA FIABILITÉ : le détenteur voyait une pastille « Déclaré » et aucun levier.
/// Le serveur savait dire ce qu'il manquait et ce que le palier apportait ;
/// personne ne le lui demandait, et les biens restaient au niveau le plus bas —
/// or c'est ce niveau qui donne sa valeur au rapport vendu aux acheteurs.
///
/// LA VEILLE : service, détection de pics et notifications existaient depuis
/// EP-04. Aucun écran ne les appelait, donc personne ne pouvait poser de veille.
///
/// LES CHARGES UTILES SONT CELLES DU SERVEUR, recopiées de `AssetDocumentController`
/// et de `WatchAlertController`.
void main() {
  const OwnedAsset bien = OwnedAsset(
    id: 7,
    publicRef: 'PRV-A1D08C05',
    identifier: 'AA123BC',
    category: 'moto',
    lifeStatus: StatusView(
      code: 'V-ACT',
      label: 'Actif',
      message: 'Ce bien est enregistré.',
      color: '#1F7A4C',
      warning: false,
    ),
    trustLevel: StatusView(
      code: 'F1',
      label: 'Déclaré',
      message: 'Sur déclaration du détenteur.',
      color: '#D97706',
      warning: false,
    ),
    attributes: <String, Object?>{},
  );

  Map<String, Object?> progression() => <String, Object?>{
        'trust': <String, Object?>{
          'current': 'F1',
          'current_label': 'Déclaré',
          'next': 'F2',
          'next_label': 'Documenté',
          'benefit': 'Un bien documenté rassure l\'acheteur et vous ouvre le transfert '
              'de propriété et la réclamation.',
          'missing': <Object?>['Une carte grise ou une facture acceptée par un agent'],
          'rules_version': 2,
        },
        'documents': <Object?>[
          <String, Object?>{
            'id': 3,
            'doc_type': 'registration_card',
            'doc_type_label': 'Carte grise',
            'review_status': 'rejected',
            'review_status_label': 'Refusée',
            'review_reason': 'La photo est floue : le numéro de châssis est illisible.',
            'submitted_at': '2026-08-01T10:00:00+00:00',
          },
        ],
      };

  Future<FauxTransport> ouvrirFiabilite(WidgetTester tester, Object reponse) async {
    tester.view.physicalSize = const Size(1080, 3600);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    final transport = FauxTransport()..enfile(reponse);

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: TrustScreen(session: fauxSession(transport), bien: bien),
    ));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('DIT CE QUE LE PALIER APPORTE AVANT CE QU\'IL COÛTE',
      (WidgetTester tester) async {
    // La liste de ce qui manque, seule, se lit comme une liste de corvées.
    await ouvrirFiabilite(tester, progression());

    expect(find.textContaining('rassure l\'acheteur'), findsOneWidget);
    expect(find.textContaining('Une carte grise ou une facture'), findsOneWidget);
    expect(find.text('Passer à « Documenté »'), findsOneWidget);
  });

  testWidgets('MONTRE LE MOTIF D\'UN REFUS', (WidgetTester tester) async {
    // Une pièce refusée sans raison est redéposée à l'identique, refusée à
    // nouveau, et les deux côtés perdent leur temps.
    await ouvrirFiabilite(tester, progression());

    expect(find.textContaining('La photo est floue'), findsOneWidget);
  });

  testWidgets('NE PROPOSE RIEN AU SOMMET', (WidgetTester tester) async {
    // Inventer une étape ferait courir après un palier qui n'existe pas.
    await ouvrirFiabilite(tester, <String, Object?>{
      'trust': <String, Object?>{
        'current': 'F3',
        'current_label': 'Vérifié',
        'next': null,
        'next_label': null,
        'benefit': null,
        'missing': <Object?>[],
      },
      'documents': <Object?>[],
    });

    expect(find.text('Rien de plus à faire'), findsOneWidget);
    expect(find.textContaining('Passer à'), findsNothing);
  });

  Future<FauxTransport> ouvrirVeille(
    WidgetTester tester, {
    required List<Object?> veilles,
    required List<Object?> biens,
  }) async {
    tester.view.physicalSize = const Size(1080, 3600);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    final transport = FauxTransport()
      ..enfile(<String, Object?>{'watch_alerts': veilles})
      ..enfile(<String, Object?>{
        'assets': biens,
        'pagination': <String, Object?>{'current_page': 1, 'last_page': 1, 'total': biens.length},
      });

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: WatchScreen(session: fauxSession(transport)),
    ));
    await tester.pumpAndSettle();

    return transport;
  }

  Map<String, Object?> bienJson(String identifiant) => <String, Object?>{
        'id': 7,
        'public_ref': 'PRV-A1D08C05',
        'identifier': identifiant,
        'category': 'moto',
        'life_status': <String, Object?>{
          'code': 'V-ACT', 'label': 'Actif', 'message': '', 'color': '#1F7A4C', 'warning': false,
        },
        'trust_level': <String, Object?>{
          'code': 'F1', 'label': 'Déclaré', 'message': '', 'color': '#D97706', 'warning': false,
        },
        'attributes': <String, Object?>{},
      };

  testWidgets('PROPOSE DE SURVEILLER SES PROPRES BIENS', (WidgetTester tester) async {
    // On ne laisse pas saisir un numéro : le serveur refuserait un bien qui
    // n'est pas le sien, et un refus qu'on aurait pu éviter n'apprend rien.
    await ouvrirVeille(tester, veilles: <Object?>[], biens: <Object?>[bienJson('AA123BC')]);

    expect(find.text('Aucune veille'), findsOneWidget);
    expect(find.text('AA123BC'), findsOneWidget);
  });

  testWidgets('DIT QU\'UNE VEILLE SANS ALERTE EST UNE BONNE NOUVELLE',
      (WidgetTester tester) async {
    // Un silence pris pour une panne ferait douter du dispositif entier.
    await ouvrirVeille(tester, veilles: <Object?>[
      <String, Object?>{
        'id': 1,
        'identifier': 'AA123BC',
        'channel': 'push',
        'active': true,
        'last_triggered_at': null,
      },
    ], biens: <Object?>[]);

    expect(find.textContaining('Rien à signaler'), findsOneWidget);
  });

  testWidgets('NE PROPOSE PAS UN BIEN DÉJÀ SOUS VEILLE', (WidgetTester tester) async {
    // Le serveur rend l'identifiant NORMALISÉ : comparer à la saisie brute
    // ferait réapparaître le bien dans la liste des propositions.
    await ouvrirVeille(tester, veilles: <Object?>[
      <String, Object?>{'id': 1, 'identifier': 'AA123BC', 'channel': 'push', 'active': true},
    ], biens: <Object?>[bienJson('AA-123-BC')]);

    expect(find.text('Tous tes biens sont déjà sous veille.'), findsOneWidget);
  });

  testWidgets('RAPPELLE QUE L\'ANONYMAT TIENT DANS LES DEUX SENS',
      (WidgetTester tester) async {
    await ouvrirVeille(tester, veilles: <Object?>[], biens: <Object?>[]);

    expect(find.textContaining('jamais par qui'), findsOneWidget);
  });
}
