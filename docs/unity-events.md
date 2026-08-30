# Unity integration

The website receives live backend events and forwards them to the Unity WebGL build. Unity never receives provider API keys, backend secrets or authority to change the official incident state.

## Unity bridge

The Unity developer creates an `AmanBridge` GameObject with:

```text
OnAmanEvent(string json)
```

- With an iframe, the website sends the event using `postMessage`; the Unity page validates the website origin and forwards it with `unityInstance.SendMessage`.
- With a React Unity component, the website calls the component library's `sendMessage` function.

Unity must signal when it is ready so the website can deliver any buffered events.

## Events

| Event | Unity action |
| --- | --- |
| `density_updated` | Update count, density and zone colour. |
| `danger_detected` | Highlight a zone requiring attention. |
| `responder_selected` | Show the recommended responder. |
| `response_started` | Start the approved response animation. |
| `response_acknowledged` | Show responder acknowledgement. |
| `incident_resolved` | Close the incident and restore the current zone state. |
| `responder_updated` | Update responder location and reachability. |

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
    "riskLevel": "critical"
  }
}
```

Values may be `null` when no justified estimate is available. Unity should ignore duplicate `eventId` values and unknown events, match objects by UUID, and never present device counts as exact people counts.

The website subscribes to the private Reverb channel `operations`. After reconnecting, it reloads current records and replays missed events from `GET /api/v1/events` before applying buffered live messages.
