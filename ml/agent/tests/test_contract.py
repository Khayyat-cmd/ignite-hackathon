from __future__ import annotations

import json
import os
import sys
import threading
import unittest
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

AGENT_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(AGENT_ROOT))

from camara import BackendCamaraClient, CamaraToolError, FixtureCamaraClient
from camara_tools import OrchestrationContext
from orchestrator import DecisionInvariantError, _model_input, degraded_response, validate_decision
from schemas import (
    AdviceRequest,
    AgentDecision,
    CamaraOperation,
    Confidence,
    ToolTraceEntry,
)
from service import AmanAgentHandler, RequestCache


def load_request() -> AdviceRequest:
    path = AGENT_ROOT / "examples" / "fixture_request.json"
    return AdviceRequest.model_validate_json(path.read_text(encoding="utf-8"))


def trace(
    operation: str,
    responder_id: str | None = None,
    result: dict | None = None,
    source: str = "simulated_fixture",
) -> ToolTraceEntry:
    return ToolTraceEntry.model_validate(
        {
            "sequence": 1,
            "tool": operation,
            "reason": "required for test decision",
            "responderId": responder_id,
            "provider": "Nokia Network as Code",
            "api": f"CAMARA {operation}",
            "source": source,
            "status": "ok",
            "checkedAt": "2026-09-12T12:00:02Z",
            "result": result or {},
        }
    )


def decision(responder_id: str | None = "responder-7", confidence: str = "high") -> AgentDecision:
    return AgentDecision.model_validate(
        {
            "recommendedResponderId": responder_id,
            "confidence": confidence,
            "summary": "Network evidence supports this recommendation.",
            "evidenceSummary": ["Device reachable and location verified."],
            "uncertainty": [],
            "proposedAction": "Operator reviews and approves or overrides.",
            "requiresHumanApproval": True,
        }
    )


def valid_trace() -> list[ToolTraceEntry]:
    return [
        trace("device_reachability", "responder-7", {"dataReachable": True}),
        trace("location_verification", "responder-7", {"verificationResult": "TRUE"}),
        trace("congestion_insights", result={"congestionLevel": "medium"}),
    ]


class SchemaTests(unittest.TestCase):
    def test_example_is_strictly_valid(self) -> None:
        self.assertEqual(load_request().requestId, "demo-incident-42-v1")

    def test_unknown_input_field_is_rejected(self) -> None:
        payload = load_request().model_dump(mode="json")
        payload["dispatchImmediately"] = True
        with self.assertRaises(ValueError):
            AdviceRequest.model_validate(payload)

    def test_duplicate_candidate_is_rejected(self) -> None:
        payload = load_request().model_dump(mode="json")
        payload["eligibleCandidates"].append(payload["eligibleCandidates"][0])
        with self.assertRaises(ValueError):
            AdviceRequest.model_validate(payload)

    def test_fixture_evidence_never_enters_model_prompt(self) -> None:
        prompt = _model_input(load_request())
        self.assertNotIn("fixtureEvidence", prompt)
        self.assertNotIn("dataReachable", prompt)


class FixtureClientTests(unittest.TestCase):
    def test_selects_evidence_for_exact_responder(self) -> None:
        request = load_request()
        evidence = FixtureCamaraClient().fetch(
            CamaraOperation.DEVICE_REACHABILITY, request, "responder-12"
        )
        self.assertFalse(evidence.dataReachable)
        self.assertEqual(evidence.source, "simulated_fixture")

    def test_missing_fixture_fails_closed(self) -> None:
        request = load_request().model_copy(update={"fixtureEvidence": []})
        with self.assertRaises(CamaraToolError):
            FixtureCamaraClient().fetch(
                CamaraOperation.DEVICE_REACHABILITY, request, "responder-7"
            )


class ToolContextTests(unittest.TestCase):
    def test_rejects_noneligible_responder_before_provider_call(self) -> None:
        async def run() -> None:
            context = OrchestrationContext(load_request(), FixtureCamaraClient())
            with self.assertRaises(CamaraToolError):
                await context.fetch(
                    CamaraOperation.DEVICE_REACHABILITY,
                    "compare candidate availability",
                    "invented-responder",
                )

        import asyncio

        asyncio.run(run())

    def test_cache_preserves_trace_without_recalling_provider(self) -> None:
        async def run() -> None:
            client = FixtureCamaraClient()
            context = OrchestrationContext(load_request(), client)
            with patch.object(client, "fetch", wraps=client.fetch) as mocked:
                for _ in range(2):
                    await context.fetch(
                        CamaraOperation.DEVICE_REACHABILITY,
                        "confirm candidate connectivity",
                        "responder-7",
                    )
            self.assertEqual(mocked.call_count, 1)
            self.assertEqual(len(context.trace), 2)

        import asyncio

        asyncio.run(run())


class _FakeResponse:
    def __init__(self, payload: dict) -> None:
        self.payload = json.dumps(payload).encode("utf-8")

    def __enter__(self) -> "_FakeResponse":
        return self

    def __exit__(self, *args: object) -> None:
        return None

    def read(self, limit: int) -> bytes:
        return self.payload


