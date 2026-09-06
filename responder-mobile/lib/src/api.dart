import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import 'models.dart';

abstract class ResponderGateway {
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
}

class AmanApi implements ResponderGateway {
  AmanApi({http.Client? client}) : _client = client ?? http.Client();

  static const _configuredUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1/demo',
  );
  final http.Client _client;
  final Uuid _uuid = const Uuid();

  String get _baseUrl => _configuredUrl.replaceFirst(RegExp(r'/$'), '');

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
      final response = method == 'GET'
          ? await _client
                .get(uri, headers: headers)
                .timeout(const Duration(seconds: 15))
          : await _client
                .post(uri, headers: headers, body: jsonEncode(body))
                .timeout(const Duration(seconds: 15));
      final payload = jsonDecode(response.body) as Map<String, dynamic>;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw ApiException(
          payload['message'] as String? ??
              'The request could not be completed.',
        );
      }
      return payload;
    } on ApiException {
      rethrow;
    } on TimeoutException {
      throw const ApiException('The connection timed out. Try again.');
    } on http.ClientException {
      throw const ApiException('AMAN could not reach the server.');
    } on FormatException {
      throw const ApiException('The server returned an unexpected response.');
    }
  }
}

class ApiException implements Exception {
  const ApiException(this.message);
  final String message;
  @override
  String toString() => message;
}
