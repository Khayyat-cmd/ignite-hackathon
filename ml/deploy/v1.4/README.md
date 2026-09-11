# AMAN ML v1.4

This is the frozen, Colab-trained AMAN v1.4 synthetic crowd-risk model. It predicts
the probability of a critical-density crossing in the next 30 seconds and p50/p90
of maximum density, then applies the same stateful alert policy used in evaluation.

## Run the service

From this directory:

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
python service.py
```

The default address is `http://127.0.0.1:8090`. In a container, set
`AMAN_ML_HOST=0.0.0.0`. Run exactly one process because alert history is currently
held in memory. The Laravel backend should call the service; the frontend and Unity
simulation should consume the backend's broadcast result.

Endpoints:

- `GET /health`
- `GET /v1/model-contract`
- `POST /v1/predict`
- `POST /v1/reset-event` with `venueId` and `eventId`

Use `example_inference_request.json` as the exact request example. Requests must
arrive every five seconds in timestamp order and include exactly the 55 features in
`feature_list.json`. The identifiers select independent alert state and are not model
features. Call `/v1/reset-event` after an event ends.

After starting the service, verify the full path with:

```powershell
curl.exe -X POST http://127.0.0.1:8090/v1/predict `
  -H "Content-Type: application/json" `
  --data-binary "@example_inference_request.json"
```

The service returns calibrated `probabilityCritical`, density p50/p90, the stateful
`decision`, data-quality status, model version and three raw-score drivers. Possible
decisions are `monitor`, `early_warning`, `currently_critical`, `outage`, and
`insufficient_data`. Keep current-critical and outage handling visible as separate
UI states.

The feature map must be constructed causally: build original features, then apply
`features_v14.augment` over the complete ordered timeline. Do not send labels,
future state, seeds or scenario identity. See `INTEGRATION.md` for precise behavior.

## Evidence and limitations

`evidence/metrics.json` contains the complete saved comparison, while
`evidence/paired_differences.json` contains whole-run paired uncertainty. This model
is trained and evaluated on synthetic simulations. It is suitable for the AMAN
hackathon prototype and does not establish real-world crowd-safety performance.
Alert delivery through Laravel/notifications requires an integration test.

Source review package SHA-256:
`10fb29dabcd815756b5bc8ceff81371e95a5513accb7520e2956fd0c5cede355`

Nested inference ZIP SHA-256:
`d89d5a5c7c1cfa64c7441203dd6e174a9484f79838050c7f81f5a97ecae57a5e`

Nested training-report ZIP SHA-256:
`0987e61bf231e5339b7c18b0d843982c0be2aa41dd22035097735cca57fd1294`
