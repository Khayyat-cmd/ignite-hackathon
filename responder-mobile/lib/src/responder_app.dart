import 'dart:async';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'models.dart';

const ink = Color(0xFF080C1A);
const surface = Color(0xFF11172A);
const line = Color(0xFF28324D);
const mint = Color(0xFF4DE0BA);
const muted = Color(0xFF9CA8C2);

class AmanResponderApp extends StatelessWidget {
  const AmanResponderApp({super.key, required this.gateway});
  final ResponderGateway gateway;

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    title: 'AMAN Responder',
    theme: ThemeData(
      brightness: Brightness.dark,
      scaffoldBackgroundColor: ink,
      colorScheme: const ColorScheme.dark(
        primary: mint,
        surface: surface,
        outline: line,
      ),
      textTheme: const TextTheme(
        headlineMedium: TextStyle(
          fontWeight: FontWeight.w700,
          letterSpacing: -0.8,
        ),
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
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w800),
        ),
      ),
    ),
    home: ResponderEntry(gateway: gateway),
  );
}

class ResponderEntry extends StatefulWidget {
  const ResponderEntry({super.key, required this.gateway});
  final ResponderGateway gateway;
  @override
  State<ResponderEntry> createState() => _ResponderEntryState();
}

class _ResponderEntryState extends State<ResponderEntry> {
  static const preferenceKey = 'demo_responder_id';
  List<Responder>? _responders;
  String? _selectedId;
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
        _selectedId = responders.any((item) => item.id == saved) ? saved : null;
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
    final selected = _responders
        ?.where((item) => item.id == _selectedId)
        .firstOrNull;
    if (selected != null) {
      return MissionHome(
        gateway: widget.gateway,
        responder: selected,
        onSwitch: () => setState(() => _selectedId = null),
      );
    }
    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 34, 22, 24),
            children: [
              const BrandHeader(label: 'FIELD RESPONSE'),
              const SizedBox(height: 48),
              Text(
                'Select your call sign',
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              const SizedBox(height: 10),
              const Text(
                'This rehearsal uses local demo identities. Choose the responder assigned to your device.',
                style: TextStyle(color: muted),
              ),
              const SizedBox(height: 28),
              if (_responders == null && _error == null)
                const Center(child: CircularProgressIndicator()),
              if (_error != null) ErrorCard(onRetry: _load),
              ...?_responders?.map(
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
  });
  final ResponderGateway gateway;
  final Responder responder;
  final VoidCallback onSwitch;
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
      final hasNewMission =
          _loadedOnce && missions.any((item) => !previousIds.contains(item.id));
      setState(() {
        _missions = missions;
        _messages = messages;
        _error = null;
        _loadedOnce = true;
      });
      if (hasNewMission) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'New assignment received. Review the destination and acknowledge.',
            ),
            backgroundColor: Color(0xFF174B40),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    }
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

  @override
  Widget build(BuildContext context) {
    final mission = _missions.firstOrNull;
    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
            children: [
              BrandHeader(
                label: widget.responder.name.toUpperCase(),
                onAccount: widget.onSwitch,
              ),
              const SizedBox(height: 28),
              const DemoNotice(),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: ErrorCard(onRetry: _refresh),
                ),
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Current assignment',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const LivePill(),
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
                MissionCard(
                  mission: mission,
                  busy: _busy,
                  onAcknowledge: () => _act(
                    () => widget.gateway.acknowledge(
                      mission.id,
                      widget.responder.id,
                    ),
                  ),
                  onEnRoute: () => _act(
                    () => widget.gateway.send(
                      mission.id,
                      widget.responder.id,
                      'en_route',
                      'En route to ${mission.zoneName}.',
                    ),
                  ),
                  onScene: () => _act(
                    () => widget.gateway.send(
                      mission.id,
                      widget.responder.id,
                      'on_scene',
                      'On scene at ${mission.zoneName}. Beginning crowd response.',
                    ),
                  ),
                ),
                const SizedBox(height: 24),
                Text(
                  'Mission channel',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 12),
                MissionThread(
                  messages: _messages,
                  disabled: _busy,
                  onSend: (body) => _act(
                    () => widget.gateway.send(
                      mission.id,
                      widget.responder.id,
                      'message',
                      body,
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 28),
              const Center(
                child: Text(
                  'Refreshes every 5 seconds · Pull down to refresh',
                  style: TextStyle(color: muted, fontSize: 12),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class BrandHeader extends StatelessWidget {
  const BrandHeader({super.key, required this.label, this.onAccount});
  final String label;
  final VoidCallback? onAccount;
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
                    responder.role.replaceAll('_', ' '),
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
  const MissionCard({
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
                _locationCopy(),
                style: const TextStyle(color: muted),
              ),
            ),
          ],
        ),
        const SizedBox(height: 22),
        if (!mission.acknowledged)
          ElevatedButton(
            onPressed: busy ? null : onAcknowledge,
            child: Text(busy ? 'UPDATING…' : 'ACKNOWLEDGE ASSIGNMENT'),
          ),
        if (mission.acknowledged && !mission.onScene) ...[
          OutlinedButton.icon(
            onPressed: busy ? null : onEnRoute,
            icon: const Icon(Icons.directions_run_rounded),
            label: const Text('REPORT EN ROUTE'),
            style: OutlinedButton.styleFrom(
              minimumSize: const Size.fromHeight(48),
              foregroundColor: Colors.white,
              side: const BorderSide(color: line),
            ),
          ),
          const SizedBox(height: 10),
          ElevatedButton(
            onPressed: busy ? null : onScene,
            child: const Text('CONFIRM ON SCENE'),
          ),
        ],
        if (mission.onScene)
          const SuccessStrip(text: 'On scene · Crowd response in progress'),
      ],
    ),
  );

  String _locationCopy() {
    if (mission.onScene) return 'Arrival verified for the assigned area.';
    if (mission.arrivalStatus == 'TRUE') {
      return 'Location verified. Confirm when you are ready to begin.';
    }
    return 'Proceed to the assigned area. AMAN will verify arrival from the simulation feed.';
  }
}

