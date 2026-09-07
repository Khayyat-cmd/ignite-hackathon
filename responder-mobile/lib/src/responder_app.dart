import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'l10n.dart';
import 'models.dart';

const ink = Color(0xFF080C1A);
const surface = Color(0xFF11172A);
const line = Color(0xFF28324D);
const mint = Color(0xFF4DE0BA);
const muted = Color(0xFF9CA8C2);

final ThemeData responderTheme = ThemeData(
  brightness: Brightness.dark,
  scaffoldBackgroundColor: ink,
  colorScheme: const ColorScheme.dark(
    primary: mint,
    surface: surface,
    outline: line,
  ),
  textTheme: const TextTheme(
    headlineMedium: TextStyle(fontWeight: FontWeight.w700, letterSpacing: -0.8),
    titleLarge: TextStyle(fontWeight: FontWeight.w700),
    titleMedium: TextStyle(fontWeight: FontWeight.w700),
    bodyMedium: TextStyle(height: 1.45),
  ),
  inputDecorationTheme: InputDecorationTheme(
    filled: true,
    fillColor: const Color(0xFF0D1324),
    border: OutlineInputBorder(
      borderRadius: BorderRadius.circular(12),
      borderSide: const BorderSide(color: line),
    ),
  ),
  elevatedButtonTheme: ElevatedButtonThemeData(
    style: ElevatedButton.styleFrom(
      backgroundColor: mint,
      foregroundColor: const Color(0xFF05231C),
      minimumSize: const Size.fromHeight(52),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      textStyle: const TextStyle(fontWeight: FontWeight.w800),
    ),
  ),
);

class AmanResponderApp extends StatefulWidget {
  const AmanResponderApp({super.key, required this.gateway});
  final ResponderGateway gateway;

  @override
  State<AmanResponderApp> createState() => _AmanResponderAppState();
}

class _AmanResponderAppState extends State<AmanResponderApp> {
  static const preferenceKey = 'app_language';
  String _language = 'en';

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    final saved = (await SharedPreferences.getInstance()).getString(preferenceKey);
    if (mounted && saved != null && supportedLanguages.contains(saved)) {
      setState(() => _language = saved);
    }
  }

  Future<void> _change(String language) async {
    if (!supportedLanguages.contains(language) || language == _language) return;
    setState(() => _language = language);
    await (await SharedPreferences.getInstance()).setString(preferenceKey, language);
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    title: 'AMAN Responder',
    theme: responderTheme,
    locale: Locale(_language),
    supportedLocales: supportedLanguages.map(Locale.new).toList(),
    localizationsDelegates: const [
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    // Installed above the Navigator so a pushed route — the full-screen
    // assignment takeover included — reads the same language as the screen
    // that pushed it.
    builder: (context, child) => L10n(
      strings: Strings(_language),
      onChangeLanguage: _change,
      child: child ?? const SizedBox.shrink(),
    ),
    home: ServerGate(gateway: widget.gateway),
  );
}

/// A failure the app raised itself is translated; text the backend sent is
/// shown as it arrived.
String describeError(Strings strings, Object error) =>
    error is ApiException && error.code != null
    ? strings.t('error.${error.code}')
    : error.toString();

class ServerGate extends StatefulWidget {
  const ServerGate({super.key, required this.gateway});
  final ResponderGateway gateway;

  @override
  State<ServerGate> createState() => _ServerGateState();
}

