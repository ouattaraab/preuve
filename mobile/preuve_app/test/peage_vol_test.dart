import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:preuve_app/screens/theft_fee_screen.dart';
import 'package:preuve_app/ui/theme.dart';
import 'package:preuve_core/preuve_core.dart';

import 'faux.dart';

/// Le péage de déclaration de vol, à l'écran (ST-0604).
///
/// CE QUI EST ÉPROUVÉ ICI, C'EST LE DÉFAUT À ZÉRO. Un écran de paiement qui
/// s'ouvrirait alors qu'aucun tarif n'a été réglé fermerait la déclaration de
/// vol à tout le monde, en silence : personne ne signale qu'il a renoncé.
///
/// LES CHARGES UTILES SONT CELLES DU SERVEUR, recopiées des réponses de
/// `TheftDeclarationFeeController`. Un test écrit sur des noms devinés
/// confirmerait l'invention au lieu de la démentir — c'est exactement ce qui
/// s'était produit sur le tableau de bord de flotte.
void main() {
  const OwnedAsset bien = OwnedAsset(
    id: 7,
    publicRef: 'PRV-A1D08C05',
    identifier: 'AA123BC',
    category: 'moto',
    lifeStatus: StatusView(
      code: 'V-ACT',
      label: 'Actif',
      message: 'Ce bien est enregistré et sans incident signalé.',
      color: '#1F7A4C',
      warning: false,
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

  /// La réponse du serveur quand aucun exploitant n'a ouvert de péage.
  Map<String, Object?> gratuit() => <String, Object?>{
        'fee_fcfa': 0,
        'free': true,
        'paid': true,
        'already_stolen': false,
        'explanation': 'Déclarer un vol est gratuit.',
      };

  Map<String, Object?> payant() => <String, Object?>{
        'fee_fcfa': 1000,
        'free': false,
        'paid': false,
        'already_stolen': false,
        'explanation': 'Le règlement couvre la déclaration elle-même. Ton bien reste '
            'enregistré et consultable dans tous les cas.',
      };

  Future<FauxTransport> ouvrir(WidgetTester tester, Object reponse) async {
    final transport = FauxTransport()..enfile(reponse);

    await tester.pumpWidget(MaterialApp(
      theme: Djassa.build(),
      home: Navigator(
        onGenerateRoute: (RouteSettings _) => MaterialPageRoute<bool>(
          builder: (_) => TheftFeeScreen(session: fauxSession(transport), bien: bien),
        ),
      ),
    ));
    await tester.pumpAndSettle();

    return transport;
  }

  testWidgets('N\'AFFICHE AUCUN PRIX QUAND LA DÉCLARATION EST GRATUITE', (WidgetTester tester) async {
    // LE test de ce dispositif. Le jour où il tombe, un utilisateur qui vient
    // de se faire dépouiller voit une caisse là où il n'y a rien à payer.
    await ouvrir(tester, gratuit());

    expect(find.textContaining('FCFA'), findsNothing);
    expect(find.text('Payer et continuer'), findsNothing);
  });

  testWidgets('ANNONCE LE MONTANT AVANT D\'OUVRIR LA CAISSE', (WidgetTester tester) async {
    await ouvrir(tester, payant());

    expect(find.text('1000 FCFA'), findsOneWidget);
    expect(find.text('Payer et continuer'), findsOneWidget);
  });

  testWidgets('DIT CE QUI RESTE ACQUIS SANS PAYER', (WidgetTester tester) async {
    // Devant un montant, on croit que sans lui son bien n'est protégé par
    // rien — et on renonce à l'enregistrement, qui vaut déjà.
    await ouvrir(tester, payant());

    expect(find.text('Acquis, quoi qu\'il arrive'), findsOneWidget);
    expect(
      find.textContaining('reste enregistré à ton nom'),
      findsOneWidget,
    );
  });

  testWidgets('NE PROPOSE PAS DE VÉRIFIER UN PAIEMENT QU\'ON N\'A PAS OUVERT',
      (WidgetTester tester) async {
    // Un bouton « j'ai payé » offert avant toute page de paiement invite à
    // cliquer sans avoir rien réglé, puis à croire le produit cassé.
    await ouvrir(tester, payant());

    expect(find.text('J\'ai payé, vérifier'), findsNothing);
  });

  testWidgets('TRAITE UNE RÉPONSE TRONQUÉE COMME GRATUITE', (WidgetTester tester) async {
    // Se tromper dans ce sens ne coûte qu'un aller-retour : le serveur
    // refusera de son côté. Se tromper dans l'autre bloque une déclaration
    // de vol sur une réponse incomplète.
    await ouvrir(tester, <String, Object?>{});

    expect(find.textContaining('FCFA'), findsNothing);
  });
}
