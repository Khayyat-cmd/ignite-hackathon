import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
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

class AmanResponderApp extends StatelessWidget {
  const AmanResponderApp({super.key, required this.gateway});
  final ResponderGateway gateway;

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    title: 'AMAN Responder',
    theme: responderTheme,
    home: ServerGate(gateway: gateway),
  );
}

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
  String? _error;
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
      setState(() => _error = 'Enter a valid IP address or server URL.');
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
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 34, 24, 24),
        children: [
          const BrandHeader(label: 'FIELD RESPONSE'),
          const SizedBox(height: 56),
          Text(
            'Connect to AMAN',
            style: Theme.of(context).textTheme.headlineMedium,
          ),
          const SizedBox(height: 10),
          const Text(
            'Enter the IP address shown as “Phone API” when the operations console starts.',
            style: TextStyle(color: muted),
          ),
          const SizedBox(height: 28),
          TextField(
            controller: _controller,
            enabled: !_busy,
            keyboardType: TextInputType.url,
            autocorrect: false,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _connect(),
            decoration: const InputDecoration(
              labelText: 'Computer IP address',
              hintText: '192.168.1.20',
              prefixIcon: Icon(Icons.lan_outlined),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: Color(0xFFF09A9A))),
          ],
          const SizedBox(height: 18),
          ElevatedButton(
            onPressed: _busy ? null : _connect,
            child: Text(_busy ? 'CONNECTING…' : 'CONNECT'),
          ),
          if (widget.onCancel != null) ...[
            const SizedBox(height: 8),
            TextButton(
              onPressed: _busy ? null : widget.onCancel,
              child: const Text('CANCEL'),
            ),
          ],
          const SizedBox(height: 16),
          const Text(
            'The phone and computer must be on the same Wi-Fi network.',
            textAlign: TextAlign.center,
            style: TextStyle(color: muted, fontSize: 12),
          ),
        ],
      ),
    ),
  );
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
  String? _error;

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
      if (mounted) setState(() => _error = error.toString());
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
                label: 'FIELD RESPONSE',
                onServer: widget.onChangeServer,
              ),
              const SizedBox(height: 48),
              Text(
                'Choose your profile',
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              const SizedBox(height: 10),
              const Text(
                'Select the current event, then choose your name.',
                style: TextStyle(color: muted),
              ),
              const SizedBox(height: 28),
              if (_responders == null && _error == null)
                const Center(child: CircularProgressIndicator()),
              if (_error != null) ErrorCard(message: _error!, onRetry: _load),
              if (_responders?.isEmpty ?? false) const EmptyDirectoryCard(),
              if (events.isNotEmpty) ...[
                DropdownButtonFormField<String>(
                  initialValue: selectedEventId,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Rehearsal event',
                    prefixIcon: Icon(Icons.event_outlined),
                  ),
                  items: events
                      .map(
                        (event) => DropdownMenuItem(
                          value: event.eventId,
                          child: Text(
                            '${event.eventLabel} · ${event.eventStatus.toUpperCase()}',
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) =>
                      setState(() => _selectedEventId = value),
                ),
                const SizedBox(height: 20),
                Text(
                  'RESPONDER',
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
  String? _error;
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
      if (mounted) setState(() => _error = error.toString());
    }
  }

  // A dismissible toast is missed by a responder whose phone is pocketed.
  // Buzz, then take the screen until the assignment is acknowledged.
  Future<void> _announce(Mission mission) async {
    await HapticFeedback.heavyImpact();
    if (!mounted) return;
    if (_takeoverOpen || mission.acknowledged) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'New assignment received. Review the destination and acknowledge.',
          ),
          backgroundColor: Color(0xFF174B40),
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
      if (mounted) setState(() => _error = error.toString());
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
                  child: ErrorCard(message: _error!, onRetry: _refresh),
                ),
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Current assignment',
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
                      'Mission channel',
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
                  'Refreshes every 5 seconds · Pull down to refresh',
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
              onEnRoute: () => _report(
                mission,
                'en_route',
                'Heading to ${mission.zoneName}.',
              ),
              onScene: () => _report(
                mission,
                'on_scene',
                'On scene at ${mission.zoneName}. Beginning crowd response.',
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
  Widget build(BuildContext context) => Row(
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
          style: const TextStyle(
            color: muted,
            letterSpacing: 1.3,
            fontSize: 10,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      if (onAccount != null)
        IconButton(
          tooltip: 'Switch responder',
          onPressed: onAccount,
          icon: const Icon(Icons.manage_accounts_outlined),
        ),
      if (onServer != null)
        IconButton(
          tooltip: 'Change server',
          onPressed: onServer,
          icon: const Icon(Icons.lan_outlined),
        ),
    ],
  );
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
                    responder.roleLabel,
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
  Widget build(BuildContext context) => Container(
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
              value: mission.acknowledged ? 'ACKNOWLEDGED' : 'ACTION REQUIRED',
            ),
            const Spacer(),
            if (mission.simulated)
              const Text(
                'REHEARSAL',
                style: TextStyle(
                  color: muted,
                  fontSize: 10,
                  letterSpacing: 1.2,
                  fontWeight: FontWeight.w700,
                ),
              ),
          ],
        ),
        const SizedBox(height: 24),
        const Text(
          'REPORT TO',
          style: TextStyle(
            color: muted,
            fontSize: 10,
            letterSpacing: 1.6,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 7),
        Text(
          mission.zoneName,
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        const SizedBox(height: 18),
        Row(
          children: [
            const Icon(Icons.location_searching_rounded, color: mint, size: 20),
            const SizedBox(width: 9),
            Expanded(
              child: Text(
                locationCopy(mission),
                style: const TextStyle(color: muted),
              ),
            ),
          ],
        ),
        if (mission.onScene) ...[
          const SizedBox(height: 22),
          const SuccessStrip(text: 'On scene · Crowd response in progress'),
        ],
      ],
    ),
  );
}

