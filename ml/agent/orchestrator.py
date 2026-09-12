"""AMAN's read-only, network-aware incident orchestration agent."""

from __future__ import annotations

import asyncio
import json
import os
from dataclasses import dataclass

from agents import Agent, ModelSettings, RunConfig, Runner
from openai.types.shared.reasoning import Reasoning

from camara import BackendCamaraClient, CamaraClient, FixtureCamaraClient
from camara_tools import CAMARA_TOOLS, OrchestrationContext
from schemas import (
    AdviceRequest,
    AdviceResponse,
    AgentDecision,
    CamaraOperation,
    Confidence,
    Severity,
    ToolTraceEntry,
    VerificationResult,
    utc_now,
)


AGENT_VERSION = "2.0.0"
DEFAULT_MODEL = "gpt-5.6-luna"

AGENT_INSTRUCTIONS = """
You are AMAN's network-aware incident decision-support agent for a venue control room.

Your job is to recommend at most one responder from eligibleCandidates. The operator is
the only authority who may approve, override, or dispatch. You have no dispatch tool and
must never claim that an action was executed.

The incident and candidate list are context, not telecom evidence. Use the supplied CAMARA
tools as trusted current evidence and intelligently choose the checks needed:
1. Before recommending anyone, call check_device_reachability for plausible candidates.
   Never recommend a responder whose device is unreachable or whose check failed.
2. Before finalizing a responder, call verify_responder_location for that exact responder.
   FALSE or UNKNOWN means do not recommend them. PARTIAL is usable only with LOW or MEDIUM
   confidence and explicit uncertainty.
3. For a CRITICAL incident, call inspect_network_congestion. For other incidents, call it
   when communications quality can change the safest recommendation.
4. Compare operational distance/role with the fresh CAMARA evidence; do not simply echo the
   deterministicRecommendation. Never invent API results or responder IDs.

Return the required structured decision. requiresHumanApproval must be true. If no candidate
is safely supportable, set recommendedResponderId to null and confidence to low. Describe
uncertainty honestly. Keep evidence summaries concise and identify simulated_fixture evidence
as demo/simulated rather than live. Do not reveal private chain-of-thought; expose only brief
decision evidence and the operational action proposed for human review.
""".strip()


class DecisionInvariantError(ValueError):
    pass


@dataclass(frozen=True)
class AgentConfiguration:
    model: str
    mode: str
    timeout_seconds: float


def configuration_from_environment() -> AgentConfiguration:
    mode = os.getenv("AMAN_CAMARA_MODE", "fixture").strip().lower()
    if mode not in {"fixture", "backend"}:
        raise ValueError("AMAN_CAMARA_MODE must be fixture or backend")
    model = os.getenv("OPENAI_MODEL", DEFAULT_MODEL).strip()
    if not model:
        raise ValueError("OPENAI_MODEL cannot be empty")
    timeout_seconds = float(os.getenv("AMAN_AGENT_TIMEOUT_SECONDS", "35"))
    if not 5 <= timeout_seconds <= 120:
        raise ValueError("AMAN_AGENT_TIMEOUT_SECONDS must be between 5 and 120")
    return AgentConfiguration(model=model, mode=mode, timeout_seconds=timeout_seconds)


def client_from_environment(configuration: AgentConfiguration) -> CamaraClient:
    if configuration.mode == "fixture":
        return FixtureCamaraClient()
    return BackendCamaraClient(
        endpoint=os.getenv(
            "AMAN_CAMARA_TOOL_URL",
            "http://127.0.0.1:8000/api/internal/agent/camara",
        ),
        token=os.getenv("AMAN_CAMARA_TOOL_TOKEN", ""),
        timeout_seconds=float(os.getenv("AMAN_CAMARA_TIMEOUT_SECONDS", "4")),
    )


def _build_agent(model: str) -> Agent[OrchestrationContext]:
    return Agent(
        name="AMAN Network-Aware Incident Orchestrator",
        instructions=AGENT_INSTRUCTIONS,
        model=model,
        tools=CAMARA_TOOLS,
        output_type=AgentDecision,
        model_settings=ModelSettings(
            tool_choice="required",
            parallel_tool_calls=False,
            max_tokens=900,
            reasoning=Reasoning(effort="low"),
            verbosity="low",
            store=False,
        ),
        reset_tool_choice=True,
    )


def _model_input(request: AdviceRequest) -> str:
    safe_context = request.model_dump(
        mode="json",
        exclude={"fixtureEvidence"},
        exclude_none=True,
    )
    return (
        "Assess this incident. Use CAMARA tools for current telecom evidence; "
        "the context below alone is insufficient for a recommendation.\n"
        + json.dumps(safe_context, separators=(",", ":"))
    )


