import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:plugin_platform_interface/plugin_platform_interface.dart';
import 'package:preuve_app/screens/stolen_listing_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';
import 'package:url_launcher_platform_interface/link.dart';
import 'package:url_launcher_platform_interface/url_launcher_platform_interface.dart';

import 'faux.dart';

/// La mise en avant payante, à l'écran (ST-0805).
///
/// CE QUE CES TESTS VERROUILLENT AVANT TOUT : le retour de la caisse. Le
/// règlement se fait DANS LE NAVIGATEUR, hors de l'application. Sans relecture
/// au retour, l'utilisateur retrouvait un écran inchangé, bouton « Payer et
/// publier » toujours en place — et la seule conduite évidente était de payer
/// une seconde fois. Aucun test qui ne quitte pas l'application ne voit cela.
///
/// LES CHARGES UTILES SONT CELLES DU SERVEUR, recopiées des réponses de
/// `StolenListingController`.
void main() {
  const OwnedAsset bien = OwnedAsset(
    id: 7,
    publicRef: 'PRV-A1D08C05',
    identifier: 'AA123BC',
    category: 'moto',
    lifeStatus: StatusView(
      code: 'V-VOL',
      label: 'Volé déclaré',
      message: 'Ce bien est signalé volé par son détenteur.',
      color: '#C62F21',
      warning: true,
    ),
    trustLevel: StatusView(
      code: 'declared',
      label: 'Déclaré',
      message: 'Enregistré sur déclaration du détenteur.',
      color: '#D97706',
      warning: false,
    ),
    attributes: <String, Object?>{},
  );

  Map<String, Object?> aPublier() => <String, Object?>{
        'listed': false,
        'listed_at': null,
        'price_fcfa': 200,
        'free': false,
        'explanation': 'Ton bien est déjà invendable pour qui vérifie son numéro.',
      };

  Map<String, Object?> ouverture() => <String, Object?>{
        'listed': false,
        'price_fcfa': 200,
        'checkout_url': 'https://checkout.paystack.com/e8wrv9csc4i2qby',
        'message': 'Règle le montant, puis reviens.',
      };

  Map<String, Object?> publie() => <String, Object?>{
        'listed': true,
        'listed_at': '2026-08-06T19:00:00+00:00',
        'price_fcfa': 200,
        'free': false,
      };

  late _FauxLanceur lanceur;

  setUp(() {
    lanceur = _FauxLanceur();
    UrlLauncherPlatform.instance = lanceur;
  });

  Future<FauxTransport> ouvrir(WidgetTester tester, List<Object> reponses) async {
    // UNE SURFACE ASSEZ HAUTE POUR TOUT L'ÉCRAN, et ce n'est pas un détail de
    // confort : `ListView` construit ses enfants PARESSEUSEMENT. Sur la
    // surface par défaut (800 dp), le bouton de vérification restait sous le
    // pli, donc jamais bâti — un `findsNothing` y aurait été vrai pour la
    // mauvaise raison, et le test aurait « passé » en n'éprouvant rien.
    tester.view.physicalSize = const Size(1080, 3600);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    final transport = FauxTransport();

    for (final Object r in reponses) {
      transport.enfile(r);
    }

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: StolenListingScreen(session: fauxSession(transport), bien: bien),
    ));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('DIT CE QUI EST DÉJÀ ACQUIS AVANT DE DIRE LE PRIX',
      (WidgetTester tester) async {
    // Sans cela, on vend à quelqu'un ce qu'il possède déjà : son bien est
    // invendable pour qui vérifie son numéro, et cela ne se paie pas.
    await ouvrir(tester, <Object>[aPublier()]);

    expect(find.text('Déjà fait, et gratuit'), findsOneWidget);
    expect(find.text('200 FCFA'), findsOneWidget);
  });

  testWidgets('OUVRE LA CAISSE DANS LE NAVIGATEUR, PAS DANS UNE VUE EMBARQUÉE',
      (WidgetTester tester) async {
    // C'est la barre d'adresse qui permet de vérifier qu'on est bien chez
    // l'opérateur et non sur une imitation.
    await ouvrir(tester, <Object>[aPublier(), ouverture()]);

    await tester.tap(find.text('Payer et publier'));
    await tester.pumpAndSettle();

    expect(lanceur.ouvertes, equals(<String>['https://checkout.paystack.com/e8wrv9csc4i2qby']));
  });

  testWidgets('PROPOSE DE VÉRIFIER APRÈS AVOIR OUVERT LA CAISSE, PAS AVANT',
      (WidgetTester tester) async {
    await ouvrir(tester, <Object>[aPublier(), ouverture()]);

    expect(find.text('J\'ai payé, vérifier'), findsNothing);

    await tester.tap(find.text('Payer et publier'));
    await tester.pumpAndSettle();

    expect(find.text('J\'ai payé, vérifier'), findsOneWidget);
  });

  testWidgets('RELIT L\'ÉTAT TOUT SEUL AU RETOUR DU NAVIGATEUR',
      (WidgetTester tester) async {
    // LE test de ce dispositif. Le jour où il tombe, celui qui vient de payer
    // retrouve un bouton « Payer et publier » et paie une seconde fois.
    final transport = await ouvrir(tester, <Object>[aPublier(), ouverture(), publie()]);

    await tester.tap(find.text('Payer et publier'));
    await tester.pumpAndSettle();

    // L'utilisateur part chez l'opérateur, puis revient.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(transport.appels.last, equals('GET /assets/7/stolen-listing'));
    expect(find.text('Paiement confirmé : ton bien est sur la liste.'), findsOneWidget);
    expect(find.text('Ton bien est sur la liste'), findsOneWidget);
  });

  testWidgets('NE DIT PAS « ÉCHEC » QUAND LE RAPPEL TARDE',
      (WidgetTester tester) async {
    // Le rappel de l'opérateur met parfois quelques secondes. Annoncer un
    // échec dans cet intervalle ferait repayer quelqu'un qui a déjà payé.
    await ouvrir(tester, <Object>[aPublier(), ouverture(), aPublier()]);

    await tester.tap(find.text('Payer et publier'));
    await tester.pumpAndSettle();

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(find.textContaining('ne repaie pas'), findsOneWidget);
    expect(find.textContaining('échec'), findsNothing);
  });

  testWidgets('NE RAPPELLE PAS LE SERVEUR SI AUCUNE CAISSE N\'A ÉTÉ OUVERTE',
      (WidgetTester tester) async {
    // Rappeler à chaque passage en avant-plan coûterait de la donnée en 3G
    // pour rien (CT-05).
    final transport = await ouvrir(tester, <Object>[aPublier()]);
    final int avant = transport.appels.length;

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(transport.appels.length, equals(avant));
  });
}

/// Retient les adresses ouvertes, sans jamais sortir sur le réseau.
class _FauxLanceur extends UrlLauncherPlatform with MockPlatformInterfaceMixin {
  final List<String> ouvertes = <String>[];

  @override
  LinkDelegate? get linkDelegate => null;

  @override
  Future<bool> canLaunch(String url) async => true;

  @override
  Future<bool> launchUrl(String url, LaunchOptions options) async {
    ouvertes.add(url);

    return true;
  }
}
