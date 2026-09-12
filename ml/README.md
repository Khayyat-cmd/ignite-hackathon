# AMAN CAMARA AI agent

This folder is AMAN's complete AI contribution. AMAN uses one focused, read-only agent that
chooses and calls CAMARA network tools, explains the evidence, and recommends an
eligible responder for an operator to approve or override.

## Decision flow

```text
Incident + eligible responders
            |
            v
OpenAI Agents SDK / gpt-5.6-luna
    |              |                 |
    v              v                 v
Device          Location          Congestion
Reachability    Verification      Insights
    \______________|_________________/
                   v
       validated recommendation
                   v
        mandatory human approval
```

The agent cannot dispatch, message, mutate incidents, or call arbitrary URLs.
Every recommendation is checked in code against the eligible-responder allowlist
and the captured CAMARA tool trace. A model or provider failure returns a safe
`degraded` result with no recommendation; the existing deterministic backend
ranking remains the operational fallback.

## Run now with demo fixtures

Fixture mode exercises the real agent/tool loop while the backend team finishes
the live CAMARA gateway. Fixture evidence is clearly labelled simulated and is
never inserted directly into the model prompt.

```powershell
cd ml\agent
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
$env:OPENAI_API_KEY = "your OpenAI API key"
$env:AMAN_CAMARA_MODE = "fixture"
python service.py
```

Do not copy or commit `backend/.env`. The deployment process should inject the
same server-side `OPENAI_API_KEY` into this service's process environment.

The service listens on `127.0.0.1:8091` by default:

- `GET /health`
- `POST /v1/incidents/advise`

Use `agent/examples/fixture_request.json` as the POST body. If
`AMAN_AGENT_SERVICE_TOKEN` is set, include
`Authorization: Bearer <token>`. A non-loopback bind refuses to start without
that token.

## Switch to live CAMARA evidence later

No AI code needs to change. Set:

```text
AMAN_CAMARA_MODE=backend
AMAN_CAMARA_TOOL_URL=http://127.0.0.1:8000/api/internal/agent/camara
AMAN_CAMARA_TOOL_TOKEN=<private service token>
```

This is what the deployed demo runs. `deploy/release.sh` writes these into
`/var/www/aman/agent/agent.env` and points the tool URL at the loopback-only
nginx listener on `127.0.0.1:8127`, because production Laravel is served by
PHP-FPM and has no `:8000` of its own.

The backend gateway receives one server-to-server POST per tool call:

```json
{
  "requestId": "demo-incident-42-v1",
  "incidentId": "incident-42",
  "zoneId": "zone-a",
  "operation": "device_reachability",
  "responderId": "responder-7"
}
```

Supported `operation` values are:

- `device_reachability` — CAMARA Device Reachability Status for a candidate.
- `location_verification` — CAMARA Location Verification for a candidate.
- `congestion_insights` — CAMARA Congestion Insights for the incident zone.

The gateway must normalize Nokia Network-as-Code responses to the strict shape in
`agent/examples/backend_camara_response.json`. It must return
`source: "live_camara"`; fixture provenance is rejected in backend mode. CAMARA
credentials, phone identifiers, and raw coordinates remain in Laravel and are
never exposed to the model, clients, logs, or tool trace.

## Stable backend integration contract

Laravel should POST an incident snapshot and the already-authorized candidate
allowlist to `/v1/incidents/advise`. The exact input schema is in
`agent/schemas.py`. Important output fields are:

- `agentStatus`: `completed` or safe `degraded`.
- `recommendedResponderId`: an allowlisted ID or `null`.
- `evidenceMode`: `live_camara`, `simulated_fixture`, or `none`.
- `toolTrace`: concise API calls, reasons, provenance, timestamps, and sanitized results.
- `requiresHumanApproval`: always `true`.

Use `requestId` as an idempotency key. Repeating the identical request replays the
cached result; reusing the ID with a different body returns HTTP 409.

All three integration steps are done and verified end to end (2026-09-12):

1. Laravel serves the normalized gateway at `POST /api/internal/agent/camara`
   (`app/Services/Ai/CamaraEvidenceGateway.php`), bearer-token authenticated and
   loopback-only in production.
2. Laravel routes advice through this service via
   `app/Services/Ai/AgentIncidentAdvisor.php`, bound whenever `AMAN_AGENT_URL` is
   set. The old single-call advisor remains the fallback for a machine that is
   not running this service.
3. The operator console renders `toolTrace`, `evidenceMode`, uncertainty, and
   the approval control, in English and Arabic.

The gateway declares provenance per operation rather than `live_camara` for
everything, because AMAN's venue genuinely runs mixed: Device Reachability is a
live Nokia Network-as-Code call, while location verification and congestion come
from the venue simulation. `BackendCamaraClient` keeps whatever the gateway
declares and never upgrades it, and `_evidence_mode` still reports the whole run
as `simulated_fixture` when any source is simulated.

Unity and the responder app do not call this service or CAMARA directly. The
responder app receives only `{urgency, networkCongested}` from the approved
advice, so the agent's congestion finding changes how a responder in the field
tries to reach the control room.

## Validation

All checks are offline and never consume OpenAI or CAMARA quota:

```powershell
cd ml\agent
python -m unittest discover -s tests -v
python evaluate_contract.py
python -m compileall -q .
```

The tests cover strict schemas, candidate allowlisting, required evidence,
unreachable responders, partial location confidence, critical-incident congestion,
provenance enforcement, safe degradation, authenticated gateway calls, and bounded
idempotency caching.

## Hackathon compliance

| Requirement | Evidence in this folder |
| --- | --- |
| AI agent layer | OpenAI Agents SDK `Agent` + `Runner`, not a one-shot model call |
| Intelligent CAMARA orchestration | Three model-selected CAMARA function tools with operational reasons |
| Trusted real-time sources | Backend mode accepts only normalized `live_camara` provenance |
| Approved AI tooling | OpenAI Agents SDK with `gpt-5.6-luna` |
| Safe, scalable design | Server-side secrets, timeouts, call budget, strict contracts, idempotency, no CORS |
| Human control | Read-only recommendations; no dispatch tool; approval invariant enforced in code |
| Honest demo | Fixture mode is visibly disclosed and cannot masquerade as live evidence |

OpenAI API access is billed separately from a ChatGPT or Codex subscription.
