"""Small stateful HTTP boundary for AMAN v1.4 inference."""
from __future__ import annotations

import json
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

from aman_inference.adapter_v14 import StatefulPredictor


MODEL_ROOT = Path(__file__).resolve().parent
PREDICTOR = StatefulPredictor(MODEL_ROOT)
LOCK = threading.RLock()
MAX_BODY_BYTES = 1 << 20


class Handler(BaseHTTPRequestHandler):
    server_version = "AMAN-ML/1.4"

    def _send(self, status: int, value: dict) -> None:
        body = json.dumps(value, allow_nan=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", os.getenv("AMAN_ML_ALLOW_ORIGIN", "*"))
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.end_headers()
        self.wfile.write(body)

    def _body(self) -> dict:
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError as error:
            raise ValueError("Invalid Content-Length") from error
        if length <= 0 or length > MAX_BODY_BYTES:
            raise ValueError("Request body must be between 1 byte and 1 MiB")
        value = json.loads(self.rfile.read(length))
        if not isinstance(value, dict):
            raise ValueError("JSON body must be an object")
        return value

    def do_OPTIONS(self) -> None:  # noqa: N802
        self._send(204, {})

    def do_GET(self) -> None:  # noqa: N802
        route = urlparse(self.path).path
        if route == "/health":
            self._send(200, {
                "status": "ok",
                "modelVersion": PREDICTOR.predictor.frozen["model_version"],
                "source": "synthetic_simulated",
                "featureCount": len(PREDICTOR.predictor.features),
                "statefulPolicy": True,
            })
        elif route == "/v1/model-contract":
            self._send(200, {
                "requiredFields": ["venueId", "eventId", "zoneId", "timestampSeconds", "features"],
                "features": PREDICTOR.predictor.features,
                "qualityPolicy": PREDICTOR.predictor.frozen["quality_policy"],
                "alertPolicy": PREDICTOR.policy.__dict__,
                "samplePeriodSeconds": 5,
            })
        else:
            self._send(404, {"error": "not_found"})

    def do_POST(self) -> None:  # noqa: N802
        route = urlparse(self.path).path
        try:
            request = self._body()
            if route == "/v1/predict":
                with LOCK:
                    response = PREDICTOR.predict(request)
                self._send(200, response)
                return
            if route == "/v1/reset-event":
                venue = request["venueId"]
                event = request["eventId"]
                with LOCK:
                    removed = [key for key in PREDICTOR.states if key[0] == venue and key[1] == event]
                    for key in removed:
                        del PREDICTOR.states[key]
                self._send(200, {"resetZones": len(removed)})
                return
            self._send(404, {"error": "not_found"})
        except (KeyError, TypeError, ValueError) as error:
            self._send(400, {"error": "invalid_request", "message": str(error)})
        except Exception:
            self._send(500, {"error": "prediction_failed"})

    def log_message(self, format: str, *args) -> None:
        print(f"{self.address_string()} - {format % args}")


def main() -> None:
    host = os.getenv("AMAN_ML_HOST", "127.0.0.1")
    port = int(os.getenv("AMAN_ML_PORT", "8090"))
    print(f"AMAN v1.4 inference listening on http://{host}:{port}")
    ThreadingHTTPServer((host, port), Handler).serve_forever()


if __name__ == "__main__":
    main()
