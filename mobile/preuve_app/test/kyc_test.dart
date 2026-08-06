import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/kyc_screen.dart';
import 'package:preuve_app/ui/theme.dart';

import 'faux.dart';

/// Dossier d'identité (ST-0103), à l'écran.
///
/// LES CHARGES UTILES SONT CELLES DE LA PRODUCTION. Le motif de refus était lu
/// sous `rejection_reason`, un nom que le serveur n'envoie pas : il le place
/// dans `last_submission.review_reason`. L'encadré rouge existait, le motif
/// existait — ils ne se sont jamais rencontrés.
void main() {
  Future<void> afficher(WidgetTester tester, Map<String, Object?> etat) async {
    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: KycScreen(session: fauxSession(FauxTransport()..enfile(etat))),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('MONTRE POURQUOI le dossier a été refusé', (WidgetTester tester) async {
    // Sans motif, on redépose à l'identique et on se fait refuser à
    // l'identique : deux fois l'attente, deux fois le travail de l'agent.
    await afficher(tester, <String, Object?>{
      'status': 'rejected',
      'status_label': 'Refusée',
      'can_submit': true,
      'last_submission': <String, Object?>{
        'id': 7,
        'status': 'rejected',
        'review_reason': 'Le selfie ne correspond pas à la photo de la pièce.',
      },
    });

    expect(find.textContaining('ne correspond pas à la photo'), findsOneWidget);
    // Et le formulaire reste ouvert : le refus n'est pas une fin.
    expect(find.textContaining('Recto de la pièce'), findsWidgets);
  });

  testWidgets('DEMANDE LA SÉQUENCE, en la disant facultative', (WidgetTester tester) async {
    // Une photo imprimée brandie devant l'objectif ne tourne pas la tête. Mais
    // un appareil qui ne sait pas produire ces prises ne doit pas priver
    // quelqu'un de sa vérification d'identité.
    await afficher(tester, <String, Object?>{'status': 'none', 'can_submit': true});

    await tester.scrollUntilVisible(find.text('Deux prises de plus'), 250);
    expect(find.textContaining('Facultatives'), findsOneWidget);
    expect(find.textContaining('Tourne la tête à GAUCHE'), findsOneWidget);

    // La liste construit à la demande : la seconde consigne est plus bas.
    await tester.scrollUntilVisible(find.textContaining('Tourne la tête à DROITE'), 250);
    expect(find.textContaining('Tourne la tête à DROITE'), findsOneWidget);
  });

  testWidgets('NE PROMET PAS UNE VÉRIFICATION AUTOMATIQUE', (WidgetTester tester) async {
    // L'écran dit ce que la séquence FAIT — montrer à un agent — et jamais
    // qu'elle vérifie quoi que ce soit. Le promettre ferait croire à une
    // sécurité que le client ne peut pas offrir.
    await afficher(tester, <String, Object?>{'status': 'none', 'can_submit': true});

    await tester.scrollUntilVisible(find.text('Deux prises de plus'), 250);

    expect(find.textContaining('montrent à l\'agent'), findsOneWidget);
    for (final Text t in tester.widgetList<Text>(find.byType(Text))) {
      expect(t.data ?? '', isNot(contains('vérifié automatiquement')));
      expect(t.data ?? '', isNot(contains('score')));
    }
  });

  testWidgets('un dossier en cours ne se redépose pas', (WidgetTester tester) async {
    // Deux dossiers concurrents feraient trancher un agent sur une pièce que
    // l'autre a déjà écartée.
    await afficher(tester, <String, Object?>{
      'status': 'pending',
      'status_label': 'En cours de vérification',
      'can_submit': false,
      'last_submission': <String, Object?>{'id': 8, 'status': 'pending'},
    });

    expect(find.textContaining('en cours d\'examen'), findsOneWidget);
    expect(find.textContaining('Recto de la pièce'), findsNothing);
  });

  testWidgets('une identité vérifiée dit ce qu\'elle ouvre', (WidgetTester tester) async {
    await afficher(tester, <String, Object?>{
      'status': 'verified',
      'status_label': 'Vérifiée',
      'can_submit': true,
      'verified_at': '2026-08-04T15:00:00+00:00',
      'last_submission': null,
    });

    expect(find.text('Identité vérifiée'), findsOneWidget);
    // LE NUMÉRO DE PIÈCE N'EST PAS RESTITUABLE, et l'écran le dit : c'est une
    // promesse de la Loi 2013-450, pas une note d'implémentation.
    expect(find.textContaining('empreinte'), findsOneWidget);
  });

  testWidgets('aucun encadré de refus quand il n\'y a pas eu de refus',
      (WidgetTester tester) async {
    await afficher(tester, <String, Object?>{'status': 'none', 'can_submit': true});

    expect(find.textContaining('Dossier refusé'), findsNothing);
    expect(find.textContaining('Recto de la pièce'), findsWidgets);
  });
}
