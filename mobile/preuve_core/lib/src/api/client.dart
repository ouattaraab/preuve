import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'exceptions.dart';
import 'transport.dart';

/// Client HTTP de l'API PREUVE.
///
/// SANS AUCUNE DÉPENDANCE EXTERNE : `dart:io` suffit. Une bibliothèque HTTP de
/// plus, c'est une chaîne d'approvisionnement de plus à surveiller dans une
/// application qui transporte des pièces d'identité — et quelques centaines de
/// kilo-octets sur un parc où le stockage est compté.
///
/// LE JETON N'EST JAMAIS ENVOYÉ SUR LA CONSULTATION. Non par oubli : la
/// consultation est anonyme (règle métier absolue n° 1), et l'accompagner du
/// jeton associerait au compte chaque bien que l'utilisateur vérifie avant
/// d'acheter. Le porteur d'un jeton est certes dispensé du plafond horaire,
/// mais ce confort ne vaut pas de transformer une consultation anonyme en
/// historique nominatif.
///
/// LES ERREURS SONT TRADUITES EN CONDUITES, pas en codes : voir `exceptions.dart`.
class PreuveApi implements PreuveTransport {
  PreuveApi({
    required this.baseUrl,
    required this.appVersion,
    HttpClient? httpClient,
    this.timeout = const Duration(seconds: 20),
  }) : _http = httpClient ?? HttpClient();

  /// Racine de l'API, sans barre finale. Ex. `https://preuve.click/api/v1`.
  final String baseUrl;

  /// Version installée, annoncée à chaque écriture. C'est elle qui permet au
  /// serveur de refuser une version dont les règles ont changé.
  final String appVersion;

  /// Généreux : CT-01 promet moins d'une seconde de traitement, mais une 3G de
  /// bord de route ajoute plusieurs secondes que l'utilisateur préfère attendre
  /// plutôt que de recommencer.
  final Duration timeout;

  final HttpClient _http;

  String? _token;

  /// Jeton de session, s'il y en a un.
  @override
  bool get isAuthenticated => _token != null;

  @override
  void setToken(String? token) => _token = token;

  /// Appel sans jeton, quel que soit l'état de la session.
  ///
  /// Utilisé par la consultation : c'est ce qui garantit qu'aucun historique
  /// nominatif ne se constitue à l'insu de qui vérifie un bien.
  @override
  Future<Map<String, Object?>> getAnonymous(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) {
    return _send('GET', path, query: query, headers: headers, authenticated: false);
  }

  @override
  Future<Map<String, Object?>> get(String path, {Map<String, String>? query}) {
    return _send('GET', path, query: query);
  }

  @override
  Future<Map<String, Object?>> post(String path, {Map<String, Object?>? body}) {
    return _send('POST', path, body: body);
  }

  @override
  Future<Map<String, Object?>> put(String path, {Map<String, Object?>? body}) {
    return _send('PUT', path, body: body);
  }

  @override
  Future<Map<String, Object?>> delete(String path, {Map<String, Object?>? body}) {
    return _send('DELETE', path, body: body);
  }

  /// Envoi d'un morceau binaire (reprise d'un envoi différé).
  @override
  Future<Map<String, Object?>> patchBytes(
    String path,
    List<int> bytes, {
    required int offset,
  }) {
    return _send(
      'PATCH',
      path,
      rawBody: bytes,
      headers: <String, String>{
        'X-Upload-Offset': offset.toString(),
        HttpHeaders.contentTypeHeader: 'application/octet-stream',
      },
    );
  }

  /// Formulaire avec pièce jointe (pièces d'une réclamation).
  ///
  /// La frontière est tirée à la main plutôt qu'empruntée à une bibliothèque :
  /// une dépendance de plus dans une application qui transporte des pièces
  /// d'identité coûte plus cher que trente lignes lisibles. Elle est tirée du
  /// compteur d'appels et non du hasard, pour rester reproductible en test.
  @override
  Future<Map<String, Object?>> postMultipart(
    String path, {
    required Map<String, String> fields,
    MultipartFile? file,
  }) {
    final frontiere = '----preuve${DateTime.now().microsecondsSinceEpoch}';
    final corps = <int>[];

    fields.forEach((cle, valeur) {
      corps
        ..addAll(utf8.encode('--$frontiere\r\n'))
        ..addAll(utf8.encode('Content-Disposition: form-data; name="$cle"\r\n\r\n'))
        ..addAll(utf8.encode('$valeur\r\n'));
    });

    if (file != null) {
      corps
        ..addAll(utf8.encode('--$frontiere\r\n'))
        ..addAll(utf8.encode(
          'Content-Disposition: form-data; name="${file.field}"; '
          'filename="${file.filename}"\r\n',
        ))
        ..addAll(utf8.encode('Content-Type: ${file.contentType}\r\n\r\n'))
        ..addAll(file.bytes)
        ..addAll(utf8.encode('\r\n'));
    }

    corps.addAll(utf8.encode('--$frontiere--\r\n'));

    return _send(
      'POST',
      path,
      rawBody: corps,
      headers: <String, String>{
        HttpHeaders.contentTypeHeader: 'multipart/form-data; boundary=$frontiere',
      },
    );
  }