class _ServerGateState extends State<ServerGate> {
  static const preferenceKey = 'server_base_url';
  String? _baseUrl;
  bool _loading = true;
  bool _editing = false;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    if (!widget.gateway.supportsServerConfiguration) {
      if (mounted) setState(() => _loading = false);
      return;
    }
    final saved = (await SharedPreferences.getInstance()).getString(
      preferenceKey,
    );
    if (saved != null) widget.gateway.configureServer(saved);
    if (mounted) {
      setState(() {
        _baseUrl = saved;
        _loading = false;
      });
    }
  }

  Future<void> _save(String baseUrl) async {
    widget.gateway.configureServer(baseUrl);
    await (await SharedPreferences.getInstance()).setString(
      preferenceKey,
      baseUrl,
    );
    if (mounted) {
      setState(() {
        _baseUrl = baseUrl;
        _editing = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (widget.gateway.supportsServerConfiguration &&
        (_baseUrl == null || _editing)) {
      return ServerSetup(
        gateway: widget.gateway,
        onConnected: _save,
        onCancel: _baseUrl == null
            ? null
            : () => setState(() => _editing = false),
      );
    }
    return ResponderEntry(
      gateway: widget.gateway,
      onChangeServer: widget.gateway.supportsServerConfiguration
          ? () => setState(() => _editing = true)
          : null,
    );
  }
}

class ServerSetup extends StatefulWidget {
  const ServerSetup({
    super.key,
    required this.gateway,
    required this.onConnected,
    this.onCancel,
  });
  final ResponderGateway gateway;
  final Future<void> Function(String) onConnected;
  final VoidCallback? onCancel;

  @override
  State<ServerSetup> createState() => _ServerSetupState();
}

class _ServerSetupState extends State<ServerSetup> {
  final _controller = TextEditingController();
  Object? _error;
  bool _busy = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  String? _normalize(String value) {
    var input = value.trim();
    if (input.isEmpty) return null;
    if (!input.contains('://')) {
      final localUri = Uri.tryParse('http://$input');
      input = localUri?.hasPort == true
          ? 'http://$input'
          : 'http://$input:8000';
    }
    final uri = Uri.tryParse(input);
    if (uri == null ||
        !{'http', 'https'}.contains(uri.scheme) ||
        uri.host.isEmpty ||
        uri.hasQuery ||
        uri.hasFragment) {
      return null;
    }
    final path = uri.path == '' || uri.path == '/'
        ? '/api/v1/demo'
        : uri.path.replaceFirst(RegExp(r'/$'), '');
    return uri.replace(path: path).toString();
  }

  Future<void> _connect() async {
    final baseUrl = _normalize(_controller.text);
    if (baseUrl == null) {
      setState(() => _error = L10n.of(context).t('server.invalid'));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      widget.gateway.configureServer(baseUrl);
      await widget.gateway.responders();
      await widget.onConnected(baseUrl);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 34, 24, 24),
          children: [
            BrandHeader(label: s.t('brand.fieldResponse')),
            const SizedBox(height: 56),
            Text(
              s.t('server.title'),
              style: Theme.of(context).textTheme.headlineMedium,
            ),
            const SizedBox(height: 10),
            Text(s.t('server.blurb'), style: const TextStyle(color: muted)),
            const SizedBox(height: 28),
            TextField(
              controller: _controller,
              enabled: !_busy,
              keyboardType: TextInputType.url,
              autocorrect: false,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _connect(),
              // An address is typed and read left to right whatever the app
              // language is.
              textDirection: TextDirection.ltr,
              decoration: InputDecoration(
                labelText: s.t('server.field'),
                hintText: '192.168.1.20',
                prefixIcon: const Icon(Icons.lan_outlined),
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                describeError(s, _error!),
                style: const TextStyle(color: Color(0xFFF09A9A)),
              ),
            ],
            const SizedBox(height: 18),
            ElevatedButton(
              onPressed: _busy ? null : _connect,
              child: Text(
                _busy ? s.t('server.connecting') : s.t('server.connect'),
              ),
            ),
            if (widget.onCancel != null) ...[
              const SizedBox(height: 8),
              TextButton(
                onPressed: _busy ? null : widget.onCancel,
                child: Text(s.t('common.cancel')),
              ),
            ],
            const SizedBox(height: 16),
            Text(
              s.t('server.sameNetwork'),
              textAlign: TextAlign.center,
              style: const TextStyle(color: muted, fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}

class ResponderEntry extends StatefulWidget {
  const ResponderEntry({
    super.key,
    required this.gateway,
    this.onChangeServer,
  });
  final ResponderGateway gateway;
  final VoidCallback? onChangeServer;
  @override
  State<ResponderEntry> createState() => _ResponderEntryState();
}

class _ResponderEntryState extends State<ResponderEntry> {
  static const preferenceKey = 'demo_responder_id';
  List<Responder>? _responders;
  String? _selectedId;
  String? _selectedEventId;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final responders = await widget.gateway.responders();
      final saved = (await SharedPreferences.getInstance()).getString(
        preferenceKey,
      );
      if (!mounted) return;
      setState(() {
        _responders = responders;
        final savedResponder = responders
            .where((item) => item.id == saved)
            .firstOrNull;
        final hasCurrentEvent = responders.any(
          (item) => item.eventStatus != 'stopped',
        );
        _selectedId =
            savedResponder != null &&
                (savedResponder.eventStatus != 'stopped' || !hasCurrentEvent)
            ? saved
            : null;
        _selectedEventId = responders
            .where((item) => item.id == _selectedId)
            .firstOrNull
            ?.eventId;
        _selectedEventId ??= responders
            .where((item) => item.eventStatus == 'running')
            .firstOrNull
            ?.eventId;
        _selectedEventId ??= responders.firstOrNull?.eventId;
        _error = null;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<void> _select(Responder responder) async {
    await (await SharedPreferences.getInstance()).setString(
      preferenceKey,
      responder.id,
    );
    if (mounted) setState(() => _selectedId = responder.id);
  }

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    final allResponders = _responders ?? const <Responder>[];
    final currentResponders = allResponders
        .where((item) => item.eventStatus != 'stopped')
        .toList();
    final selectableResponders = currentResponders.isEmpty
        ? allResponders
        : currentResponders;
    final selectedEventId =
        selectableResponders.any((item) => item.eventId == _selectedEventId)
        ? _selectedEventId
        : selectableResponders.firstOrNull?.eventId;
    final events = <String, Responder>{
      for (final responder in selectableResponders)
        responder.eventId: responder,
    }.values.toList();
    final visibleResponders = selectableResponders.where(
      (item) => item.eventId == selectedEventId,
    );
    final selected = _responders
        ?.where((item) => item.id == _selectedId)
        .firstOrNull;
    if (selected != null) {
      return MissionHome(
        gateway: widget.gateway,
        responder: selected,
        onSwitch: () => setState(() => _selectedId = null),
        onChangeServer: widget.onChangeServer,
      );
    }
    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 34, 22, 24),
            children: [
              BrandHeader(
                label: s.t('brand.fieldResponse'),
                onServer: widget.onChangeServer,
              ),
              const SizedBox(height: 48),
              Text(
                s.t('entry.title'),
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              const SizedBox(height: 10),
              Text(s.t('entry.blurb'), style: const TextStyle(color: muted)),
              const SizedBox(height: 28),
              if (_responders == null && _error == null)
                const Center(child: CircularProgressIndicator()),
              if (_error != null) ErrorCard(error: _error!, onRetry: _load),
              if (_responders?.isEmpty ?? false) const EmptyDirectoryCard(),
              if (events.isNotEmpty) ...[
                DropdownButtonFormField<String>(
                  initialValue: selectedEventId,
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: s.t('entry.event'),
                    prefixIcon: const Icon(Icons.event_outlined),
                  ),
                  items: events
                      .map(
                        (event) => DropdownMenuItem(
                          value: event.eventId,
                          child: Text(
                            s.t('entry.eventOption', {
                              'event': s.eventLabel(
                                event.eventNumber,
                                event.eventName,
                              ),
                              'status': s.runStatus(event.eventStatus),
                            }),
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) =>
                      setState(() => _selectedEventId = value),
                ),
                const SizedBox(height: 20),
                Text(
                  s.t('entry.responder'),
                  style: Theme.of(context).textTheme.labelSmall
                      ?.copyWith(color: muted, letterSpacing: 1.3),
                ),
                const SizedBox(height: 10),
              ],
              ...visibleResponders.map(
                (responder) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: ResponderTile(
                    responder: responder,
                    onTap: () => _select(responder),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              const DemoNotice(),
            ],
          ),
        ),
      ),
    );
  }
}

class MissionHome extends StatefulWidget {
  const MissionHome({
    super.key,
    required this.gateway,
    required this.responder,
    required this.onSwitch,
    this.onChangeServer,
  });
  final ResponderGateway gateway;
  final Responder responder;
  final VoidCallback onSwitch;
  final VoidCallback? onChangeServer;
  @override
  State<MissionHome> createState() => _MissionHomeState();
}

class _MissionHomeState extends State<MissionHome> {
  Timer? _timer;
  List<Mission> _missions = const [];
  List<MissionMessage> _messages = const [];
  Object? _error;
  bool _busy = false;
  bool _loadedOnce = false;
  DateTime? _lastSyncAt;
  int _lastSeenMessageId = 0;
  bool _takeoverOpen = false;

  @override
  void initState() {
    super.initState();
    _refresh();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _refresh());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  int get _unread => _messages
      .where(
        (message) =>
            message.isInstruction && message.id > _lastSeenMessageId,
      )
      .length;

  void _markRead() {
    final newest = _messages.fold<int>(
      0,
      (id, message) => message.id > id ? message.id : id,
    );
    if (newest > _lastSeenMessageId) {
      setState(() => _lastSeenMessageId = newest);
    }
  }

  Future<void> _refresh() async {
    try {
      final previousIds = _missions.map((item) => item.id).toSet();
      final missions = await widget.gateway.missions(widget.responder.id);
      final messages = missions.isEmpty
          ? <MissionMessage>[]
          : await widget.gateway.messages(
              missions.first.id,
              widget.responder.id,
            );
      if (!mounted) return;
      final arrived = missions
          .where((item) => !previousIds.contains(item.id))
          .toList();
      final hasNewMission = _loadedOnce && arrived.isNotEmpty;
      setState(() {
        _missions = missions;
        _messages = messages;
        _error = null;
        _lastSyncAt = DateTime.now();
        if (!_loadedOnce) {
          // The first load is the read baseline, so an old thread never
          // arrives wearing an unread badge.
          _lastSeenMessageId = messages.fold<int>(
            0,
            (id, message) => message.id > id ? message.id : id,
          );
        }
        _loadedOnce = true;
      });
      if (hasNewMission) await _announce(arrived.first);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  // A dismissible toast is missed by a responder whose phone is pocketed.
  // Buzz, then take the screen until the assignment is acknowledged.
  Future<void> _announce(Mission mission) async {
    await HapticFeedback.heavyImpact();
    if (!mounted) return;
    if (_takeoverOpen || mission.acknowledged) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(L10n.of(context).t('mission.newSnack')),
          backgroundColor: const Color(0xFF174B40),
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }
    setState(() => _takeoverOpen = true);
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        fullscreenDialog: true,
        builder: (_) => NewAssignmentScreen(
          mission: mission,
          onAcknowledge: () => _act(
            () => widget.gateway.acknowledge(mission.id, widget.responder.id),
          ),
        ),
      ),
    );
    if (mounted) setState(() => _takeoverOpen = false);
  }

  Future<void> _act(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await action();
      await _refresh();
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _acknowledge(Mission mission) => _act(
    () => widget.gateway.acknowledge(mission.id, widget.responder.id),
  );

  Future<void> _report(Mission mission, String kind, String body) =>
      _act(() => widget.gateway.send(mission.id, widget.responder.id, kind, body));

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    final mission = _missions.firstOrNull;
    final keyboardOpen = MediaQuery.of(context).viewInsets.bottom > 0;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
            children: [
              BrandHeader(
                label: widget.responder.name.toUpperCase(),
                onAccount: widget.onSwitch,
                onServer: widget.onChangeServer,
              ),
              const SizedBox(height: 28),
              const DemoNotice(),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: ErrorCard(error: _error!, onRetry: _refresh),
                ),
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    s.t('mission.current'),
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  ConnectionPill(
                    lastSyncAt: _lastSyncAt,
                    offline: _error != null,
                  ),
                ],
              ),
              const SizedBox(height: 14),
              if (!_loadedOnce)
                const Center(
                  child: Padding(
                    padding: EdgeInsets.all(40),
                    child: CircularProgressIndicator(),
                  ),
                ),
              if (_loadedOnce && mission == null) const EmptyMissionCard(),
              if (mission != null) ...[
                MissionCard(mission: mission),
                const SizedBox(height: 24),
                Row(
                  children: [
                    Text(
                      s.t('mission.channel'),
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    if (_unread > 0) ...[
                      const SizedBox(width: 10),
                      UnreadBadge(count: _unread),
                    ],
                  ],
                ),
                const SizedBox(height: 12),
                MissionThread(
                  messages: _messages,
                  disabled: _busy,
                  onSeen: _markRead,
                  onSend: (body) => _report(mission, 'message', body),
                ),
              ],
              const SizedBox(height: 28),
              Center(
                child: Text(
                  s.t('mission.refreshNote'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: muted, fontSize: 12),
                ),
              ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: mission == null || keyboardOpen
          ? null
          : MissionActions(
              mission: mission,
              busy: _busy,
              onAcknowledge: () => _acknowledge(mission),
              // The report body is the responder's own words, so it is sent in
              // the language they are working in; `kind` stays English because
              // the console renders progress events from it, not from the text.
              onEnRoute: () => _report(
                mission,
                'en_route',
                s.t('report.enRoute', {'zone': s.zoneLabel(mission.zoneName)}),
              ),
              onScene: () => _report(
                mission,
                'on_scene',
                s.t('report.onScene', {'zone': s.zoneLabel(mission.zoneName)}),
              ),
            ),
    );
  }
}
class BrandHeader extends StatelessWidget {
  const BrandHeader({
    super.key,
    required this.label,
    this.onAccount,
    this.onServer,
  });
  final String label;
  final VoidCallback? onAccount;
  final VoidCallback? onServer;
  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    return Row(
      children: [
        Container(
          width: 36,
          height: 36,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: mint,
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Text(
            'A',
            style: TextStyle(
              color: ink,
              fontWeight: FontWeight.w900,
              fontSize: 18,
            ),
          ),
        ),
        const SizedBox(width: 11),
        const Text(
          'AMAN',
          style: TextStyle(
            fontWeight: FontWeight.w800,
            letterSpacing: 1.6,
            fontSize: 17,
          ),
        ),
        const SizedBox(width: 10),
        Container(width: 1, height: 18, color: line),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            label,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: muted,
              // Arabic letters join; spacing them out breaks the word.
              letterSpacing: s.isRtl ? 0 : 1.3,
              fontSize: 10,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        const LanguageSwitch(),
        if (onAccount != null)
          IconButton(
            tooltip: s.t('brand.switchResponder'),
            onPressed: onAccount,
            icon: const Icon(Icons.manage_accounts_outlined),
          ),
        if (onServer != null)
          IconButton(
            tooltip: s.t('brand.changeServer'),
            onPressed: onServer,
            icon: const Icon(Icons.lan_outlined),
          ),
      ],
    );
  }
}

/// Both languages stay on screen, each written in its own script, so a
/// responder picks theirs without having to read the other one first.
class LanguageSwitch extends StatelessWidget {
  const LanguageSwitch({super.key});

  @override
  Widget build(BuildContext context) {
    final scope = L10n.maybeOf(context);
    if (scope == null) return const SizedBox.shrink();
    final active = scope.strings.language;
    return Semantics(
      label: scope.strings.t('brand.language'),
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: line),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final language in supportedLanguages)
              InkWell(
                borderRadius: BorderRadius.circular(8),
                onTap: () => scope.onChangeLanguage(language),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 9,
                    vertical: 6,
                  ),
                  child: Text(
                    languageNames[language]!,
                    style: TextStyle(
                      color: language == active ? mint : muted,
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class ResponderTile extends StatelessWidget {
  const ResponderTile({
    super.key,
    required this.responder,
    required this.onTap,
  });
  final Responder responder;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Material(
    color: surface,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: const BorderSide(color: line),
    ),
    child: InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Row(
          children: [
            CircleAvatar(
              backgroundColor: const Color(0xFF183B37),
              foregroundColor: mint,
              child: Text(responder.name.characters.first),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    responder.name,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 3),
                  Text(
                    L10n.of(context).role(responder.role),
                    style: const TextStyle(color: muted),
                  ),
                ],
              ),
            ),
            Icon(
              responder.available
                  ? Icons.arrow_forward_rounded
                  : Icons.assignment_outlined,
              color: responder.available ? mint : muted,
            ),
          ],
        ),
      ),
    ),
  );
}

