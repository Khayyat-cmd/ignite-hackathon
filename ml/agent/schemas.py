"""Strict public contracts for the AMAN CAMARA orchestration agent."""

from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class Severity(str, Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


class Confidence(str, Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"


class VerificationResult(str, Enum):
    TRUE = "TRUE"
    FALSE = "FALSE"
    PARTIAL = "PARTIAL"
    UNKNOWN = "UNKNOWN"


class CamaraOperation(str, Enum):
    DEVICE_REACHABILITY = "device_reachability"
    LOCATION_VERIFICATION = "location_verification"
    CONGESTION_INSIGHTS = "congestion_insights"


class Incident(StrictModel):
    id: str = Field(min_length=1, max_length=100)
    status: str = Field(min_length=1, max_length=50)
    severity: Severity
    source: str = Field(min_length=1, max_length=80)


class Zone(StrictModel):
    id: str = Field(min_length=1, max_length=100)
    name: str = Field(min_length=1, max_length=120)
    riskLevel: Severity
    density: float = Field(ge=0, le=50)
    warningThreshold: float = Field(gt=0, le=50)
    criticalThreshold: float = Field(gt=0, le=50)
    observedAt: datetime

    @model_validator(mode="after")
    def thresholds_are_ordered(self) -> "Zone":
        if self.warningThreshold >= self.criticalThreshold:
            raise ValueError("warningThreshold must be below criticalThreshold")
        return self


class EligibleCandidate(StrictModel):
    responderId: str = Field(min_length=1, max_length=100)
    name: str = Field(min_length=1, max_length=120)
    role: str = Field(min_length=1, max_length=80)
    distanceMeters: float = Field(ge=0, le=100_000)
    accuracyMeters: float | None = Field(default=None, ge=0, le=10_000)


class DeterministicRecommendation(StrictModel):
    responderId: str = Field(min_length=1, max_length=100)
    reason: str = Field(min_length=1, max_length=500)


class CamaraEvidence(StrictModel):
    operation: CamaraOperation
    responderId: str | None = Field(default=None, max_length=100)
    provider: str = Field(min_length=1, max_length=80)
    api: str = Field(min_length=1, max_length=120)
    source: Literal["live_camara", "simulated_fixture"]
    status: Literal["ok", "unavailable"]
    observedAt: datetime | None = None
    checkedAt: datetime = Field(default_factory=utc_now)
    dataReachable: bool | None = None
    verificationResult: VerificationResult | None = None
    congestionLevel: Literal["low", "medium", "high", "unknown"] | None = None
    accuracyMeters: float | None = Field(default=None, ge=0, le=10_000)
    latencyMs: float | None = Field(default=None, ge=0, le=120_000)
    packetLossPct: float | None = Field(default=None, ge=0, le=100)
    confidence: float | None = Field(default=None, ge=0, le=1)

    @model_validator(mode="after")
    def operation_has_expected_payload(self) -> "CamaraEvidence":
        if self.status == "unavailable":
            return self
        if self.operation is CamaraOperation.DEVICE_REACHABILITY:
            if self.responderId is None or self.dataReachable is None:
                raise ValueError("reachability evidence needs responderId and dataReachable")
        elif self.operation is CamaraOperation.LOCATION_VERIFICATION:
            if self.responderId is None or self.verificationResult is None:
                raise ValueError("location evidence needs responderId and verificationResult")
        elif self.operation is CamaraOperation.CONGESTION_INSIGHTS:
            if self.congestionLevel is None:
                raise ValueError("congestion evidence needs congestionLevel")
        return self


class AdviceRequest(StrictModel):
    requestId: str = Field(min_length=1, max_length=100, pattern=r"^[A-Za-z0-9._:-]+$")
    incident: Incident
    zone: Zone
    eligibleCandidates: list[EligibleCandidate] = Field(min_length=1, max_length=20)
    deterministicRecommendation: DeterministicRecommendation | None = None
    fixtureEvidence: list[CamaraEvidence] | None = Field(default=None, max_length=100)

    @field_validator("eligibleCandidates")
    @classmethod
    def candidate_ids_are_unique(cls, value: list[EligibleCandidate]) -> list[EligibleCandidate]:
        ids = [candidate.responderId for candidate in value]
        if len(ids) != len(set(ids)):
            raise ValueError("eligibleCandidates contains duplicate responderId values")
        return value

    @model_validator(mode="after")
    def deterministic_candidate_is_eligible(self) -> "AdviceRequest":
        if self.deterministicRecommendation is not None:
            candidate_ids = {item.responderId for item in self.eligibleCandidates}
            if self.deterministicRecommendation.responderId not in candidate_ids:
                raise ValueError("deterministicRecommendation must reference an eligible candidate")
        return self


class AgentDecision(StrictModel):
    recommendedResponderId: str | None
    confidence: Confidence
    summary: str = Field(min_length=1, max_length=600)
    evidenceSummary: list[str] = Field(min_length=1, max_length=8)
    uncertainty: list[str] = Field(max_length=8)
    proposedAction: str = Field(min_length=1, max_length=500)
    requiresHumanApproval: bool


class ToolTraceEntry(StrictModel):
    sequence: int = Field(ge=1)
    tool: CamaraOperation
    reason: str = Field(min_length=1, max_length=300)
    responderId: str | None = None
    provider: str
    api: str
    source: Literal["live_camara", "simulated_fixture"]
    status: Literal["ok", "unavailable"]
    checkedAt: datetime
    result: dict[str, bool | float | str | None]


class AdviceResponse(StrictModel):
    requestId: str
    incidentId: str
    agentStatus: Literal["completed", "degraded"]
    provider: Literal["openai"]
    model: str
    agentRuntime: Literal["openai-agents-python"]
    agentVersion: str
    evidenceMode: Literal["live_camara", "simulated_fixture", "none"]
    recommendedResponderId: str | None
    confidence: Confidence
    summary: str
    evidenceSummary: list[str]
    uncertainty: list[str]
    proposedAction: str
    requiresHumanApproval: bool
    toolTrace: list[ToolTraceEntry]
    generatedAt: datetime = Field(default_factory=utc_now)