String locationCopy(Mission mission) {
  if (mission.onScene) return 'Arrival verified for the assigned area.';
  if (mission.arrivalStatus == 'TRUE') {
    return 'Location verified. Confirm when you are ready to begin.';
  }
  return 'Proceed to the assigned area. Arrival will be verified automatically.';
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
                        child: const Text('HEADING THERE'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: ElevatedButton(
                        onPressed: busy ? null : onScene,
                        child: const Text('ON SCENE'),
                      ),
                    ),
                  ],
                )
              : ElevatedButton(
                  onPressed: busy ? null : onAcknowledge,
                  child: Text(
                    busy ? 'UPDATING…' : 'ACKNOWLEDGE ASSIGNMENT',
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
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: ink,
    body: SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const StatusPill(value: 'NEW ASSIGNMENT'),
                const Spacer(),
                if (widget.mission.simulated)
                  const Text(
                    'REHEARSAL',
                    style: TextStyle(
                      color: muted,
                      fontSize: 10,
                      letterSpacing: 1.2,
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
            const Text(
              'REPORT TO',
              style: TextStyle(
                color: muted,
                fontSize: 11,
                letterSpacing: 1.8,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 10),
            Text(
              widget.mission.zoneName,
              style: const TextStyle(
                fontSize: 40,
                height: 1.1,
                fontWeight: FontWeight.w800,
                letterSpacing: -1.2,
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
                    locationCopy(widget.mission),
                    style: const TextStyle(color: muted),
                  ),
                ),
              ],
            ),
            const Spacer(flex: 3),
            ElevatedButton(
              onPressed: _busy ? null : _acknowledge,
              child: Text(_busy ? 'UPDATING…' : 'ACKNOWLEDGE ASSIGNMENT'),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: _busy ? null : () => Navigator.of(context).pop(),
              child: const Text('VIEW DETAILS FIRST'),
            ),
          ],
        ),
      ),
    ),
  );
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

  static String _progressLabel(String kind) => switch (kind) {
    'en_route' => 'Heading there',
    'on_scene' => 'On scene',
    _ => kind.replaceAll('_', ' '),
  };

  Widget _entry(MissionMessage message) {
    // Progress events are the responder's own state changes, not conversation.
    if (!message.isInstruction && message.kind != 'message') {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Center(
          child: Text(
            '${_progressLabel(message.kind)} · ${_clock(message.createdAt)}',
            style: const TextStyle(
              color: muted,
              fontSize: 11,
              letterSpacing: 0.6,
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
            '${fromControl ? 'Control room' : 'You'} · ${_clock(message.createdAt)}',
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
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: surface,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: line),
    ),
    child: Column(
      children: [
        if (widget.messages.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 18),
            child: Text(
              'No messages yet. Instructions from the control room will appear here.',
              style: TextStyle(color: muted),
            ),
          )
        else
          // The thread scrolls inside its own box so a long conversation never
          // pushes the assignment off the screen.
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
                  children: widget.messages.map(_entry).toList(),
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
                decoration: const InputDecoration(
                  hintText: 'Update control room',
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton.filled(
              tooltip: 'Send update',
              onPressed: widget.disabled ? null : _send,
              icon: const Icon(Icons.arrow_upward_rounded),
            ),
          ],
        ),
      ],
    ),
  );
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
    child: const Row(
      children: [
        Icon(Icons.science_outlined, color: mint, size: 18),
        SizedBox(width: 9),
        Expanded(
          child: Text(
            'REHEARSAL · Missions and locations are simulated.',
            style: TextStyle(
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
    child: const Column(
      children: [
        Icon(Icons.check_circle_outline_rounded, color: mint, size: 38),
        SizedBox(height: 14),
        Text(
          'No active assignment',
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        SizedBox(height: 7),
        Text(
          'Remain available. New assignments appear here automatically.',
          textAlign: TextAlign.center,
          style: TextStyle(color: muted),
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
    child: const Column(
      children: [
        Icon(Icons.event_busy_outlined, color: muted, size: 34),
        SizedBox(height: 12),
        Text(
          'No event available',
          style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
        ),
        SizedBox(height: 6),
        Text(
          'Start a rehearsal from the operations console, then refresh.',
          textAlign: TextAlign.center,
          style: TextStyle(color: muted),
        ),
      ],
    ),
  );
}

class ErrorCard extends StatelessWidget {
  const ErrorCard({super.key, required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(15),
    decoration: BoxDecoration(
      color: const Color(0xFF351C26),
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: const Color(0xFF673242)),
    ),
    child: Row(
      children: [
        Expanded(child: Text(message)),
        TextButton(onPressed: onRetry, child: const Text('Retry')),
      ],
    ),
  );
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

  static String _ago(Duration age) {
    if (age.inSeconds < 5) return 'just now';
    if (age.inSeconds < 60) return '${age.inSeconds}s ago';
    if (age.inMinutes < 60) return '${age.inMinutes}m ago';
    return '${age.inHours}h ago';
  }

  @override
  Widget build(BuildContext context) {
    final syncedAt = widget.lastSyncAt;
    final age = syncedAt == null ? null : DateTime.now().difference(syncedAt);
    const amber = Color(0xFFEBC56E);
    final Color colour;
    final String label;
    if (age == null) {
      colour = muted;
      label = 'CONNECTING';
    } else if (widget.offline) {
      colour = amber;
      label = age.inSeconds > 60 ? 'OFFLINE' : 'RECONNECTING';
    } else if (age.inSeconds > 20) {
      colour = amber;
      label = 'DELAYED';
    } else {
      colour = mint;
      label = 'LIVE';
    }
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.circle, size: 8, color: colour),
        const SizedBox(width: 7),
        Text(
          age == null ? label : '$label · ${_ago(age)}',
          style: const TextStyle(
            color: muted,
            fontSize: 10,
            letterSpacing: 1.1,
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
      '$count NEW',
      style: const TextStyle(
        color: Color(0xFFEBC56E),
        fontSize: 10,
        letterSpacing: 1,
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
      style: const TextStyle(
        color: Color(0xFFEBC56E),
        fontSize: 10,
        letterSpacing: 1,
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