  Future<Map<String, Object?>> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, Object?>? body,
    List<int>? rawBody,
    Map<String, String>? headers,
    bool authenticated = true,
  }) async {
    final uri = Uri.parse('$baseUrl$path').replace(
      queryParameters: query == null || query.isEmpty ? null : query,
    );

    HttpClientResponse response;
    String payload;

    try {
      final request = await _http.openUrl(method, uri).timeout(timeout);

      // Sans cet en-tête, une erreur d'authentification peut suivre le chemin
      // web du serveur et rendre du HTML : l'intégrateur y lit une panne là où
      // il n'a qu'oublié son jeton.
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set('X-App-Version', appVersion);

      if (authenticated && _token != null) {
        request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $_token');
      }

      headers?.forEach(request.headers.set);

      if (rawBody != null) {
        request.add(rawBody);
      } else if (body != null) {
        request.headers.contentType = ContentType.json;
        request.write(jsonEncode(body));
      }

      response = await request.close().timeout(timeout);
      payload = await response.transform(utf8.decoder).join();
    } on TimeoutException {
      throw const NetworkFailure(
        'Le réseau ne répond pas. Réessaie quand la connexion revient.',
      );
    } on SocketException {
      throw const NetworkFailure('Pas de connexion. Réessaie plus tard.');
    } on HttpException catch (e) {
      throw NetworkFailure(e.message);
    }

    final decoded = _decode(payload);

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    throw _toException(response.statusCode, decoded);
  }

  /// Un corps vide ou illisible n'est pas une erreur en soi : c'est le CODE qui
  /// porte le sens, et une réponse `204` légitime n'a rien à décoder.
  Map<String, Object?> _decode(String payload) {
    if (payload.trim().isEmpty) {
      return const <String, Object?>{};
    }

    try {
      final decoded = jsonDecode(payload);

      return decoded is Map<String, Object?> ? decoded : <String, Object?>{'data': decoded};
    } on FormatException {
      return const <String, Object?>{};
    }
  }

  PreuveException _toException(int status, Map<String, Object?> body) {
    final message = _string(body['message']) ??
        'Le serveur a refusé la demande (code $status).';

    return switch (status) {
      401 => NotAuthenticated(message),
      402 => PaymentRequired(
          message,
          // Le serveur nomme différemment selon l'objet du refus : quota pour
          // un enregistrement, montant pour des frais de dossier.
          details: _map(body['quota']) ?? _map(body['fee']) ?? _map(body['details']),
        ),
      404 => NotFound(message),
      // Deux conflits de sens opposé partagent ce code. Le corps les
      // distingue : une position reçue annonce une reprise, une fiche de bien
      // annonce une réclamation.
      409 when body['received_bytes'] is int => UploadOffsetMismatch(
          message,
          receivedBytes: body['received_bytes']! as int,
        ),
      409 => AlreadyRegistered(
          message,
          existingAsset: _map(body['asset']),
          claimUrl: _string(body['claim_url']),
        ),
      422 => InvalidRequest(message, errors: _errors(body['errors'])),
      426 => UpgradeRequired(
          message,
          minimumVersion: _string(body['minimum_version']),
          latestVersion: _string(body['latest_version']),
        ),
      429 => RateLimited(message, captchaSiteKey: _siteKey(body)),
      503 => ReadOnlyPlatform(message),
      _ => ServerFailure(message, status),
    };
  }

  /// La clé publique du défi voyage AVEC le refus, et seulement là.
  static String? _siteKey(Map<String, Object?> body) {
    final captcha = _map(body['captcha']);

    return captcha == null ? null : _string(captcha['site_key']);
  }

  static String? _string(Object? value) => value is String && value.isNotEmpty ? value : null;

  static Map<String, Object?>? _map(Object? value) =>
      value is Map<String, Object?> ? value : null;

  static Map<String, List<String>> _errors(Object? value) {
    if (value is! Map<String, Object?>) {
      return const <String, List<String>>{};
    }

    final result = <String, List<String>>{};

    value.forEach((key, messages) {
      if (messages is List) {
        result[key] = messages.whereType<String>().toList();
      } else if (messages is String) {
        result[key] = <String>[messages];
      }
    });

    return result;
  }

  void close() => _http.close(force: true);
}
