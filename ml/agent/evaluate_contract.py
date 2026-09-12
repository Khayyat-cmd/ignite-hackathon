"""Offline judge gate: validate examples, evaluation manifest, and public schemas."""

from __future__ import annotations

import json
from pathlib import Path

from schemas import AdviceRequest, CamaraOperation


ROOT = Path(__file__).resolve().parent


def main() -> None:
    request = AdviceRequest.model_validate_json(
        (ROOT / "examples" / "fixture_request.json").read_text(encoding="utf-8")
    )
    scenarios = json.loads((ROOT / "evals" / "scenarios.json").read_text(encoding="utf-8"))
    scenario_ids = [item["id"] for item in scenarios]
    if len(scenario_ids) != len(set(scenario_ids)):
        raise ValueError("evaluation scenario IDs must be unique")
    known_operations = {item.value for item in CamaraOperation}
    for scenario in scenarios:
        unknown = set(scenario["expected"].get("mustCall", [])) - known_operations
        if unknown:
            raise ValueError(f"{scenario['id']} references unknown CAMARA tools: {sorted(unknown)}")
        if scenario["expected"].get("humanApproval") is not True:
            raise ValueError(f"{scenario['id']} must preserve human approval")
    print(
        json.dumps(
            {
                "status": "PASS",
                "validatedRequest": request.requestId,
                "evaluationScenarios": len(scenarios),
                "camaraTools": sorted(known_operations),
                "dispatchTools": 0,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()

