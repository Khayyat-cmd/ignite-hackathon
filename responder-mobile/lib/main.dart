import 'package:flutter/material.dart';

import 'src/api.dart';
import 'src/responder_app.dart';
import 'src/push_notifications.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final pushNotifications = await PushNotifications.initialize();
  runApp(
    AmanResponderApp(gateway: AmanApi(), pushNotifications: pushNotifications),
  );
}
