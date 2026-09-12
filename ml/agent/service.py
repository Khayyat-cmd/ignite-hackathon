"""Minimal HTTP boundary for the AMAN agent. No framework or browser exposure required."""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import threading
from collections import OrderedDict
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
from urllib.parse import urlparse

from pydantic import ValidationError

from orchestrator import AGENT_VERSION, DEFAULT_MODEL, orchestrate
from schemas import AdviceRequest


MAX_BODY_BYTES = 1_048_576
CACHE_LIMIT = 256


class RequestCache:
    def __init__(self, limit: int = CACHE_LIMIT) -> None:
        self.limit = limit
        self._items: OrderedDict[str, tuple[str, bytes]] = OrderedDict()
        self._lock = threading.Lock()

    def get(self, request_id: str, fingerprint: str) -> bytes | None:
        with self._lock:
            item = self._items.get(request_id)
            if item is None:
                return None
            cached_fingerprint, payload = item
            if cached_fingerprint != fingerprint:
                raise ValueError("requestId was already used with a different payload")
            self._items.move_to_end(request_id)
            return payload

    def put(self, request_id: str, fingerprint: str, payload: bytes) -> None:
        with self._lock:
            self._items[request_id] = (fingerprint, payload)
            self._items.move_to_end(request_id)
            while len(self._items) > self.limit:
                self._items.popitem(last=False)


REQUEST_CACHE = RequestCache()


class AmanAgentHandler(BaseHTTPRequestHandler):
    server_version = "AMANAgent/2.0"
    sys_version = ""

    def do_GET(self) -> None:
        if urlparse(self.path).path != "/health":
            self._json(HTTPStatus.NOT_FOUND, {"error": "not_found"})
            return
        mode = os.getenv("AMAN_CAMARA_MODE", "fixture").strip().lower()
        self._json(
            HTTPStatus.OK,
            {
                "status": "ok",
                "service": "aman-camara-agent",
                "agentVersion": AGENT_VERSION,
                "model": os.getenv("OPENAI_MODEL", DEFAULT_MODEL),
                "camaraMode": mode,
                "openAiConfigured": bool(os.getenv("OPENAI_API_KEY")),
                "camaraGatewayConfigured": mode == "fixture" or bool(os.getenv("AMAN_CAMARA_TOOL_TOKEN")),
            },
        )

    def do_POST(self) -> None:
        if urlparse(self.path).path != "/v1/incidents/advise":
            self._json(HTTPStatus.NOT_FOUND, {"error": "not_found"})
            return
        if not self._authorized():
            self._json(HTTPStatus.UNAUTHORIZED, {"error": "unauthorized"})
            return
        content_type = self.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        if content_type != "application/json":
            self._json(HTTPStatus.UNSUPPORTED_MEDIA_TYPE, {"error": "application_json_required"})
            return
        try:
            length = int(self.headers.get("Content-Length", ""))
        except ValueError:
            self._json(HTTPStatus.LENGTH_REQUIRED, {"error": "valid_content_length_required"})
            return
        if length <= 0 or length > MAX_BODY_BYTES:
            self._json(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, {"error": "invalid_body_size"})
            return
        raw = self.rfile.read(length)
        try:
            request = AdviceRequest.model_validate_json(raw)
        except ValidationError as error:
            self._json(
                HTTPStatus.UNPROCESSABLE_ENTITY,
                {"error": "invalid_contract", "details": error.errors(include_url=False)},
            )
            return

        fingerprint = hashlib.sha256(raw).hexdigest()
        try:
            cached = REQUEST_CACHE.get(request.requestId, fingerprint)
        except ValueError:
            self._json(HTTPStatus.CONFLICT, {"error": "request_id_payload_conflict"})
            return
        if cached is not None:
            self._raw_json(HTTPStatus.OK, cached, request.requestId, cache_hit=True)
            return

        response = orchestrate(request)
        # Preserve explicit nulls so consumers receive one stable response shape.
        payload = response.model_dump_json().encode("utf-8")
        REQUEST_CACHE.put(request.requestId, fingerprint, payload)
        self._raw_json(HTTPStatus.OK, payload, request.requestId)

    def _authorized(self) -> bool:
        expected = os.getenv("AMAN_AGENT_SERVICE_TOKEN", "")
        if not expected:
            return True
        supplied = self.headers.get("Authorization", "")
        prefix = "Bearer "
        return supplied.startswith(prefix) and hmac.compare_digest(supplied[len(prefix) :], expected)

    def _json(self, status: HTTPStatus, body: dict[str, Any]) -> None:
        payload = json.dumps(body, separators=(",", ":"), default=str).encode("utf-8")
        self._raw_json(status, payload)

    def _raw_json(
        self,
        status: HTTPStatus,
        payload: bytes,
        request_id: str | None = None,
        cache_hit: bool = False,
    ) -> None:
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        if request_id:
            self.send_header("X-Request-ID", request_id)
        if cache_hit:
            self.send_header("X-AMAN-Idempotent-Replay", "true")
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format: str, *args: object) -> None:
        # Avoid logging request bodies, tokens, or query values.
        print(f"{self.address_string()} - {format % args}")


def main() -> None:
    host = os.getenv("AMAN_AGENT_HOST", "127.0.0.1")
    port = int(os.getenv("AMAN_AGENT_PORT", "8091"))
    if host not in {"127.0.0.1", "localhost", "::1"} and not os.getenv("AMAN_AGENT_SERVICE_TOKEN"):
        raise RuntimeError("AMAN_AGENT_SERVICE_TOKEN is required when binding beyond loopback")
    server = ThreadingHTTPServer((host, port), AmanAgentHandler)
    print(f"AMAN CAMARA agent v{AGENT_VERSION} listening on http://{host}:{port}")
    server.serve_forever()


if __name__ == "__main__":
    main()
