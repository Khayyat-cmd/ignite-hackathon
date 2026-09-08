import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import 'api.dart';

class PushNotifications {
  PushNotifications._(this._messaging);

  static const _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const _senderId = String.fromEnvironment(
    'FIREBASE_MESSAGING_SENDER_ID',
  );
  static const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const _deviceIdKey = 'push_device_id';

  final FirebaseMessaging? _messaging;
  final StreamController<void> _events = StreamController<void>.broadcast();
  StreamSubscription<String>? _tokenSubscription;
  StreamSubscription<RemoteMessage>? _messageSubscription;
  StreamSubscription<RemoteMessage>? _openedSubscription;
  ResponderGateway? _gateway;
  String? _responderId;
  String? _deviceId;

  Stream<void> get events => _events.stream;
  bool get isEnabled => _messaging != null;

  static Future<PushNotifications> initialize() async {
    if (kIsWeb || (!Platform.isAndroid && !Platform.isIOS)) {
      return PushNotifications._(null);
    }
    try {
      final hasExplicitOptions = [
        _apiKey,
        _appId,
        _senderId,
        _projectId,
      ].every((value) => value.isNotEmpty);
      await Firebase.initializeApp(
        options: hasExplicitOptions
            ? const FirebaseOptions(
                apiKey: _apiKey,
                appId: _appId,
                messagingSenderId: _senderId,
                projectId: _projectId,
              )
            : null,
      );
      final messaging = FirebaseMessaging.instance;
      await messaging.setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );
      return PushNotifications._(messaging);
    } catch (_) {
      return PushNotifications._(null);
    }
  }

  Future<void> activate(ResponderGateway gateway, String responderId) async {
    final messaging = _messaging;
    if (messaging == null) return;
    _gateway = gateway;
    _responderId = responderId;
    final preferences = await SharedPreferences.getInstance();
    _deviceId = preferences.getString(_deviceIdKey) ?? const Uuid().v4();
    await preferences.setString(_deviceIdKey, _deviceId!);

    await messaging.requestPermission(alert: true, badge: true, sound: true);
    final token = await messaging.getToken();
    if (token != null) await _register(token);
    await _tokenSubscription?.cancel();
    _tokenSubscription = messaging.onTokenRefresh.listen(_register);
    _messageSubscription ??= FirebaseMessaging.onMessage.listen(
      (_) => _events.add(null),
    );
    _openedSubscription ??= FirebaseMessaging.onMessageOpenedApp.listen(
      (_) => _events.add(null),
    );
    if (await messaging.getInitialMessage() != null) _events.add(null);
  }

  Future<void> deactivate() async {
    final gateway = _gateway;
    final responderId = _responderId;
    final deviceId = _deviceId;
    _gateway = null;
    _responderId = null;
    if (gateway != null && responderId != null && deviceId != null) {
      try {
        await gateway.unregisterPushToken(deviceId, responderId);
      } catch (_) {
        // A later registration moves this device token to the selected profile.
      }
    }
  }

  Future<void> _register(String token) async {
    final gateway = _gateway;
    final responderId = _responderId;
    final deviceId = _deviceId;
    if (gateway == null || responderId == null || deviceId == null) return;
    await gateway.registerPushToken(
      deviceId,
      responderId,
      Platform.isIOS ? 'ios' : 'android',
      token,
    );
  }
}
