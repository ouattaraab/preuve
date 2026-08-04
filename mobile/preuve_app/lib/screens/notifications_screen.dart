import 'package:flutter/material.dart';
import 'package:preuve_core/preuve_core.dart';

import '../data/session.dart';
import '../ui/code_action.dart';
import '../ui/theme.dart';
import '../ui/widgets.dart';

/// Centre de notifications (ST-1001) et préférences (ST-0107).
///
/// C'EST LA SEULE FORME SOUS LAQUELLE ON APPREND QU'ON REGARDE SON BIEN, et
/// elle est délibérément pauvre : « consulté 3 fois aujourd'hui », jamais par
/// qui ni depuis où. L'anonymat est symétrique — le consultant y a autant droit
/// que le détenteur — et cet écran ne doit jamais laisser espérer davantage.
///
/// LES ALERTES QUI APPELLENT UN GESTE SONT DISTINGUÉES. Une tentative
/// d'enregistrement en doublon signifie que quelqu'un a essayé de déclarer un
/// bien qui est le vôtre : la noyer parmi les compteurs de consultation
/// reviendrait à ne pas la donner.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  NotificationFeed? _fil;
  bool _enCours = true;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      final fil = await widget.session.notifications.feed();

      if (mounted) {
        setState(() => _fil = fil);
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  Future<void> _toutMarquer() async {
    try {
      await widget.session.notifications.markAllAsRead();
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }

      return;
    }

    await _charger();
  }

  Future<void> _marquer(UserNotification notification) async {
    if (notification.read) {
      return;
    }

    try {
      await widget.session.notifications.markAsRead(notification.id);
    } on PreuveException {
      // Sans conséquence : le fil se rechargera. Faire échouer bruyamment la
      // lecture d'une alerte serait disproportionné.
      return;
    }

    await _charger();
  }

  @override
  Widget build(BuildContext context) {
    final fil = _fil;

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Alertes', style: TextStyle(fontWeight: FontWeight.w800)),
        actions: <Widget>[
          if ((fil?.unreadCount ?? 0) > 0)
            TextButton(
              onPressed: _toutMarquer,
              child: const Text(
                'Tout marquer',
                style: TextStyle(fontWeight: FontWeight.w800, color: Djassa.encre),
              ),
            ),
          IconButton(
            onPressed: () => Navigator.of(context).push<void>(
              MaterialPageRoute<void>(
                builder: (_) => NotificationPreferencesScreen(session: widget.session),
              ),
            ),
            icon: const Icon(Icons.tune, color: Djassa.encre),
            tooltip: 'Préférences',
          ),
        ],
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _charger,
          color: Djassa.encre,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
            children: <Widget>[
              if (_erreur != null) ...<Widget>[
                EncadreErreur(_erreur!),
                const SizedBox(height: 16),
              ],
              if (_enCours && fil == null)
                const EnCours()
              else if (fil == null || fil.notifications.isEmpty)
                const RienEncore(
                  titre: 'Aucune alerte',
                  explication:
                      'Tu seras prévenu quand un de tes biens est consulté, quand quelqu\'un '
                      'tente de l\'enregistrer, ou quand une réclamation le vise. Jamais de '
                      'qui il s\'agit : personne ne saura non plus que tu consultes.',
                )
              else
                ...fil.notifications.map(
                  (UserNotification n) => Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: _Ligne(notification: n, onLire: () => _marquer(n)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Ligne extends StatelessWidget {
  const _Ligne({required this.notification, required this.onLire});

  final UserNotification notification;
  final VoidCallback onLire;

  @override
  Widget build(BuildContext context) {
    final critique = notification.isCritical;

    return InkWell(
      onTap: onLire,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(
            // Le rouge est réservé à ce qui appelle un geste. L'appliquer à un
            // compteur de consultation le banaliserait, et le jour où une vraie
            // alerte arrive, elle ne se distinguerait plus.
            color: critique ? Djassa.alerte : Djassa.encre,
            width: 3,
          ),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                if (!notification.read) ...<Widget>[
                  Container(
                    margin: const EdgeInsets.only(top: 7, right: 10),
                    height: 11,
                    width: 11,
                    decoration: const BoxDecoration(
                      color: Djassa.accent,
                      shape: BoxShape.circle,
                    ),
                  ),
                ],
                Expanded(
                  child: Text(
                    // TITRE ET CORPS SONT RÉDIGÉS PAR LE SERVEUR (CT-04) : les
                    // recomposer ici embarquerait les règles d'agrégation dans
                    // une version qui se périmera.
                    notification.title,
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      height: 1.3,
                    ),
                  ),
                ),
              ],
            ),
            if (notification.body.isNotEmpty) ...<Widget>[
              const SizedBox(height: 6),
              Text(
                notification.body,
                style: const TextStyle(height: 1.45),
              ),
            ],
            if (notification.createdAt != null) ...<Widget>[
              const SizedBox(height: 8),
              Text(
                _quand(notification.createdAt!),
                style: const TextStyle(color: Djassa.sourdine, fontSize: 15),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Une ancienneté relative : « il y a 2 heures » se lit d'un coup d'œil là où
  /// une date demande un calcul.
  static String _quand(DateTime moment) {
    final ecart = DateTime.now().difference(moment.toLocal());

    if (ecart.inMinutes < 60) {
      return 'Il y a ${ecart.inMinutes} min';
    }

    if (ecart.inHours < 24) {
      return 'Il y a ${ecart.inHours} h';
    }

    return 'Il y a ${ecart.inDays} jour${ecart.inDays > 1 ? 's' : ''}';
  }
}

/// Ce qu'on peut réellement couper (ST-0107).
class NotificationPreferencesScreen extends StatefulWidget {
  const NotificationPreferencesScreen({required this.session, super.key});

  final PreuveSession session;

  @override
  State<NotificationPreferencesScreen> createState() =>
      _NotificationPreferencesScreenState();
}

class _NotificationPreferencesScreenState extends State<NotificationPreferencesScreen> {
  NotificationPreferences? _preferences;
  bool _enCours = true;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final preferences = await widget.session.notifications.preferences();

      if (mounted) {
        setState(() => _preferences = preferences);
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  Future<void> _basculer(String type, bool actif) async {
    final courantes = _preferences;

    if (courantes == null) {
      return;
    }

    final demandees = <String, bool>{
      for (final OptionalNotification o in courantes.available)
        o.type: o.type == type ? actif : courantes.enabled(o.type),
    };

    setState(() => _enCours = true);

    try {
      final retenues = await widget.session.notifications.updatePreferences(demandees);

      if (mounted) {
        // CE QUE LE SERVEUR RETIENT, jamais ce qu'on a demandé : il peut
        // refuser de couper un type critique, et afficher le souhait plutôt que
        // l'état ferait croire à quelqu'un qu'il ne recevra plus une alerte
        // qu'il recevra.
        setState(() => _preferences = retenues);
      }
    } on PreuveException catch (e) {
      if (mounted) {
        setState(() => _erreur = messageDeRefus(e));
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final preferences = _preferences;

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Djassa.creme,
        surfaceTintColor: Djassa.creme,
        title: const Text('Préférences', style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
          children: <Widget>[
            if (_erreur != null) ...<Widget>[
              EncadreErreur(_erreur!),
              const SizedBox(height: 16),
            ],
            if (preferences == null)
              const EnCours()
            else ...<Widget>[
              ...preferences.available.map(
                (OptionalNotification option) => SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  activeThumbColor: Djassa.accent,
                  title: Text(
                    option.label,
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
                  ),
                  value: preferences.enabled(option.type),
                  onChanged: _enCours
                      ? null
                      : (bool actif) => _basculer(option.type, actif),
                ),
              ),
              const SizedBox(height: 20),
              // DIRE CE QUI NE SE COUPE PAS, plutôt que de laisser chercher la
              // case absente. Une liste incomplète sans explication passe pour
              // un défaut ; expliquée, elle passe pour une protection.
              const Text(
                'Certaines alertes ne se coupent pas : une tentative d\'enregistrement '
                'sur un bien qui est le tien, une réclamation qui le vise, un vol '
                'constaté. Ce sont celles sur lesquelles il y a quelque chose à faire, '
                'et souvent peu de temps pour le faire.',
                style: TextStyle(color: Djassa.sourdine, height: 1.5),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