class MissionCard extends StatelessWidget {
  const MissionCard({super.key, required this.mission});
  final Mission mission;

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: mission.acknowledged ? line : const Color(0xFF9C7732),
        ),
        boxShadow: const [
          BoxShadow(
            color: Color(0x33000000),
            blurRadius: 24,
            offset: Offset(0, 12),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              StatusPill(
                value: mission.acknowledged
                    ? s.t('mission.acknowledged')
                    : s.t('mission.actionRequired'),
              ),
              const Spacer(),
              if (mission.simulated)
                Text(
                  s.t('mission.rehearsal'),
                  style: TextStyle(
                    color: muted,
                    fontSize: 10,
                    letterSpacing: s.isRtl ? 0 : 1.2,
                    fontWeight: FontWeight.w700,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 24),
          Text(
            s.t('mission.reportTo'),
            style: TextStyle(
              color: muted,
              fontSize: 10,
              letterSpacing: s.isRtl ? 0 : 1.6,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 7),
          Text(
            s.zoneLabel(mission.zoneName),
            style: Theme.of(context).textTheme.headlineMedium,
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              const Icon(
                Icons.location_searching_rounded,
                color: mint,
                size: 20,
              ),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  locationCopy(s, mission),
                  style: const TextStyle(color: muted),
                ),
              ),
            ],
          ),
          if (mission.onScene) ...[
            const SizedBox(height: 22),
            SuccessStrip(text: s.t('mission.onSceneStrip')),
          ],
        ],
      ),
    );
  }
}

