"""CAMARA evidence adapters. Credentials and provider calls remain server-side."""

from __future__ import annotations

import json
from abc import ABC, abstractmethod
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

from schemas import AdviceRequest, CamaraEvidence, CamaraOperation


class CamaraToolError(RuntimeError):
    """A sanitized provider/tool failure safe to handle without exposing secrets."""


class CamaraClient(ABC):
    @abstractmethod
    def fetch(
        self,
        operation: CamaraOperation,
        request: AdviceRequest,
        responder_id: str | None = None,
    ) -> CamaraEvidence:
        raise NotImplementedError


class FixtureCamaraClient(CamaraClient):
    """Deterministic demo adapter. Fixture data never enters the model prompt directly."""

    def fetch(
        self,
        operation: CamaraOperation,
        request: AdviceRequest,
        responder_id: str | None = None,
    ) -> CamaraEvidence:
        for evidence in request.fixtureEvidence or []:
            if evidence.operation is not operation:
                continue
            if operation is CamaraOperation.CONGESTION_INSIGHTS or evidence.responderId == responder_id:
                return evidence.model_copy(update={"source": "simulated_fixture"})
        subject = f" for responder {responder_id}" if responder_id else ""
        raise CamaraToolError(f"fixture evidence unavailable for {operation.value}{subject}")


class BackendCamaraClient(CamaraClient):
    """Calls the backend's authenticated, normalized CAMARA tool gateway."""

    def __init__(self, endpoint: str, token: str, timeout_seconds: float = 4.0) -> None:
        parsed = urlparse(endpoint)
        if parsed.scheme not in {"http", "https"} or not parsed.hostname:
            raise ValueError("AMAN_CAMARA_TOOL_URL must be an absolute HTTP(S) URL")
        if parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError("CAMARA tool URL cannot contain credentials, query, or fragment")
        if parsed.scheme == "http" and parsed.hostname not in {"127.0.0.1", "localhost", "::1"}:
            raise ValueError("non-local CAMARA tool URL must use HTTPS")
        if not token:
            raise ValueError("AMAN_CAMARA_TOOL_TOKEN is required in backend mode")
        self.endpoint = endpoint.rstrip("/")
        self.token = token
        self.timeout_seconds = timeout_seconds

    def fetch(
        self,
        operation: CamaraOperation,
        request: AdviceRequest,
        responder_id: str | None = None,
    ) -> CamaraEvidence:
        payload: dict[str, str] = {
            "requestId": request.requestId,
            "incidentId": request.incident.id,
            "zoneId": request.zone.id,
            "operation": operation.value,
        }
        if responder_id is not None:
            payload["responderId"] = responder_id
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        http_request = Request(
            self.endpoint,
            data=body,
            method="POST",
            headers={
                "Accept": "application/json",
                "Authorization": f"Bearer {self.token}",
                "Content-Type": "application/json",
                "User-Agent": "AMAN-CAMARA-Agent/2.0",
            },
        )
        try:
            with urlopen(http_request, timeout=self.timeout_seconds) as response:
                raw = response.read(131_073)
                if len(raw) > 131_072:
                    raise CamaraToolError("CAMARA gateway response exceeded 128 KiB")
                decoded = json.loads(raw.decode("utf-8"))
        except HTTPError as error:
            raise CamaraToolError(f"CAMARA gateway returned HTTP {error.code}") from error
        except (URLError, TimeoutError, OSError) as error:
            raise CamaraToolError("CAMARA gateway was unreachable") from error
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise CamaraToolError("CAMARA gateway returned invalid JSON") from error

        if isinstance(decoded, dict) and "evidence" in decoded:
            decoded = decoded["evidence"]
        try:
            evidence = CamaraEvidence.model_validate(decoded)
        except (TypeError, ValueError) as error:
            raise CamaraToolError("CAMARA gateway returned evidence outside the contract") from error
        if evidence.operation is not operation or evidence.responderId != responder_id:
            if operation is CamaraOperation.CONGESTION_INSIGHTS and evidence.responderId is None:
                pass
            else:
                raise CamaraToolError("CAMARA gateway returned evidence for the wrong subject")
        # Provenance is whatever the gateway declares per operation, and it is
        # never upgraded here. AMAN's venue runs mixed: Device Reachability is a
        # live Nokia Network-as-Code call, while location verification and
        # congestion come from the venue simulation. A run that touches any
        # simulated source is reported as simulated_fixture overall.
        return evidence

