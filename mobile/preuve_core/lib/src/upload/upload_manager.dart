import '../api/exceptions.dart';
import 'upload_queue.dart';

/// Où la file d'attente survit entre deux lancements.
///
/// UNE INTERFACE, PAS UNE IMPLÉMENTATION, pour la même raison que le coffre à
/// jeton : ce paquet ne connaît ni système de fichiers ni base locale, et c'est
/// ce qui permet d'éprouver toute la reprise dans une console.
///
/// CE QU'ELLE CONSERVE N'EST PAS LE FICHIER mais sa DESCRIPTION — chemin,
/// taille, empreinte, identifiant tiré par le client. Recopier des mégaoctets
/// dans un magasin local doublerait l'occupation disque d'un téléphone pour ne
/// rien garantir de plus : le fichier, lui, est déjà quelque part.
abstract interface class PendingUploadStore {
  Future<List<Map<String, Object?>>> read();

  Future<void> write(List<Map<String, Object?>> entries);
}

/// File d'envoi différée, avec reprise après REDÉMARRAGE (ST-0206, CT-05).
///
/// `UploadQueue` sait pousser UN envoi jusqu'au bout ; ce qui manquait, c'est ce
/// qui survit à la fermeture de l'application. Quelqu'un qui photographie sa
/// carte grise au bord d'une route perd le réseau, range son téléphone, et
/// rouvre l'application deux heures plus tard : sans persistance, sa pièce
/// n'était jamais partie et rien ne le lui aurait dit.
///
/// ELLE NE RÉESSAIE JAMAIS D'ELLE-MÊME, et c'est la même discipline que
/// `UploadQueue` : décider QUAND réessayer appartient à l'appelant, seul à
/// savoir si le réseau est revenu, si l'écran est ouvert et si la batterie
/// tient. Un minuteur enfoui ici viderait le forfait de quelqu'un dans son dos.
///
/// LES ENVOIS SONT TRAITÉS UN À UN. Les lancer en parallèle sur une 3G partagée
/// les ferait tous ralentir et échouer ensemble, là où la file les fait aboutir
/// l'un après l'autre.
class UploadManager {
  UploadManager({required UploadQueue queue, required PendingUploadStore store})
      : _queue = queue,
        _store = store;

  final UploadQueue _queue;
  final PendingUploadStore _store;

  final List<PendingUpload> _attente = <PendingUpload>[];

  /// Ce qui reste à envoyer, dans l'ordre de mise en file.
  List<PendingUpload> get pending => List<PendingUpload>.unmodifiable(_attente);

  /// Relit la file au lancement.
  Future<void> restore() async {
    _attente
      ..clear()
      ..addAll(
        (await _store.read())
            .map(PendingUpload.fromJson)
            // Une entrée illisible — magasin corrompu, format d'une version
            // antérieure — est écartée plutôt que de faire échouer le
            // lancement : perdre un envoi vaut mieux que perdre l'application.
            .where((PendingUpload? envoi) => envoi != null)
            .cast<PendingUpload>(),
      );
  }

  /// Met une pièce en file. Elle part au prochain [drain].
  ///
  /// IDEMPOTENT PAR L'IDENTIFIANT tiré par le client : remettre deux fois la
  /// même pièce ne crée pas deux envois, et n'ouvrira donc pas deux sessions
  /// sur le disque du serveur.
  Future<void> enqueue(PendingUpload envoi) async {
    if (_attente.any((PendingUpload e) => e.uuid == envoi.uuid)) {
      return;
    }

    _attente.add(envoi);
    await _persister();
  }

  /// Tente d'envoyer tout ce qui attend, une pièce après l'autre.
  ///
  /// Rend le compte-rendu de la passe. Ne lève jamais : un échec d'envoi n'est
  /// pas une erreur de programme, c'est l'état normal d'un réseau de bord de
  /// route — et faire remonter une exception obligerait chaque appelant à
  /// l'attraper pour ne rien en faire.
  Future<DrainReport> drain({void Function(PendingUpload, UploadState)? onProgress}) async {
    final abouties = <PendingUpload>[];
    final abandonnees = <PendingUpload, String>{};
    var reseauCoupe = false;

    // Copie : la liste est modifiée en cours de route.
    for (final PendingUpload envoi in List<PendingUpload>.of(_attente)) {
      // LE RÉSEAU EST TOMBÉ : on arrête la passe au lieu de faire échouer les
      // suivants un par un. Chaque tentative coûte une attente de vingt
      // secondes, et les enchaîner ferait paraître l'application bloquée.
      if (reseauCoupe) {
        break;
      }

      try {
        // L'ÉTAT VIENT DU SERVEUR, jamais d'un compteur local conservé entre
        // deux lancements : lui seul sait ce qui est réellement arrivé.
        final etat = await _queue.push(envoi);

        onProgress?.call(envoi, etat);

        if (etat.isComplete) {
          abouties.add(envoi);
          _attente.remove(envoi);
        } else if (etat.hasFailed) {
          abandonnees[envoi] = etat.failureReason ??
              'L\'envoi a été refusé. Reprends la photo et recommence.';
          _attente.remove(envoi);
        }
      } on LocalFileChanged catch (e) {
        // Distincte d'un échec réseau : celui-ci se réessaie, celui-là non. Le
        // fichier a été tronqué ou remplacé, et poursuivre produirait une pièce
        // dont l'empreinte ne tomberait jamais juste.
        abandonnees[envoi] = e.message;
        _attente.remove(envoi);
      } on NetworkFailure {
        reseauCoupe = true;
      } on NotAuthenticated {
        // La session est tombée : insister ferait échouer tous les envois et
        // épuiserait le compteur d'anti-brute-force du serveur.
        break;
      } on PreuveException catch (e) {
        // Un refus du serveur — pièce trop lourde, type non accepté, bien
        // disparu — ne se répare pas en réessayant. Le garder en file ferait
        // rejouer indéfiniment un envoi voué à échouer.
        abandonnees[envoi] = e.message;
        _attente.remove(envoi);
      }
    }

    await _persister();

    return DrainReport(
      completed: abouties,
      abandoned: abandonnees,
      networkInterrupted: reseauCoupe,
    );
  }

  /// Retire une pièce de la file, à la demande.
  Future<void> forget(String uuid) async {
    _attente.removeWhere((PendingUpload e) => e.uuid == uuid);
    await _persister();
  }

  Future<void> _persister() {
    return _store.write(
      _attente.map((PendingUpload e) => e.toJson()).toList(growable: false),
    );
  }
}

/// Ce qu'une passe d'envoi a produit.
class DrainReport {
  const DrainReport({
    required this.completed,
    required this.abandoned,
    required this.networkInterrupted,
  });

  final List<PendingUpload> completed;

  /// Pièces retirées de la file sans avoir abouti, avec la raison à afficher.
  /// ELLES NE REVIENDRONT PAS TOUTES SEULES : la personne doit savoir laquelle,
  /// et pourquoi, sinon elle croira son dossier complet.
  final Map<PendingUpload, String> abandoned;

  /// Vrai quand la passe s'est arrêtée faute de réseau. Ce qui reste en file
  /// repartira au prochain essai — rien n'est perdu.
  final bool networkInterrupted;

  bool get hasFailures => abandoned.isNotEmpty;
}
