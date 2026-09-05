import 'package:flutter/material.dart';

import 'src/api.dart';
import 'src/responder_app.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(AmanResponderApp(gateway: AmanApi()));
}
