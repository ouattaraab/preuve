package ci.bookmi.preuve

import io.flutter.embedding.android.FlutterActivity

/**
 * Point d'entrée Android.
 *
 * SON PAQUET SUIT L'IDENTIFIANT DE L'APPLICATION, et ce n'est pas cosmétique :
 * un binaire où `applicationId` et `namespace` divergent s'installe puis refuse
 * de démarrer — le lanceur cherche la classe sous un nom que le système ne
 * résout pas. Constaté sur l'APK de publication, invisible en débogage.
 */
class MainActivity : FlutterActivity()