String locationCopy(Strings s, Mission mission) {
  if (mission.onScene) return s.t('mission.locationVerifiedArrival');
  if (mission.arrivalStatus == 'TRUE') return s.t('mission.locationVerified');
  return s.t('mission.locationProceed');
}

// The primary action is pinned to the bottom of the screen so it is never
// below the fold on a small phone, and hidden while the keyboard is up.
class MissionActions extends StatelessWidget {
  const MissionActions({
    super.key,
    required this.mission,
    required this.busy,
    required this.onAcknowledge,
    required this.onEnRoute,
    required this.onScene,
  });
  final Mission mission;
  final bool busy;
  final VoidCallback onAcknowledge;
  final VoidCallback onEnRoute;
  final VoidCallback onScene;

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    if (mission.onScene) return const SizedBox.shrink();
    return Container(
      decoration: const BoxDecoration(
        color: surface,
        border: Border(top: BorderSide(color: line)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
          child: mission.acknowledged
              ? Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: busy ? null : onEnRoute,
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size.fromHeight(52),
                          foregroundColor: Colors.white,
                          side: const BorderSide(color: line),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                          textStyle: const TextStyle(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        child: Text(s.t('action.headingThere')),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: ElevatedButton(
                        onPressed: busy ? null : onScene,
                        child: Text(s.t('action.onScene')),
                      ),
                    ),
                  ],
                )
              : ElevatedButton(
                  onPressed: busy ? null : onAcknowledge,
                  child: Text(
                    busy ? s.t('action.updating') : s.t('action.acknowledge'),
                  ),
                ),
        ),
      ),
    );
  }
}

