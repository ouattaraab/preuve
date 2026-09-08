import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/data/session.dart';
import 'package:preuve_app/screens/register_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// Le parcours d'enregistrement, tel qu'un utilisateur le vit.
///
/// CE FICHIER EST NÉ D'UN BLOCAGE SIGNALÉ EN USAGE : « on reste bloqué au
/// niveau de la marque du bien ». Il reproduit le parcours exact, sur un écran
/// de la taille d'un téléphone, pour établir ce que la personne VOIT — et non
/// ce que le code fait.
void main() {
  /// Le catalogue réellement servi par la production : un identifiant canonique
  /// puis la marque, obligatoire, sur les trois catégories.
  Map<String, Object?> catalogue() => <String, Object?>{
        'version': 'v1',
        'categories': <Object?>[
          <String, Object?>{
            'key': 'moto',
            'name': 'Moto',
            'icon': '🛵',
            'fields': <Object?>[
              <String, Object?>{
                'key': 'chassis',
                'label': 'N° de châssis',
                'type': 'identifier',
                'required': true,
                'canonical': true,
              },
              <String, Object?>{
                'key': 'brand_model',
                'label': 'Marque et modèle',
                'type': 'text',
                'required': true,
                'canonical': false,
              },
            ],
          },
        ],
      };

  Widget app(PreuveSession session) => MaterialApp(
        theme: Djassa.build(),
        home: RegisterScreen(session: session),
      );

  /// Écran de téléphone, clavier ouvert : les conditions du blocage signalé.
  Future<void> telephone(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1179, 2556);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
  }

  /// Vrai si le widget est réellement dans la fenêtre visible.
  bool visible(WidgetTester tester, Finder cible) {
    if (cible.evaluate().isEmpty) {
      return false;
    }

    final boite = tester.getRect(cible.first);
    final ecran = tester.view.physicalSize / tester.view.devicePixelRatio;

    return boite.bottom > 0 && boite.top < ecran.height;
  }

  testWidgets('mène de la marque du bien jusqu\'à l\'enregistrement', (
    WidgetTester tester,
  ) async {
    await telephone(tester);

    final transport = FauxTransport()
      ..enfile(catalogue())
      ..enfile(<String, Object?>{
        'asset': <String, Object?>{
          'id': 12,
          'public_ref': 'PRV-2H4K9MNP',
          'identifier': '1M8GDM9AXKP042788',
          'category': 'moto',
          'life_status': <String, Object?>{'code': 'V-PRV', 'label': 'Enregistrement récent'},
          'trust_level': <String, Object?>{'code': 'F1', 'label': 'Déclaré'},
        },
      });

    await tester.pumpWidget(app(fauxSession(transport)));
    await tester.pumpAndSettle();

    // Une seule catégorie : l'écran doit avoir sauté l'étape du type.
    expect(find.text('Le numéro'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, '1M8GDM9AXKP042788');
    await tester.enterText(find.byType(TextField).last, 'Yamaha Crux');
    await tester.pumpAndSettle();

    await tester.tap(find.text('Continuer'));
    await tester.pumpAndSettle();

    // On doit être passé aux photos.
    expect(find.text('4 photos'), findsOneWidget);
  });

  testWidgets('ne propose pas de photos quand le serveur ne rend pas d\'identifiant', (
    WidgetTester tester,
  ) async {
    // CAS RÉEL : un serveur plus ancien rend la vue PUBLIQUE du bien, qui ne
    // porte pas d'identifiant interne. Proposer les quatre cases mettrait des
    // pièces en file sur le bien numéro ZÉRO — elles n'arriveraient jamais, et
    // personne ne saurait qu'elles manquent.
    await telephone(tester);

    final transport = FauxTransport()
      ..enfile(catalogue())
      ..enfile(<String, Object?>{
        'asset': <String, Object?>{
          'public_ref': 'PRV-2H4K9MNP',
          'category': 'moto',
          'life_status': <String, Object?>{'code': 'V-PRV', 'label': 'Enregistrement récent'},
          'trust_level': <String, Object?>{'code': 'F1', 'label': 'Déclaré'},
        },
      });

    await tester.pumpWidget(app(fauxSession(transport)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '1M8GDM9AXKP042788');
    await tester.enterText(find.byType(TextField).last, 'Yamaha Crux');
    await tester.pumpAndSettle();

    await tester.tap(find.text('Continuer'));
    await tester.pumpAndSettle();

    // Le bien EST enregistré : c'est l'essentiel, et l'écran le dit.
    expect(find.text('C\'est enregistré !'), findsOneWidget);
    expect(find.text('4 photos'), findsNothing);
    expect(find.textContaining('n\'ont pas pu être proposées'), findsOneWidget);
  });

  testWidgets('laisse atteindre « Continuer » clavier ouvert', (
    WidgetTester tester,
  ) async {
    // LE BLOCAGE SIGNALÉ, reproduit dans ses conditions : on est sur le champ
    // « marque et modèle », donc le clavier occupe le bas de l'écran. Si le
    // bouton n'est pas atteignable, le parcours s'arrête là — et rien à l'écran
    // ne dit pourquoi.
    await telephone(tester);
    tester.view.viewInsets = const FakeViewPadding(bottom: 336 * 3);

    final transport = FauxTransport()..enfile(catalogue());

    await tester.pumpWidget(app(fauxSession(transport)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).last, 'Yamaha Crux');
    await tester.pumpAndSettle();

    final bouton = find.text('Continuer');

    expect(bouton, findsOneWidget, reason: 'le bouton doit exister');

    // Il peut être hors de la fenêtre : ce qui compte est qu'on puisse l'y
    // amener en faisant défiler, sans quoi le parcours est sans issue.
    await tester.dragUntilVisible(
      bouton,
      find.byType(SingleChildScrollView),
      const Offset(0, -80),
    );
    await tester.pumpAndSettle();

    expect(
      visible(tester, bouton),
      isTrue,
      reason: 'le bouton doit pouvoir être amené à l\'écran',
    );
  });

  testWidgets('MONTRE le refus quand le numéro est mal recopié', (
    WidgetTester tester,
  ) async {
    // LE BLOCAGE SIGNALÉ. Le message existait, mais sous le champ du châssis —
    // c'est-à-dire au-dessus de la marque, hors de la fenêtre quand le clavier
    // est ouvert. La personne touche « Continuer », rien ne bouge à l'écran, et
    // elle conclut que l'application est cassée.
    await telephone(tester);

    final transport = FauxTransport()..enfile(catalogue());

    await tester.pumpWidget(app(fauxSession(transport)));
    await tester.pumpAndSettle();

    // Un châssis dont le chiffre de contrôle ne tombe pas juste.
    await tester.enterText(find.byType(TextField).first, '1M8GDM9AXKP042789');
    await tester.enterText(find.byType(TextField).last, 'Yamaha Crux');
    await tester.pumpAndSettle();

    await tester.tap(find.text('Continuer'));
    await tester.pumpAndSettle();

    final refus = find.textContaining('Recompte les 17 caractères');

    expect(refus, findsOneWidget, reason: 'le refus doit exister');
    expect(
      visible(tester, refus),
      isTrue,
      reason: 'le refus doit être DANS LA FENÊTRE, près du bouton qui vient d\'être touché',
    );
  });

  testWidgets('MONTRE un refus du serveur, et ne laisse pas l\'écran muet', (
    WidgetTester tester,
  ) async {
    // Quota épuisé, session expirée, réseau coupé : ces refus s'affichaient en
    // HAUT de la page, derrière le clavier et deux écrans de défilement.
    await telephone(tester);

    final transport = FauxTransport()
      ..enfile(catalogue())
      ..enfile(const PaymentRequired('Tes places gratuites sont utilisées.'));

    await tester.pumpWidget(app(fauxSession(transport)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '1M8GDM9AXKP042788');
    await tester.enterText(find.byType(TextField).last, 'Yamaha Crux');
    await tester.pumpAndSettle();

    await tester.tap(find.text('Continuer'));
    await tester.pumpAndSettle();

    final refus = find.textContaining('places gratuites');

    expect(refus, findsWidgets, reason: 'le refus doit exister');
    expect(
      visible(tester, refus),
      isTrue,
      reason: 'un refus invisible équivaut à un bouton qui ne fait rien',
    );
  });
}
