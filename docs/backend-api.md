# Backend API

Base URL: `http://localhost:8000/api/v1`

Send `Authorization: Bearer <token>` and `Accept: application/json`. JSON requests also require `Content-Type: application/json`.

## Main endpoints

| Method and path | Purpose |
| --- | --- |
| `GET /integrations` | Show provider integration status. |
| `GET /zones` | Get monitored zones and their latest state. |
| `GET /responders` | Get responders and availability. |
| `GET /incidents` | Get active and resolved incidents. |
| `POST /zones` | Create a zone with reviewed thresholds. |
| `POST /responders` | Register an authorized responder device. |
| `POST /responders/{id}/refresh` | Refresh responder network information. |
| `POST /incidents/{id}/recommend` | Recommend a suitable responder. |
| `POST /incidents/{id}/approve` | Approve the recommended response. |
| `POST /incidents/{id}/acknowledge` | Record responder acknowledgement. |
| `POST /incidents/{id}/resolve` | Resolve an incident with a note. |
| `GET /events?after=0&limit=100` | Replay ordered events after reconnecting. |
| `POST /demo/zones/{id}/observations` | Submit a simulated crowd reading. |
| `POST /demo/responders/{id}/signals` | Submit simulated responder location/reachability. |

Demo routes require `AMAN_DEMO_ENABLED=true` and are disabled in production.

## Important behavior

- Responder selection considers authorization, role, availability, fresh location and network reachability.
- A recommendation is not dispatched until an operator approves it.
- Live changes are broadcast on the private Reverb channel `operations`.
- The API rejects invalid, stale or duplicate-conflicting readings and rate-limits requests.
- Nokia and Orange playground data is simulated or mocked. Congestion Insights describes network congestion, not crowd density.
- Region Device Count is not connected because no public provider endpoint is currently available.

See [Unity events](unity-events.md) for the shared live-message contract.
