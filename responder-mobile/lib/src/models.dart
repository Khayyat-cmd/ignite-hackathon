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

  factory Responder.fromJson(Map<String, dynamic> json) {
    final event = json['event'] as Map<String, dynamic>? ?? const {};
    return Responder(
      id: json['id'] as String,
      name: json['name'] as String,
      role: (json['role'] as String?) ?? 'responder',
      available: json['available'] as bool? ?? false,
      eventId: (event['id'] as String?) ?? 'unknown',
      eventName: (event['name'] as String?) ?? '',
      eventNumber: event['number'] as int?,
      eventStatus: (event['status'] as String?) ?? 'stopped',
    );
  }
}

/// The field-facing part of the operator's approved brief. The control room
/// sees the whole agent output; a responder gets how urgent the zone is and the
/// one network fact that changes how they should try to reach the control room.
/// The agent's prose stays in the control room — it is written for the operator
/// deciding the dispatch, not for the responder already on the way.
class MissionBrief {
  const MissionBrief({required this.urgency, required this.networkCongested});

  final String? urgency;
  final bool networkCongested;

  bool get isCritical => urgency == 'critical';

  /// Nothing to show is a real answer: a normal zone with a healthy network
  /// earns no block rather than a reassuring one the responder has to read.
  bool get hasSomethingToSay => isCritical || networkCongested;

  factory MissionBrief.fromJson(Map<String, dynamic> json) => MissionBrief(
    urgency: json['urgency'] as String?,
    networkCongested: json['networkCongested'] as bool? ?? false,
  );
}

class Mission {
  const Mission({
    required this.id,
    required this.status,
    required this.zoneName,
    required this.simulated,
    this.arrivalStatus,
    this.workStartedAt,
    this.brief,
  });

  final String id;
  final String status;
  final String zoneName;
  final bool simulated;
  final String? arrivalStatus;
  final String? workStartedAt;
  final MissionBrief? brief;

  bool get acknowledged => status == 'acknowledged';
  bool get onScene => workStartedAt != null;

  factory Mission.fromJson(Map<String, dynamic> json) {
    final destination =
        json['destination'] as Map<String, dynamic>? ?? const {};
    final verification = json['arrivalVerification'] as Map<String, dynamic>?;
    final brief = json['brief'] as Map<String, dynamic>?;
    return Mission(
      id: json['id'] as String,
      status: json['status'] as String,
      zoneName: (destination['name'] as String?) ?? '',
      simulated: destination['simulated'] as bool? ?? false,
      arrivalStatus: verification?['verificationResult'] as String?,
      workStartedAt: json['workStartedAt'] as String?,
      brief: brief == null ? null : MissionBrief.fromJson(brief),
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
