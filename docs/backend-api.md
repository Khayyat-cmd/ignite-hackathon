# Backend API

Laravel is the shared source of truth for the Electron admin app, Flutter responder app, and standalone Unity simulation. This document distinguishes routes that exist in the current hackathon build from the authenticated contracts planned for the three-client architecture.

## Current demo API

Base URL: `http://localhost:8000/api/v1/demo`

The current demo creates its local operator and responder identities automatically and does not require a bearer token. Set `AMAN_DEMO_ENABLED=true` before starting a simulation.

| Method and path | Purpose |
| --- | --- |
| `GET /simulations` | List simulation runs. |
| `POST /simulations` | Create a simulation run. |
| `GET /simulations/{run}` | Get the current operator or Unity snapshot. |
| `POST /simulations/{run}/control` | Start, pause, stop, or reset a run. |
| `POST /simulations/{run}/location-retrieval/v0/retrieve` | Exercise the simulated location provider. |
| `POST /incidents/{incident}/recommend` | Calculate the deterministic responder recommendation. |
| `POST /incidents/{incident}/advice` | Retry asynchronous AI advice for an incident with eligible candidates. |
| `POST /incidents/{incident}/approve` | Approve and dispatch an eligible responder selected by the operator. |
| `POST /incidents/{incident}/resolve` | Resolve a verified incident. |
| `GET /responders` | List safe responder identities for demo mobile selection. |
| `GET /missions` | List active missions for a demo responder. |
| `POST /missions/{incident}/acknowledge` | Acknowledge a demo mission. |
| `GET /missions/{incident}/messages` | List mission messages. |
| `POST /missions/{incident}/messages` | Send a mission message or progress event. |

These routes support the current Electron renderer, Flutter prototype, and Unity snapshot integration. They are not the role-scoped production API.

When `OPENAI_API_KEY` is set, a deterministic recommendation queues an incident brief automatically. The response is stored under `incident.decision.advice`; `adviceStatus` is `pending`, `ready`, `failed`, or `disabled`. The model may choose only from `decision.candidates`, and dispatch still requires the `/approve` request with `routeReviewed: true`.

## Planned authenticated contracts

The next API version will expose role-scoped resources rather than client-specific business logic:

- Admin: event overview, incidents, evidence, eligible responder ranking, AI advice, approval, rejection, reassignment, resolution, and audit history.
- Responder: device registration, assigned missions, acknowledgement/decline, progress, messages, and push token management.
- Unity: render-safe snapshots and ordered event replay with read-only credentials.
- Shared realtime: authenticated channels with sequence-based replay after reconnecting.

Concrete endpoint paths and DTOs must be frozen during Phase 1 of `notes.md` before client implementations depend on them.

## Invariants

- Responder eligibility and ranking are deterministic backend decisions.
- AI may explain and select only from eligible candidates; it cannot dispatch or mutate incident state.
- Dispatch requires an explicit, authenticated operator approval.
- Mobile actions are revalidated against current mission state when received.
- Unity receives render-safe fields only and has no mutation endpoints.
- Every accepted state transition is idempotent where clients may retry and is appended to the audit/event journal.
- Invalid, stale, out-of-order, and duplicate-conflicting observations are rejected.
- Nokia and Orange playground data is simulated or mocked. Network congestion is not crowd density.

See [Standalone Unity integration](unity-events.md) and the phased plan in [`notes.md`](../notes.md).