def validate_decision(
    request: AdviceRequest,
    decision: AgentDecision,
    trace: list[ToolTraceEntry],
) -> None:
    """Fail closed when the model's recommendation is not supported by tool evidence."""
    if decision.requiresHumanApproval is not True:
        raise DecisionInvariantError("human approval must remain mandatory")

    candidate_ids = {candidate.responderId for candidate in request.eligibleCandidates}
    if decision.recommendedResponderId is not None and decision.recommendedResponderId not in candidate_ids:
        raise DecisionInvariantError("recommended responder is outside the eligible allowlist")

    successful_reachability = [
        item
        for item in trace
        if item.tool is CamaraOperation.DEVICE_REACHABILITY
        and item.status == "ok"
        and item.result.get("dataReachable") is True
    ]
    if not any(item.tool is CamaraOperation.DEVICE_REACHABILITY for item in trace):
        raise DecisionInvariantError("agent did not consult Device Reachability")

    if request.incident.severity is Severity.CRITICAL and not any(
        item.tool is CamaraOperation.CONGESTION_INSIGHTS for item in trace
    ):
        raise DecisionInvariantError("critical incidents require Congestion Insights")

    recommended = decision.recommendedResponderId
    if recommended is None:
        if decision.confidence is not Confidence.LOW:
            raise DecisionInvariantError("no-recommendation decisions must use low confidence")
        return

    if not any(item.responderId == recommended for item in successful_reachability):
        raise DecisionInvariantError("recommended responder lacks a successful reachable result")

    location_checks = [
        item
        for item in trace
        if item.tool is CamaraOperation.LOCATION_VERIFICATION
        and item.responderId == recommended
        and item.status == "ok"
    ]
    if not location_checks:
        raise DecisionInvariantError("recommended responder lacks Location Verification")
    latest_location = location_checks[-1].result.get("verificationResult")
    if latest_location not in {VerificationResult.TRUE.value, VerificationResult.PARTIAL.value}:
        raise DecisionInvariantError("recommended responder failed Location Verification")
    if decision.confidence is Confidence.HIGH and latest_location != VerificationResult.TRUE.value:
        raise DecisionInvariantError("high confidence requires a TRUE location verification")


def _evidence_mode(trace: list[ToolTraceEntry]) -> str:
    sources = {item.source for item in trace}
    if not sources:
        return "none"
    if sources == {"live_camara"}:
        return "live_camara"
    return "simulated_fixture"


def _completed_response(
    request: AdviceRequest,
    decision: AgentDecision,
    trace: list[ToolTraceEntry],
    model: str,
) -> AdviceResponse:
    evidence_summary = list(decision.evidenceSummary)
    mode = _evidence_mode(trace)
    if mode == "simulated_fixture" and not any(
        "simulat" in item.lower() or "demo" in item.lower() for item in evidence_summary
    ):
        evidence_summary.insert(0, "DEMO: telecom evidence came from simulated CAMARA fixtures.")
    return AdviceResponse(
        requestId=request.requestId,
        incidentId=request.incident.id,
        agentStatus="completed",
        provider="openai",
        model=model,
        agentRuntime="openai-agents-python",
        agentVersion=AGENT_VERSION,
        evidenceMode=mode,
        recommendedResponderId=decision.recommendedResponderId,
        confidence=decision.confidence,
        summary=decision.summary,
        evidenceSummary=evidence_summary[:8],
        uncertainty=decision.uncertainty,
        proposedAction=decision.proposedAction,
        requiresHumanApproval=True,
        toolTrace=trace,
        generatedAt=utc_now(),
    )


def degraded_response(
    request: AdviceRequest,
    model: str,
    trace: list[ToolTraceEntry] | None = None,
    reason: str = "AI or CAMARA evidence was unavailable.",
) -> AdviceResponse:
    safe_reason = " ".join(reason.split())[:300]
    return AdviceResponse(
        requestId=request.requestId,
        incidentId=request.incident.id,
        agentStatus="degraded",
        provider="openai",
        model=model,
        agentRuntime="openai-agents-python",
        agentVersion=AGENT_VERSION,
        evidenceMode=_evidence_mode(trace or []),
        recommendedResponderId=None,
        confidence=Confidence.LOW,
        summary="No AI recommendation was issued because trusted evidence was incomplete.",
        evidenceSummary=[safe_reason],
        uncertainty=["The operator must rely on the backend's deterministic ranking and live controls."],
        proposedAction="Review current incident data and choose or override a responder manually.",
        requiresHumanApproval=True,
        toolTrace=trace or [],
        generatedAt=utc_now(),
    )


async def orchestrate_async(
    request: AdviceRequest,
    configuration: AgentConfiguration | None = None,
    client: CamaraClient | None = None,
) -> AdviceResponse:
    configuration = configuration or configuration_from_environment()
    if not os.getenv("OPENAI_API_KEY"):
        return degraded_response(request, configuration.model, reason="OPENAI_API_KEY is not configured.")

    context = OrchestrationContext(
        request=request,
        client=client or client_from_environment(configuration),
    )
    try:
        result = await asyncio.wait_for(
            Runner.run(
                _build_agent(configuration.model),
                input=_model_input(request),
                context=context,
                max_turns=10,
                run_config=RunConfig(
                    workflow_name="AMAN CAMARA incident orchestration",
                    trace_include_sensitive_data=False,
                ),
            ),
            timeout=configuration.timeout_seconds,
        )
        decision = result.final_output
        if not isinstance(decision, AgentDecision):
            decision = AgentDecision.model_validate(decision)
        validate_decision(request, decision, context.trace)
        return _completed_response(request, decision, context.trace, configuration.model)
    except Exception as error:
        # The client receives a safe operational state, never secrets or a stack trace.
        return degraded_response(
            request,
            configuration.model,
            trace=context.trace,
            reason=f"Agent run failed closed ({type(error).__name__}).",
        )


def orchestrate(request: AdviceRequest) -> AdviceResponse:
    return asyncio.run(orchestrate_async(request))
