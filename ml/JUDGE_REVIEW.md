# Strict judge review — AI layer

Status: **implementation-ready; live CAMARA wiring pending teammate integration**.

## Passes

- This is a genuine agent loop: the model chooses among three CAMARA tools and
  receives their results before issuing a structured decision.
- Tool use is operationally meaningful. Reachability gates responder selection,
  location verifies the chosen responder, and critical incidents require network
  congestion evidence.
- The model cannot invent an eligible responder or bypass human approval.
- There is no action/dispatch tool. Provider or model failure fails closed.
- Live and simulated provenance are technically separated and visibly disclosed.
- Secrets and raw telecom identifiers stay behind the Laravel gateway contract.
- A concise tool trace can be shown in the pitch/demo without exposing hidden reasoning.

## Must be demonstrated before submission

- Replace fixture mode with the team's real Nokia Network-as-Code gateway and show
  at least one successful `live_camara` trace.
- Show the agent choosing an alternative after the nearest responder is reported
  unreachable.
- Show operator approval or override after the recommendation; never describe the
  recommendation itself as a dispatch.
- Keep fixture mode available only as a clearly labelled rehearsal fallback.

## Claim boundary

Do not claim autonomous emergency dispatch, real-world safety validation, or live
CAMARA evidence while `evidenceMode` is `simulated_fixture`. The defensible claim is:
"AMAN is a human-in-the-loop incident agent that orchestrates CAMARA network signals
to recommend a reachable, location-verified responder."

