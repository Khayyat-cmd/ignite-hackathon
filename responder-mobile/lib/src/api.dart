import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import 'models.dart';

abstract class ResponderGateway {
  bool get supportsServerConfiguration => false;
  void configureServer(String baseUrl) {}
  Future<List<Responder>> responders();
  Future<List<Mission>> missions(String responderId);
  Future<List<MissionMessage>> messages(String missionId, String responderId);
  Future<void> acknowledge(String missionId, String responderId);
  Future<void> send(
    String missionId,
    String responderId,
    String kind,
    String body,
  );
  Future<void> registerPushToken(
    String deviceId,
    String responderId,
    String platform,
    String token,
  ) async {}
  Future<void> unregisterPushToken(String deviceId, String responderId) async {}
}

class AmanApi implements ResponderGateway {
  AmanApi({http.Client? client})
    : _client = client ?? http.Client(),
      _baseUrl = _configuredUrl.replaceFirst(RegExp(r'/$'), '');

  static const _emulatorUrl = 'http://10.0.2.2:8000/api/v1/demo';
  static const _configuredUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: _emulatorUrl,
  );
  // A build that carries a real API_URL is a build handed to someone else, so it
  // never asks for a server address. Development builds keep the setup screen.
  static const _allowServerConfiguration = bool.fromEnvironment(
    'ALLOW_SERVER_CONFIGURATION',
    defaultValue: _configuredUrl == _emulatorUrl,
  );
  final http.Client _client;
  final Uuid _uuid = const Uuid();
  String _baseUrl;

  @override
  bool get supportsServerConfiguration => _allowServerConfiguration;

  @override
  void configureServer(String baseUrl) {
    _baseUrl = baseUrl.replaceFirst(RegExp(r'/$'), '');
  }

  @override
  Future<List<Responder>> responders() async {
    final payload = await _request('GET', '/responders');
    return (payload['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(Responder.fromJson)
        .toList();
  }

  @override
  Future<List<Mission>> missions(String responderId) async {
    final payload = await _request(
      'GET',
      '/missions?responderId=${Uri.encodeQueryComponent(responderId)}',
    );
    return (payload['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(Mission.fromJson)
        .toList();
  }

  @override
  Future<List<MissionMessage>> messages(
    String missionId,
    String responderId,
  ) async {
    final payload = await _request(
      'GET',
      '/missions/$missionId/messages?responderId=${Uri.encodeQueryComponent(responderId)}',
    );
    return (payload['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(MissionMessage.fromJson)
        .toList();
  }

  @override
  Future<void> acknowledge(String missionId, String responderId) => _request(
    'POST',
    '/missions/$missionId/acknowledge',
    body: {'responderId': responderId},
  );

  @override
  Future<void> send(
    String missionId,
    String responderId,
    String kind,
    String body,
  ) => _request(
    'POST',
    '/missions/$missionId/messages',
    body: {
      'responderId': responderId,
      'clientMessageId': _uuid.v4(),
      'kind': kind,
      'body': body,
    },
  );

  @override
  Future<void> registerPushToken(
    String deviceId,
    String responderId,
    String platform,
    String token,
  ) => _request(
    'PUT',
    '/devices/${Uri.encodeComponent(deviceId)}/push-token',
    body: {'responderId': responderId, 'platform': platform, 'token': token},
  );

  @override
  Future<void> unregisterPushToken(String deviceId, String responderId) =>
      _request(
        'DELETE',
        '/devices/${Uri.encodeComponent(deviceId)}/push-token',
        body: {'responderId': responderId},
      );

  Future<Map<String, dynamic>> _request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    final uri = Uri.parse('$_baseUrl$path');
    final headers = {
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json',
    };
    try {
      final response = await (switch (method) {
        'GET' => _client.get(uri, headers: headers),
        'PUT' => _client.put(uri, headers: headers, body: jsonEncode(body)),
        'DELETE' => _client.delete(
          uri,
          headers: headers,
          body: jsonEncode(body),
        ),
        _ => _client.post(uri, headers: headers, body: jsonEncode(body)),
      }).timeout(const Duration(seconds: 15));
      final payload = response.body.isEmpty
          ? <String, dynamic>{}
          : jsonDecode(response.body) as Map<String, dynamic>;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        // A backend message is already specific; only the transport failures
        // below carry a code the app can say in the responder's language.
        final message = payload['message'] as String?;
        throw message == null
            ? const ApiException(
                'The request could not be completed.',
                code: 'incomplete',
              )
            : ApiException(message);
      }
      return payload;
    } on ApiException {
      rethrow;
    } on TimeoutException {
      throw const ApiException(
        'The connection timed out. Try again.',
        code: 'timeout',
      );
    } on http.ClientException {
      throw const ApiException(
        'AMAN could not reach the server.',
        code: 'unreachable',
      );
    } on FormatException {
      throw const ApiException(
        'The server returned an unexpected response.',
        code: 'malformed',
      );
    }
  }
}

class ApiException implements Exception {
  const ApiException(this.message, {this.code});

  final String message;

  /// Set for failures the app raises itself, so the UI can show them in the
  /// responder's language. Null when the text came from the backend.
  final String? code;

  @override
  String toString() => message;
}
