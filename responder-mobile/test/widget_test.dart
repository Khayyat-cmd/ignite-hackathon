import 'package:aman_responder/src/api.dart';
import 'package:aman_responder/src/models.dart';
import 'package:aman_responder/src/responder_app.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

class FakeGateway implements ResponderGateway {
  @override
  bool get supportsServerConfiguration => false;
  @override
  void configureServer(String baseUrl) {}
  @override
  Future<void> acknowledge(String missionId, String responderId) async {}
  @override
  Future<List<MissionMessage>> messages(
    String missionId,
    String responderId,
  ) async => const [];
  @override
  Future<List<Mission>> missions(String responderId) async => const [];
  @override
  Future<List<Responder>> responders() async => const [
    Responder(
      id: 'r1',
      name: 'East Marshal',
      role: 'crowd_marshal',
      available: true,
      eventId: 'event-1',
      eventName: 'Stadium rehearsal',
      eventNumber: 3,
      eventStatus: 'running',
    ),
    Responder(
      id: 'r2',
      name: 'East Marshal',
      role: 'crowd_marshal',
      available: true,
      eventId: 'event-2',
      eventName: 'Older rehearsal',
      eventNumber: 2,
      eventStatus: 'stopped',
    ),
  ];
  @override
  Future<void> send(
    String missionId,
    String responderId,
    String kind,
    String body,
  ) async {}
  @override
  Future<void> registerPushToken(
    String deviceId,
    String responderId,
    String platform,
    String token,
  ) async {}
  @override
  Future<void> unregisterPushToken(String deviceId, String responderId) async {}
}

class MissionGateway extends FakeGateway {
  MissionGateway({required this.mission, this.thread = const []});
  final Mission mission;
  final List<MissionMessage> thread;
  String? acknowledged;

  @override
  Future<List<Mission>> missions(String responderId) async => [mission];
  @override
  Future<List<MissionMessage>> messages(
    String missionId,
    String responderId,
  ) async => thread;
  @override
  Future<void> acknowledge(String missionId, String responderId) async =>
      acknowledged = missionId;
}

const onDuty = Responder(
  id: 'r1',
  name: 'East Marshal',
  role: 'crowd_marshal',
  available: false,
  eventId: 'event-1',
  eventName: 'Stadium rehearsal',
  eventNumber: 3,
  eventStatus: 'running',
);

Future<void> pumpMission(WidgetTester tester, MissionGateway gateway) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: responderTheme,
      home: MissionHome(gateway: gateway, responder: onDuty, onSwitch: () {}),
    ),
  );
  await tester.pump();
  await tester.pump();
}

void main() {
  test('a build with a baked API URL never asks for a server address', () {
    // The APK handed to responders is built with --dart-define=API_URL, and that
    // alone must retire the setup screen; no second flag has to be remembered.
    const baked = String.fromEnvironment('API_URL');
    expect(AmanApi().supportsServerConfiguration, baked.isEmpty);
  });

  testWidgets('shows the demo responder directory', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(AmanResponderApp(gateway: FakeGateway()));
    await tester.pumpAndSettle();

    expect(find.text('Choose your profile'), findsOneWidget);
    expect(find.text('Event #3 · Stadium rehearsal · RUNNING'), findsOneWidget);
    expect(find.text('East Marshal'), findsOneWidget);
    expect(find.textContaining('REHEARSAL'), findsOneWidget);
    expect(find.textContaining('counter'), findsNothing);
  });

  testWidgets('pins the acknowledge action and reports real link state', (
    tester,
  ) async {
    final gateway = MissionGateway(
      mission: const Mission(
        id: 'm1',
        status: 'dispatched',
        zoneName: 'East Entrance',
        simulated: true,
      ),
    );
    await pumpMission(tester, gateway);

    expect(find.text('ACKNOWLEDGE ASSIGNMENT'), findsOneWidget);
    expect(find.textContaining('LIVE'), findsOneWidget);
    expect(find.text('CONNECTED'), findsNothing);

    await tester.tap(find.text('ACKNOWLEDGE ASSIGNMENT'));
    await tester.pump();
    await tester.pump();
    expect(gateway.acknowledged, 'm1');

    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('labels thread senders and separates progress events', (
    tester,
  ) async {
    final gateway = MissionGateway(
      mission: const Mission(
        id: 'm1',
        status: 'acknowledged',
        zoneName: 'East Entrance',
        simulated: true,
      ),
      thread: [
        MissionMessage(
          id: 1,
          kind: 'instruction',
          body: 'Open the alternate lane.',
          createdAt: DateTime.utc(2026, 9, 5, 12),
        ),
        MissionMessage(
          id: 2,
          kind: 'en_route',
          body: 'Heading to East Entrance.',
          createdAt: DateTime.utc(2026, 9, 5, 12, 1),
        ),
        MissionMessage(
          id: 3,
          kind: 'message',
          body: 'Lane is open.',
          createdAt: DateTime.utc(2026, 9, 5, 12, 2),
        ),
      ],
    );
    await pumpMission(tester, gateway);

    expect(find.textContaining('Control room ·'), findsOneWidget);
    expect(find.textContaining('You ·'), findsOneWidget);
    expect(find.textContaining('Heading there ·'), findsOneWidget);
    expect(find.text('Heading to East Entrance.'), findsNothing);
    expect(find.text('HEADING THERE'), findsOneWidget);
    expect(find.text('ON SCENE'), findsOneWidget);

    await tester.pumpWidget(const SizedBox());
  });
}
