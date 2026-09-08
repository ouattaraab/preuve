import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/fleet_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// Le tableau de bord d'un loueur (EP-07), à l'écran.
///
/// LA CHARGE UTILE EST CELLE DE LA PRODUCTION, relevée sur le serveur et
/// recopiée sans retouche. Trois noms avaient été devinés dans ce modèle, et
/// les trois étaient faux — le parc s'affichait à zéro. Une fixture inventée
/// aurait confirmé l'invention au lieu de la démentir.
void main() {
  const CompanyMembership societe = CompanyMembership(
    id: 1,
    name: 'DEMO Loueur Abidjan',
    role: 'admin',
    roleLabel: 'Administrateur de flotte',
  );

  Map<String, Object?> tableau() => <String, Object?>{
        'fleet_size': 4,
        'by_status': <Object?>[
          <String, Object?>{'code': 'V-ACT', 'label': 'Actif', 'count': 2, 'warning': false},
          <String, Object?>{'code': 'V-LOC', 'label': 'En location', 'count': 1, 'warning': true},
          <String, Object?>{'code': 'V-VOL', 'label': 'Volé déclaré', 'count': 1, 'warning': true},
        ],
        'needs_attention': <Object?>[
          <String, Object?>{
            'asset_id': 4,
            'public_ref': 'PRV-A1D08C05',
            'status': 'V-LOC',
            'status_label': 'En location',
          },
          <String, Object?>{
            'asset_id': 5,
            'public_ref': 'PRV-F4F27AF0',
            'status': 'V-VOL',
            'status_label': 'Volé déclaré',
          },
        ],
        'lookups_30d': <String, Object?>{
          'total': 12,
          'most_viewed': <Object?>[
            <String, Object?>{'asset_id': 4, 'public_ref': 'PRV-A1D08C05', 'lookups': 9},
          ],
        },
        'recent_alerts': <Object?>[],
      };

  Future<FauxTransport> afficher(WidgetTester tester, {Object? reponse}) async {
    final transport = FauxTransport()..enfile(reponse ?? tableau());

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: FleetScreen(session: fauxSession(transport), societe: societe),
    ));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('affiche la taille réelle du parc, pas un zéro', (WidgetTester tester) async {
    await afficher(tester);

    expect(find.text('DEMO Loueur Abidjan'), findsWidgets);

    // Le total est PLUS BAS QUE LES ALERTES, à dessein : il faut donc défiler
    // pour l'atteindre. C'est la démonstration du défaut corrigé — le serveur
    // dit `fleet_size`, et le modèle lisait `total`.
    await tester.scrollUntilVisible(find.text('véhicules protégés'), 200);

    expect(find.text('4'), findsOneWidget);
    // Et les consultations, que le modèle lisait aussi de travers.
    expect(find.text('12'), findsOneWidget);
  });

  testWidgets('OUVRE SUR CE QUI NE VA PAS', (WidgetTester tester) async {
    // Un loueur de quarante motos sait qu'il en a quarante. Ce qu'il ignore,
    // c'est laquelle est déclarée volée.
    await afficher(tester);

    final double aTraiter = tester.getTopLeft(find.text('À traiter')).dy;
    final double leParc = tester.getTopLeft(find.text('Le parc')).dy;

    expect(aTraiter, lessThan(leParc));
  });

  testWidgets('NOMME les véhicules concernés, au lieu de les compter',
      (WidgetTester tester) async {
    await afficher(tester);

    expect(find.text('PRV-F4F27AF0'), findsOneWidget);
    expect(find.text('PRV-A1D08C05'), findsOneWidget);
  });

  testWidgets('ne montre AUCUNE immatriculation à l\'écran', (WidgetTester tester) async {
    // Ce tableau s'ouvre au comptoir d'une agence : il se lit par-dessus
    // l'épaule. La référence publique suffit à retrouver le véhicule dedans.
    await afficher(tester);

    for (final Text t in tester.widgetList<Text>(find.byType(Text))) {
      expect(t.data ?? '', isNot(matches(RegExp(r'\b[A-Z0-9]{9,}\b'))));
    }
  });

  testWidgets('dit les statuts en langage courant, jamais par leur code (CT-04)',
      (WidgetTester tester) async {
    await afficher(tester);

    expect(find.text('Volé déclaré'), findsWidgets);
    expect(find.textContaining('V-VOL'), findsNothing);
    expect(find.textContaining('V-ACT'), findsNothing);
  });

  testWidgets('un parc vide ne fait pas tomber l\'écran', (WidgetTester tester) async {
    await afficher(tester, reponse: const <String, Object?>{});

    expect(find.text('Le parc'), findsOneWidget);
    expect(find.text('À traiter'), findsNothing);
  });

  testWidgets('un refus du serveur se lit, et l\'écran reste utilisable',
      (WidgetTester tester) async {
    await afficher(tester, reponse: const ServerFailure('Service indisponible.', 503));

    expect(find.textContaining('indisponible'), findsOneWidget);
    // Le marquage reste offert : le loueur peut réessayer sans quitter.
    expect(find.text('Marquer en location'), findsOneWidget);
  });
}