class NewAssignmentScreen extends StatefulWidget {
  const NewAssignmentScreen({
    super.key,
    required this.mission,
    required this.onAcknowledge,
  });
  final Mission mission;
  final Future<void> Function() onAcknowledge;
  @override
  State<NewAssignmentScreen> createState() => _NewAssignmentScreenState();
}

class _NewAssignmentScreenState extends State<NewAssignmentScreen> {
  static const _maxPulses = 6;
  Timer? _pulse;
  int _pulses = 0;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _pulse = Timer.periodic(const Duration(seconds: 3), (timer) {
      if (!mounted || _pulses >= _maxPulses) {
        timer.cancel();
        return;
      }
      _pulses += 1;
      HapticFeedback.heavyImpact();
    });
  }

  @override
  void dispose() {
    _pulse?.cancel();
    super.dispose();
  }

  Future<void> _acknowledge() async {
    setState(() => _busy = true);
    await widget.onAcknowledge();
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    return Scaffold(
      backgroundColor: ink,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  StatusPill(value: s.t('mission.new')),
                  const Spacer(),
                  if (widget.mission.simulated)
                    Text(
                      s.t('mission.rehearsal'),
                      style: TextStyle(
                        color: muted,
                        fontSize: 10,
                        letterSpacing: s.isRtl ? 0 : 1.2,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                ],
              ),
              const Spacer(flex: 2),
              const Icon(
                Icons.notifications_active_rounded,
                color: Color(0xFFEBC56E),
                size: 34,
              ),
              const SizedBox(height: 20),
              Text(
                s.t('mission.reportTo'),
                style: TextStyle(
                  color: muted,
                  fontSize: 11,
                  letterSpacing: s.isRtl ? 0 : 1.8,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                s.zoneLabel(widget.mission.zoneName),
                style: TextStyle(
                  fontSize: 40,
                  height: 1.1,
                  fontWeight: FontWeight.w800,
                  letterSpacing: s.isRtl ? 0 : -1.2,
                ),
              ),
              const SizedBox(height: 18),
              Row(
                children: [
                  const Icon(
                    Icons.location_searching_rounded,
                    color: mint,
                    size: 20,
                  ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: Text(
                      locationCopy(s, widget.mission),
                      style: const TextStyle(color: muted),
                    ),
                  ),
                ],
              ),
              const Spacer(flex: 3),
              ElevatedButton(
                onPressed: _busy ? null : _acknowledge,
                child: Text(
                  _busy ? s.t('action.updating') : s.t('action.acknowledge'),
                ),
              ),
              const SizedBox(height: 8),
              TextButton(
                onPressed: _busy ? null : () => Navigator.of(context).pop(),
                child: Text(s.t('action.viewDetails')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
class MissionThread extends StatefulWidget {
  const MissionThread({
    super.key,
    required this.messages,
    required this.disabled,
    required this.onSend,
    required this.onSeen,
  });
  final List<MissionMessage> messages;
  final bool disabled;
  final ValueChanged<String> onSend;
  final VoidCallback onSeen;
  @override
  State<MissionThread> createState() => _MissionThreadState();
}

class _MissionThreadState extends State<MissionThread> {
  final _controller = TextEditingController();
  final _scroll = ScrollController();

  @override
  void didUpdateWidget(MissionThread oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.messages.length != oldWidget.messages.length) _scrollToEnd();
  }

  @override
  void dispose() {
    _controller.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      _scroll.animateTo(
        _scroll.position.maxScrollExtent,
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
      );
    });
  }

  void _send() {
    final value = _controller.text.trim();
    if (value.isEmpty) return;
    widget.onSeen();
    widget.onSend(value);
    _controller.clear();
  }

  static String _clock(DateTime? at) {
    if (at == null) return '';
    final local = at.toLocal();
    return '${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
  }

  Widget _entry(Strings s, MissionMessage message) {
    // Progress events are the responder's own state changes, not conversation.
    if (!message.isInstruction && message.kind != 'message') {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Center(
          child: Text(
            s.t('thread.entry', {
              'sender': s.kind(message.kind),
              'time': _clock(message.createdAt),
            }),
            style: TextStyle(
              color: muted,
              fontSize: 11,
              letterSpacing: s.isRtl ? 0 : 0.6,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      );
    }
    final fromControl = message.isInstruction;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: fromControl
            ? CrossAxisAlignment.start
            : CrossAxisAlignment.end,
        children: [
          Text(
            s.t('thread.entry', {
              'sender': fromControl ? s.t('thread.controlRoom') : s.t('thread.you'),
              'time': _clock(message.createdAt),
            }),
            style: const TextStyle(color: muted, fontSize: 11),
          ),
          const SizedBox(height: 4),
          ConstrainedBox(
            constraints: BoxConstraints(
              maxWidth: MediaQuery.of(context).size.width * 0.7,
            ),
            child: Container(
              padding: const EdgeInsets.symmetric(
                horizontal: 13,
                vertical: 10,
              ),
              decoration: BoxDecoration(
                color: fromControl
                    ? const Color(0xFF17213A)
                    : const Color(0xFF173B36),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(message.body),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: line),
      ),
      child: Column(
        children: [
          if (widget.messages.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 18),
              child: Text(
                s.t('thread.empty'),
                style: const TextStyle(color: muted),
              ),
            )
          else
            // The thread scrolls inside its own box so a long conversation
            // never pushes the assignment off the screen.
            GestureDetector(
              behavior: HitTestBehavior.translucent,
              onTap: widget.onSeen,
              child: NotificationListener<ScrollNotification>(
                onNotification: (_) {
                  widget.onSeen();
                  return false;
                },
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxHeight: 280),
                  child: ListView(
                    controller: _scroll,
                    shrinkWrap: true,
                    padding: EdgeInsets.zero,
                    children: widget.messages
                        .map((message) => _entry(s, message))
                        .toList(),
                  ),
                ),
              ),
            ),
          const Divider(color: line, height: 28),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _controller,
                  enabled: !widget.disabled,
                  textInputAction: TextInputAction.send,
                  onTap: widget.onSeen,
                  onSubmitted: (_) => _send(),
                  decoration: InputDecoration(
                    hintText: s.t('thread.hint'),
                    isDense: true,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                tooltip: s.t('thread.send'),
                onPressed: widget.disabled ? null : _send,
                icon: const Icon(Icons.arrow_upward_rounded),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
class DemoNotice extends StatelessWidget {
  const DemoNotice({super.key});
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 11),
    decoration: BoxDecoration(
      color: const Color(0xFF13251F),
      borderRadius: BorderRadius.circular(10),
      border: Border.all(color: const Color(0xFF285344)),
    ),
    child: Row(
      children: [
        const Icon(Icons.science_outlined, color: mint, size: 18),
        const SizedBox(width: 9),
        Expanded(
          child: Text(
            L10n.of(context).t('demo.notice'),
            style: const TextStyle(
              fontSize: 12,
              color: Color(0xFFB7CFC7),
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    ),
  );
}

class EmptyMissionCard extends StatelessWidget {
  const EmptyMissionCard({super.key});
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 44),
    decoration: BoxDecoration(
      color: surface,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: line),
    ),
    child: Column(
      children: [
        const Icon(Icons.check_circle_outline_rounded, color: mint, size: 38),
        const SizedBox(height: 14),
        Text(
          L10n.of(context).t('mission.emptyTitle'),
          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 7),
        Text(
          L10n.of(context).t('mission.emptyBody'),
          textAlign: TextAlign.center,
          style: const TextStyle(color: muted),
        ),
      ],
    ),
  );
}

class EmptyDirectoryCard extends StatelessWidget {
  const EmptyDirectoryCard({super.key});
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 36),
    decoration: BoxDecoration(
      color: surface,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: line),
    ),
    child: Column(
      children: [
        const Icon(Icons.event_busy_outlined, color: muted, size: 34),
        const SizedBox(height: 12),
        Text(
          L10n.of(context).t('directory.emptyTitle'),
          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 6),
        Text(
          L10n.of(context).t('directory.emptyBody'),
          textAlign: TextAlign.center,
          style: const TextStyle(color: muted),
        ),
      ],
    ),
  );
}

class ErrorCard extends StatelessWidget {
  const ErrorCard({super.key, required this.error, required this.onRetry});

  /// The failure itself, not its text: the app translates the ones it raised
  /// and passes a backend message through as it arrived.
  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: const Color(0xFF351C26),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF673242)),
      ),
      child: Row(
        children: [
          Expanded(child: Text(describeError(s, error))),
          TextButton(onPressed: onRetry, child: Text(s.t('common.retry'))),
        ],
      ),
    );
  }
}