class MissionThread extends StatefulWidget {
  const MissionThread({
    super.key,
    required this.messages,
    required this.disabled,
    required this.onSend,
  });
  final List<MissionMessage> messages;
  final bool disabled;
  final ValueChanged<String> onSend;
  @override
  State<MissionThread> createState() => _MissionThreadState();
}

class _MissionThreadState extends State<MissionThread> {
  final _controller = TextEditingController();
  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _send() {
    final value = _controller.text.trim();
    if (value.isEmpty) return;
    widget.onSend(value);
    _controller.clear();
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
              'No messages yet. Operational instructions will appear here.',
              style: TextStyle(color: muted),
            ),
          ),
        ...widget.messages.map(
          (message) => Align(
            alignment: message.isInstruction
                ? Alignment.centerLeft
                : Alignment.centerRight,
            child: Container(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 10),
              decoration: BoxDecoration(
                color: message.isInstruction
                    ? const Color(0xFF17213A)
                    : const Color(0xFF173B36),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(message.body),
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

class ErrorCard extends StatelessWidget {
  const ErrorCard({super.key, required this.onRetry});
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
        const Expanded(
          child: Text(
            'Connection interrupted. Pull down or retry when the backend is available.',
          ),
        ),
        TextButton(onPressed: onRetry, child: const Text('Retry')),
      ],
    ),
  );
}

class LivePill extends StatelessWidget {
  const LivePill({super.key});
  @override
  Widget build(BuildContext context) => const Row(
    children: [
      Icon(Icons.circle, size: 8, color: mint),
      SizedBox(width: 7),
      Text(
        'CONNECTED',
        style: TextStyle(
          color: muted,
          fontSize: 10,
          letterSpacing: 1.1,
          fontWeight: FontWeight.w700,
        ),
      ),
    ],
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