class BackendClientTests(unittest.TestCase):
    def test_rejects_insecure_remote_url(self) -> None:
        with self.assertRaises(ValueError):
            BackendCamaraClient("http://example.com/tool", "secret")

    def test_posts_minimal_authenticated_contract(self) -> None:
        request = load_request()
        response = json.loads(
            (AGENT_ROOT / "examples" / "backend_camara_response.json").read_text(encoding="utf-8")
        )
        with patch("camara.urlopen", return_value=_FakeResponse(response)) as mocked:
            evidence = BackendCamaraClient("https://backend.example/api/internal/agent/camara", "secret").fetch(
                CamaraOperation.DEVICE_REACHABILITY, request, "responder-7"
            )
        sent = mocked.call_args.args[0]
        sent_body = json.loads(sent.data.decode("utf-8"))
        self.assertEqual(sent_body["operation"], "device_reachability")
        self.assertEqual(sent_body["responderId"], "responder-7")
        self.assertEqual(sent.headers["Authorization"], "Bearer secret")
        self.assertTrue(evidence.dataReachable)

    def test_backend_mode_rejects_fixture_provenance(self) -> None:
        response = json.loads(
            (AGENT_ROOT / "examples" / "backend_camara_response.json").read_text(encoding="utf-8")
        )
        response["source"] = "simulated_fixture"
        with patch("camara.urlopen", return_value=_FakeResponse(response)):
            with self.assertRaises(CamaraToolError):
                BackendCamaraClient("https://backend.example/tool", "secret").fetch(
                    CamaraOperation.DEVICE_REACHABILITY, load_request(), "responder-7"
                )


class InvariantTests(unittest.TestCase):
    def test_valid_evidence_passes(self) -> None:
        validate_decision(load_request(), decision(), valid_trace())

    def test_unknown_responder_is_rejected(self) -> None:
        with self.assertRaises(DecisionInvariantError):
            validate_decision(load_request(), decision("invented-responder"), valid_trace())

    def test_unreachable_responder_is_rejected(self) -> None:
        items = valid_trace()
        items[0] = trace("device_reachability", "responder-7", {"dataReachable": False})
        with self.assertRaises(DecisionInvariantError):
            validate_decision(load_request(), decision(), items)

    def test_missing_location_is_rejected(self) -> None:
        items = [valid_trace()[0], valid_trace()[2]]
        with self.assertRaises(DecisionInvariantError):
            validate_decision(load_request(), decision(), items)

    def test_partial_location_cannot_claim_high_confidence(self) -> None:
        items = valid_trace()
        items[1] = trace("location_verification", "responder-7", {"verificationResult": "PARTIAL"})
        with self.assertRaises(DecisionInvariantError):
            validate_decision(load_request(), decision(confidence="high"), items)

    def test_critical_incident_cannot_skip_congestion(self) -> None:
        with self.assertRaises(DecisionInvariantError):
            validate_decision(load_request(), decision(), valid_trace()[:2])

    def test_human_approval_cannot_be_disabled(self) -> None:
        payload = decision().model_dump()
        payload["requiresHumanApproval"] = False
        with self.assertRaises(DecisionInvariantError):
            validate_decision(load_request(), AgentDecision.model_validate(payload), valid_trace())

    def test_degraded_response_never_recommends_or_dispatches(self) -> None:
        result = degraded_response(load_request(), "gpt-5.6-luna")
        self.assertEqual(result.agentStatus, "degraded")
        self.assertIsNone(result.recommendedResponderId)
        self.assertTrue(result.requiresHumanApproval)
        self.assertNotIn("dispatch immediately", result.proposedAction.lower())


class IdempotencyTests(unittest.TestCase):
    def test_same_request_replays_and_mismatch_conflicts(self) -> None:
        cache = RequestCache(limit=2)
        cache.put("req-1", "hash-a", b"result")
        self.assertEqual(cache.get("req-1", "hash-a"), b"result")
        with self.assertRaises(ValueError):
            cache.get("req-1", "hash-b")

    def test_cache_is_bounded(self) -> None:
        cache = RequestCache(limit=2)
        cache.put("one", "1", b"1")
        cache.put("two", "2", b"2")
        cache.put("three", "3", b"3")
        self.assertIsNone(cache.get("one", "1"))


class ServiceBoundaryTests(unittest.TestCase):
    def test_authenticated_http_boundary_returns_safe_response(self) -> None:
        request_model = load_request()
        expected = degraded_response(request_model, "gpt-5.6-luna")
        server = ThreadingHTTPServer(("127.0.0.1", 0), AmanAgentHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        url = f"http://127.0.0.1:{server.server_port}/v1/incidents/advise"
        raw = request_model.model_dump_json().encode("utf-8")
        try:
            with patch.dict(os.environ, {"AMAN_AGENT_SERVICE_TOKEN": "test-token"}), patch(
                "service.orchestrate", return_value=expected
            ):
                unauthorized = Request(
                    url,
                    data=raw,
                    method="POST",
                    headers={"Content-Type": "application/json"},
                )
                with self.assertRaises(HTTPError) as rejected:
                    urlopen(unauthorized, timeout=2)
                self.assertEqual(rejected.exception.code, 401)

                authorized = Request(
                    url,
                    data=raw,
                    method="POST",
                    headers={
                        "Authorization": "Bearer test-token",
                        "Content-Type": "application/json",
                    },
                )
                with urlopen(authorized, timeout=2) as response:
                    payload = json.loads(response.read().decode("utf-8"))
                self.assertEqual(payload["agentStatus"], "degraded")
                self.assertIsNone(payload["recommendedResponderId"])
                self.assertTrue(payload["requiresHumanApproval"])
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)


if __name__ == "__main__":
    unittest.main()
