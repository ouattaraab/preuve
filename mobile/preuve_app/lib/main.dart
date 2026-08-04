import 'dart:async';

import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import 'data/secure_token_store.dart';
import 'data/session.dart';
import 'screens/shell_screen.dart';
import 'ui/theme.dart';

/// Version installée, annoncée au serveur à chaque écriture.
///
/// À TENIR À JOUR AVEC `pubspec.yaml` : c'est elle que le serveur compare à la
/// version minimale exigée. Une constante figée ferait échapper au forçage de
/// mise à jour précisément les versions qu'il faut arrêter.
const String versionInstallee = '0.1.0';

/// Racine de l'API.
///
/// LA PRODUCTION EST LE DÉFAUT, DÉLIBÉRÉMENT : une application livrée avec une
/// adresse de développement ne joindrait rien, et le défaut ne se verrait
/// qu'après publication. Le remplacer sert au développement, pas l'inverse :
///
/// ```
/// flutter run --dart-define=PREUVE_API=http://192.168.1.10:8000/api/v1
/// ```
///
/// Une adresse locale doit être une adresse de RÉSEAU, jamais `localhost` : sur
/// un simulateur ou un téléphone, `localhost` désigne l'appareil lui-même.
const String baseApi = String.fromEnvironment(
  'PREUVE_API',
  defaultValue: 'https://preuve.click/api/v1',
);

Future<void> main() async {
  // Requis avant tout accès au trousseau : le pont natif ne répond pas tant
  // que la liaison Flutter n'est pas établie.
  WidgetsFlutterBinding.ensureInitialized();

  final session = PreuveSession.pour(
    PreuveApi(baseUrl: baseApi, appVersion: versionInstallee),
    const SecureTokenStore(),
  );

  // LA REPRISE DE SESSION NE RETARDE PAS L'AFFICHAGE, et ne peut pas
  // l'empêcher. Elle joint le serveur ; l'écran d'accueil, lui, n'a besoin de
  // rien. Attendre sa réponse ferait fixer un écran blanc à quelqu'un qui veut
  // seulement vérifier une moto au marché — dans un réseau 3G, plusieurs
  // secondes (CT-05).
  unawaited(session.reprendre());

  runApp(PreuveApp(session: session));
}

/// L'APPLICATION OUVRE SUR LA CONSULTATION, jamais sur une connexion.
///
/// C'est la promesse du produit et l'usage majoritaire : quelqu'un qui vérifie
/// une moto au marché n'a pas de compte et n'en aura peut-être jamais. Faire
/// précéder cet écran d'un accueil, d'un tutoriel ou d'une invitation à
/// s'inscrire ajouterait la friction que CT-06 réserve aux gestes risqués.
class PreuveApp extends StatelessWidget {
  const PreuveApp({required this.session, super.key});

  final PreuveSession session;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Preuve',
      debugShowCheckedModeBanner: false,
      theme: Djassa.build(),
      home: ShellScreen(session: session),
    );
  }
}