// Reports what the poll loop actually did. A hardcoded "CONNECTED" is worse
// than no pill at all when the responder is out of coverage.
class ConnectionPill extends StatefulWidget {
  const ConnectionPill({
    super.key,
    required this.lastSyncAt,
    required this.offline,
  });
  final DateTime? lastSyncAt;
  final bool offline;
  @override
  State<ConnectionPill> createState() => _ConnectionPillState();
}

class _ConnectionPillState extends State<ConnectionPill> {
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  static String _ago(Strings s, Duration age) {
    if (age.inSeconds < 5) return s.t('link.justNow');
    if (age.inSeconds < 60) return s.t('link.secondsAgo', {'count': age.inSeconds});
    if (age.inMinutes < 60) return s.t('link.minutesAgo', {'count': age.inMinutes});
    return s.t('link.hoursAgo', {'count': age.inHours});
  }

  @override
  Widget build(BuildContext context) {
    final s = L10n.of(context);
    final syncedAt = widget.lastSyncAt;
    final age = syncedAt == null ? null : DateTime.now().difference(syncedAt);
    const amber = Color(0xFFEBC56E);
    final Color colour;
    final String label;
    if (age == null) {
      colour = muted;
      label = s.t('link.connecting');
    } else if (widget.offline) {
      colour = amber;
      label = age.inSeconds > 60 ? s.t('link.offline') : s.t('link.reconnecting');
    } else if (age.inSeconds > 20) {
      colour = amber;
      label = s.t('link.delayed');
    } else {
      colour = mint;
      label = s.t('link.live');
    }
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.circle, size: 8, color: colour),
        const SizedBox(width: 7),
        Text(
          age == null
              ? label
              : s.t('link.state', {'state': label, 'age': _ago(s, age)}),
          style: TextStyle(
            color: muted,
            fontSize: 10,
            letterSpacing: s.isRtl ? 0 : 1.1,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    );
  }
}

