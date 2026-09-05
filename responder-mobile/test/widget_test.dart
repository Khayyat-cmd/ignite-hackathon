import 'package:aman_responder/src/api.dart';
import 'package:aman_responder/src/models.dart';
import 'package:aman_responder/src/responder_app.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

class FakeGateway implements ResponderGateway {
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
    ),
  ];
  @override
  Future<void> send(
    String missionId,
    String responderId,
    String kind,
    String body,
  ) async {}
}

void main() {
  testWidgets('shows the demo responder directory', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(AmanResponderApp(gateway: FakeGateway()));
    await tester.pumpAndSettle();

    expect(find.text('Select your call sign'), findsOneWidget);
    expect(find.text('East Marshal'), findsOneWidget);
    expect(find.textContaining('REHEARSAL'), findsOneWidget);
    expect(find.textContaining('counter'), findsNothing);
  });
}
