# Standalone Unity integration

Unity runs as an independent application on the control room's second screen. It does not embed in Electron and Electron does not forward events to it. Both applications obtain state from Laravel, which keeps them synchronized and allows either client to reconnect independently.

The Unity work is owned by the Unity developer. This repository owns the backend contract and representative payload fixtures.

## Connection flow

1. Authenticate with a read-only, event-scoped client credential supplied at deployment time.
2. Load the current simulation or event snapshot from the versioned backend API.
3. Subscribe to the backend operations event stream.
4. Record the last applied `sequence` and ignore duplicate `eventId` values.
5. After reconnecting, fetch events after that sequence, apply them in order, then resume the live stream.

Unity must not receive provider credentials, LLM credentials, responder phone numbers, private operator notes, or authority to change incident state. If the live connection drops, it should show a visible stale indicator and keep the last valid scene until a snapshot refresh succeeds.

## Events

| Event | Unity action |
| --- | --- |
| `density_updated` | Update estimated count, density, quality, and zone colour. |
| `danger_detected` | Highlight a zone requiring attention. |
| `responder_selected` | Show the operator-approved responder assignment state. |
| `response_started` | Start the approved response animation. |
| `response_acknowledged` | Show responder acknowledgement. |
| `incident_resolved` | Close the incident and restore the current zone state. |
| `responder_updated` | Update the responder's render-safe position and availability. |

AI advice is intentionally absent from the Unity contract. It is an operator decision aid in Electron, not simulation truth.

## Message shape

```json
{
  "schemaVersion": 1,
  "sequence": "42",
  "eventId": "c9da97f3-e888-4a26-8a67-f1fc6138dd84",
  "event": "density_updated",
  "occurredAt": "2026-08-30T17:00:00Z",
  "zoneId": "929873b7-5341-42c4-9183-fd4b2bb1700e",
  "incidentId": null,
  "data": {
    "deviceCount": 250,
    "estimatedPeople": 250,
    "densityPerSquareMeter": 2.5,
    "riskLevel": "critical",
    "quality": "simulated"
  }
}
```

Values may be `null` when no justified estimate is available. Unity should ignore unknown event names, match entities by stable IDs, and never present device counts as exact people counts.

The exact authenticated snapshot, replay, and broadcast endpoints are part of the planned production API. The current hackathon backend exposes the demo snapshot routes listed in `backend-api.md`.
