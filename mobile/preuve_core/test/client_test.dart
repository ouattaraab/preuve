import 'package:preuve_core/preuve_core.dart';
import 'package:test/test.dart';

/// Racine du site public, déduite de celle de l'API.
///
/// ELLE SERT AU DÉFI ANTI-ROBOT ET AUX LIENS DE PARTAGE. Écrite en dur, elle
/// renverrait vers la production quelqu'un qui a pointé l'application sur une
/// recette — c'est-à-dire vers un registre qui ne contient pas ce qu'il vient
/// d'enregistrer, et qui conclurait que l'enregistrement a échoué.
void main() {
  String pour(String api) => PreuveApi(baseUrl: api, appVersion: '1.0.0').siteBase;

  test('retire le suffixe d\'API, et rien de plus', () {
    expect(pour('https://preuve.click/api/v1'), equals('https://preuve.click'));
    expect(pour('https://preuve.click/api/v1/'), equals('https://preuve.click'));
  });

  test('SUIT LA RECETTE quand on l\'y pointe', () {
    expect(pour('http://192.168.1.10:8000/api/v1'), equals('http://192.168.1.10:8000'));
  });

  test('préserve un sous-répertoire d\'hébergement', () {
    expect(pour('https://exemple.ci/preuve/api/v1'), equals('https://exemple.ci/preuve'));
  });
}