class UnreadBadge extends StatelessWidget {
  const UnreadBadge({super.key, required this.count});
  final int count;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(
      color: const Color(0xFF2E291B),
      borderRadius: BorderRadius.circular(10),
    ),
    child: Text(
      L10n.of(context).t('thread.unread', {'count': count}),
      style: TextStyle(
        color: const Color(0xFFEBC56E),
        fontSize: 10,
        letterSpacing: L10n.of(context).isRtl ? 0 : 1,
        fontWeight: FontWeight.w800,
      ),
    ),
  );
}
class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.value});
  final String value;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    decoration: BoxDecoration(
      color: const Color(0xFF2E291B),
      borderRadius: BorderRadius.circular(6),
    ),
    child: Text(
      value,
      style: TextStyle(
        color: const Color(0xFFEBC56E),
        fontSize: 10,
        letterSpacing: L10n.of(context).isRtl ? 0 : 1,
        fontWeight: FontWeight.w800,
      ),
    ),
  );
}

class SuccessStrip extends StatelessWidget {
  const SuccessStrip({super.key, required this.text});
  final String text;
  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(13),
    decoration: BoxDecoration(
      color: const Color(0xFF173B36),
      borderRadius: BorderRadius.circular(10),
    ),
    child: Row(
      children: [
        const Icon(Icons.verified_outlined, color: mint),
        const SizedBox(width: 9),
        Expanded(child: Text(text)),
      ],
    ),
  );
}
