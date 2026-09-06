class Responder {
  const Responder({
    required this.id,
    required this.name,
    required this.role,
    required this.available,
    required this.eventId,
    required this.eventName,
    required this.eventNumber,
    required this.eventStatus,
  });

  final String id;
  final String name;
  final String role;
  final bool available;
  final String eventId;
  final String eventName;
  final int? eventNumber;
  final String eventStatus;

  String get eventLabel =>
      eventNumber == null ? eventName : 'Event #$eventNumber · $eventName';

  String get roleLabel =>
      role == 'crowd_marshal' ? 'Crowd responder' : role.replaceAll('_', ' ');

  factory Responder.fromJson(Map<String, dynamic> json) {
    final event = json['event'] as Map<String, dynamic>? ?? const {};
    return Responder(
      id: json['id'] as String,
      name: json['name'] as String,
      role: (json['role'] as String?) ?? 'responder',
      available: json['available'] as bool? ?? false,
      eventId: (event['id'] as String?) ?? 'unknown',
      eventName: (event['name'] as String?) ?? 'Rehearsal event',
      eventNumber: event['number'] as int?,
      eventStatus: (event['status'] as String?) ?? 'stopped',
    );
  }
}

class Mission {
  const Mission({
    required this.id,
    required this.status,
    required this.zoneName,
    required this.simulated,
    this.arrivalStatus,
    this.workStartedAt,
  });

  final String id;
  final String status;
  final String zoneName;
  final bool simulated;
  final String? arrivalStatus;
  final String? workStartedAt;

  bool get acknowledged => status == 'acknowledged';
  bool get onScene => workStartedAt != null;

  factory Mission.fromJson(Map<String, dynamic> json) {
    final destination =
        json['destination'] as Map<String, dynamic>? ?? const {};
    final verification = json['arrivalVerification'] as Map<String, dynamic>?;
    return Mission(
      id: json['id'] as String,
      status: json['status'] as String,
      zoneName: (destination['name'] as String?) ?? 'Assigned area',
      simulated: destination['simulated'] as bool? ?? false,
      arrivalStatus: verification?['verificationResult'] as String?,
      workStartedAt: json['workStartedAt'] as String?,
    );
  }
}

class MissionMessage {
  const MissionMessage({
    required this.id,
    required this.kind,
    required this.body,
    required this.createdAt,
  });

  final int id;
  final String kind;
  final String body;
  final DateTime? createdAt;

  bool get isInstruction => kind == 'instruction';

  factory MissionMessage.fromJson(Map<String, dynamic> json) => MissionMessage(
    id: json['id'] as int,
    kind: json['kind'] as String,
    body: json['body'] as String,
    createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
  );
}
