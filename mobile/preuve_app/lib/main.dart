import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import 'screens/lookup_screen.dart';
import 'ui/theme.dart';

/// Version installée, annoncée au serveur à chaque écriture.
///
/// À TENIR À JOUR AVEC `pubspec.yaml` : c'est elle que le serveur compare à la
/// version minimale exigée. Une constante figée ferait échapper au forçage de
/// mise à jour précisément les versions qu'il faut arrêter.
const String versionInstallee = '0.1.0';

const String baseApi = 'https://preuve.click/api/v1';

void main() {
  final api = PreuveApi(baseUrl: baseApi, appVersion: versionInstallee);

  runApp(PreuveApp(lookups: LookupService(api)));
}

/// L'APPLICATION OUVRE SUR LA CONSULTATION, jamais sur une connexion.
///
/// C'est la promesse du produit et l'usage majoritaire : quelqu'un qui vérifie
/// une moto au marché n'a pas de compte et n'en aura peut-être jamais. Faire
/// précéder cet écran d'un accueil, d'un tutoriel ou d'une invitation à
/// s'inscrire ajouterait la friction que CT-06 réserve aux gestes risqués.
class PreuveApp extends StatelessWidget {
  const PreuveApp({required this.lookups, super.key});

  final LookupService lookups;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Preuve',
      debugShowCheckedModeBanner: false,
      theme: Djassa.build(),
      home: LookupScreen(lookups: lookups),
    );
  }
}
