/// Erreurs de l'API, typées par ce que le client doit FAIRE.
///
/// Un client qui ne verrait que des codes numériques traiterait un quota épuisé
/// comme une erreur de saisie, et renverrait l'utilisateur au formulaire qu'il
/// vient de remplir correctement. Chaque classe ici correspond à une conduite
/// différente, pas à un code différent.
library;

sealed class PreuveException implements Exception {
  const PreuveException(this.message);

  final String message;

  @override
  String toString() => message;
}

/// Le jeton est absent, expiré ou révoqué (401).
///
/// Une rétrogradation de rôle ou une suspension de compte révoque les jetons :
/// ce n'est pas nécessairement une session périmée, et le message du serveur
/// vaut mieux que le nôtre.
class NotAuthenticated extends PreuveException {
  const NotAuthenticated(super.message);
}

/// Quota d'enregistrement épuisé, ou frais de dossier dus (402).
///
/// IL N'Y A RIEN À CORRIGER DANS LA DEMANDE : elle est valide et le restera.
/// Router vers le paiement, jamais vers le formulaire.
class PaymentRequired extends PreuveException {
  const PaymentRequired(super.message, {this.details});

  /// État du quota ou montant dû, tel que le serveur le rend.
  final Map<String, Object?>? details;
}

/// Cet identifiant est déjà enregistré par quelqu'un d'autre (409 sur `/assets`).
///
/// LA SEULE ISSUE EST LA RÉCLAMATION. Réessayer ne créera jamais un second
/// enregistrement actif : c'est la règle qui protège le premier propriétaire.
class AlreadyRegistered extends PreuveException {
  const AlreadyRegistered(super.message, {this.existingAsset, this.claimUrl});

  final Map<String, Object?>? existingAsset;
  final String? claimUrl;
}

/// L'application est trop ancienne pour écrire (426).
///
/// LA CONSULTATION RESTE OUVERTE. Ne jamais bloquer l'application entière :
/// quelqu'un au marché doit obtenir son verdict même avec un vieux téléphone.
class UpgradeRequired extends PreuveException {
  const UpgradeRequired(super.message, {this.minimumVersion, this.latestVersion});

  final String? minimumVersion;
  final String? latestVersion;
}

/// Plafond horaire de consultation atteint (429).
///
/// `captchaSiteKey` peut être nul : le défi n'est pas configuré côté serveur.
/// Le refus tient quand même — ne pas promettre une échappatoire qui n'existe
/// pas.
class RateLimited extends PreuveException {
  const RateLimited(super.message, {this.captchaSiteKey});

  final String? captchaSiteKey;

  bool get challengeAvailable => captchaSiteKey != null;
}

/// La plateforme est en maintenance, en lecture seule (503).
///
/// La consultation continue de fonctionner : ne pas afficher un écran de panne
/// générale pour un refus d'écriture.
class ReadOnlyPlatform extends PreuveException {
  const ReadOnlyPlatform(super.message);
}

/// Saisie refusée par le serveur (422).
class InvalidRequest extends PreuveException {
  const InvalidRequest(super.message, {this.errors = const {}});

  /// Erreurs par champ, telles que Laravel les rend.
  final Map<String, List<String>> errors;

  /// Premier message concernant un champ donné, s'il existe.
  String? forField(String field) {
    final messages = errors[field];

    return messages == null || messages.isEmpty ? null : messages.first;
  }
}

/// La ressource n'existe pas, ou n'appartient pas au porteur du jeton (404).
///
/// Le serveur rend délibérément 404 plutôt que 403 sur le bien d'autrui :
/// confirmer l'existence d'un bien par son identifiant interne donnerait un
/// moyen de balayage.
class NotFound extends PreuveException {
  const NotFound(super.message);
}

/// Le réseau n'a pas répondu, ou la réponse est inexploitable.
///
/// DISTINCTE D'UN REFUS DU SERVEUR, et c'est tout l'intérêt : une action perdue
/// faute de réseau peut être rejouée telle quelle, un refus non.
class NetworkFailure extends PreuveException {
  const NetworkFailure(super.message);
}

/// Défaut inattendu côté serveur (5xx hors 503).
class ServerFailure extends PreuveException {
  const ServerFailure(super.message, this.statusCode);

  final int statusCode;
}
