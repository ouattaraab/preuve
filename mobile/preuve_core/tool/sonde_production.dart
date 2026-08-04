// Sonde manuelle : éprouve le client contre le serveur RÉEL.
//
// Elle ne fait partie d'aucune suite : elle sort du réseau, et un test qui
// dépend d'internet finit par échouer pour des raisons qui n'ont rien à voir
// avec le code. Elle se lance à la main quand on veut savoir si les contrats
// tiennent encore : `dart run tool/sonde_production.dart`.
import 'package:preuve_core/preuve_core.dart';

Future<void> main() async {
  final api = PreuveApi(
    baseUrl: 'https://preuve.click/api/v1',
    appVersion: '0.1.0',
  );
  final lookups = LookupService(api);

  final release = await lookups.release();
  print('version minimale exigée : ${release.minimumVersion ?? "aucune"}');
  print('la consultation reste ouverte : ${AppRelease.lookupAlwaysAvailable}');

  final inconnu = await lookups.check('1M8GDM9AXKP042788');
  print('verdict : ${inconnu.outcome.name}');
  print('message : ${inconnu.message}');
  print('alerte  : ${inconnu.isWarning}');

  final illisible = await lookups.check('AB');
  print('saisie trop courte -> ${illisible.outcome.name}');

  api.close();
}
