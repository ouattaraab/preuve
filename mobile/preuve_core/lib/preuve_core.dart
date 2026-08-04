/// Cœur métier du client PREUVE.
///
/// AUCUNE DÉPENDANCE À FLUTTER, et ce n'est pas une coquetterie : les règles qui
/// décident ce qu'un acheteur voit à l'écran doivent être vérifiables sans
/// appareil ni émulateur. Ce paquet s'analyse et se teste avec le seul SDK
/// Dart ; l'application ne fait que l'habiller.
library;

export 'src/api/asset_service.dart';
export 'src/api/auth_service.dart';
export 'src/api/client.dart';
export 'src/api/exceptions.dart';
export 'src/api/lookup_service.dart';
export 'src/api/transport.dart';
export 'src/identifiers.dart';
export 'src/upload/upload_queue.dart';
export 'src/models/catalog.dart';
export 'src/models/lookup.dart';
export 'src/version.dart';
