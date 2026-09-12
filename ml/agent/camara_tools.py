"""Read-only CAMARA tools exposed to the AMAN OpenAI agent."""

from __future__ import annotations

import asyncio
import json
from dataclasses import dataclass, field

from agents import RunContextWrapper, function_tool

from camara import CamaraClient, CamaraToolError
from schemas import AdviceRequest, CamaraEvidence, CamaraOperation, ToolTraceEntry


@dataclass
class OrchestrationContext:
    request: AdviceRequest
    client: CamaraClient
    max_tool_calls: int = 12
    trace: list[ToolTraceEntry] = field(default_factory=list)
    cache: dict[tuple[CamaraOperation, str | None], CamaraEvidence] = field(default_factory=dict)

    @property
    def candidate_ids(self) -> set[str]:
        return {candidate.responderId for candidate in self.request.eligibleCandidates}

    async def fetch(
        self,
        operation: CamaraOperation,
        reason: str,
        responder_id: str | None = None,
    ) -> CamaraEvidence:
        reason = " ".join(reason.split())[:300]
        if not reason:
            raise CamaraToolError("a concise operational reason is required")
        if responder_id is not None and responder_id not in self.candidate_ids:
            raise CamaraToolError("responder is outside the eligible candidate allowlist")
        key = (operation, responder_id)
        evidence = self.cache.get(key)
        if evidence is None:
            if len(self.trace) >= self.max_tool_calls:
                raise CamaraToolError("CAMARA tool-call budget exhausted")
            evidence = await asyncio.to_thread(
                self.client.fetch,
                operation,
                self.request,
                responder_id,
            )
            self.cache[key] = evidence
        self.trace.append(_trace_entry(len(self.trace) + 1, operation, reason, evidence))
        return evidence


def _trace_entry(
    sequence: int,
    operation: CamaraOperation,
    reason: str,
    evidence: CamaraEvidence,
) -> ToolTraceEntry:
    result: dict[str, bool | float | str | None] = {}
    for name in (
        "dataReachable",
        "verificationResult",
        "congestionLevel",
        "accuracyMeters",
        "latencyMs",
        "packetLossPct",
        "confidence",
    ):
        value = getattr(evidence, name)
        if value is not None:
            result[name] = value.value if hasattr(value, "value") else value
    return ToolTraceEntry(
        sequence=sequence,
        tool=operation,
        reason=reason,
        responderId=evidence.responderId,
        provider=evidence.provider,
        api=evidence.api,
        source=evidence.source,
        status=evidence.status,
        checkedAt=evidence.checkedAt,
        result=result,
    )


def _tool_json(evidence: CamaraEvidence) -> str:
    return json.dumps(evidence.model_dump(mode="json", exclude_none=True), separators=(",", ":"))


@function_tool(failure_error_function=None, timeout=5, timeout_behavior="raise_exception")
async def check_device_reachability(
    ctx: RunContextWrapper[OrchestrationContext], responder_id: str, reason: str
) -> str:
    """Check whether an eligible responder's device is currently data reachable via CAMARA.

    Args:
        responder_id: An exact responderId from eligibleCandidates.
        reason: Short explanation of why this check is necessary for the decision.
    """
    evidence = await ctx.context.fetch(
        CamaraOperation.DEVICE_REACHABILITY, reason, responder_id
    )
    return _tool_json(evidence)


@function_tool(failure_error_function=None, timeout=5, timeout_behavior="raise_exception")
async def verify_responder_location(
    ctx: RunContextWrapper[OrchestrationContext], responder_id: str, reason: str
) -> str:
    """Verify an eligible responder's expected location/geofence via CAMARA.

    Args:
        responder_id: An exact responderId from eligibleCandidates.
        reason: Short explanation of why location verification affects the recommendation.
    """
    evidence = await ctx.context.fetch(
        CamaraOperation.LOCATION_VERIFICATION, reason, responder_id
    )
    return _tool_json(evidence)


@function_tool(failure_error_function=None, timeout=5, timeout_behavior="raise_exception")
async def inspect_network_congestion(
    ctx: RunContextWrapper[OrchestrationContext], reason: str
) -> str:
    """Inspect current network congestion around the incident zone via CAMARA.

    Args:
        reason: Short explanation of why congestion evidence matters for this incident.
    """
    evidence = await ctx.context.fetch(CamaraOperation.CONGESTION_INSIGHTS, reason)
    return _tool_json(evidence)


CAMARA_TOOLS = [
    check_device_reachability,
    verify_responder_location,
    inspect_network_congestion,
]

