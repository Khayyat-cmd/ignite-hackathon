# AMAN implementation handoff

Last updated: 2026-09-05

Current implementation commits:

- `83f7d2d` — guarded backend incident advice and responder directory
- `23a0242` — Electron operator console
- `b593f59` — Flutter responder workflow
- The latest commit contains architecture, setup, and handoff documentation.

## Product decisions

AMAN is a hackathon prototype with one Laravel backend and three separate clients:

1. `frontend/` is the Electron control-room application. React is its renderer.
2. `responder-mobile/` is the Flutter responder application.
3. Unity runs as a standalone application on a second control-room screen and is owned by another developer.

Laravel is the source of truth. Clients do not communicate directly. The AI advisor is decision support: it may explain an incident and recommend an eligible responder, but an operator must approve or override the selection before dispatch.

## Implemented

### Laravel backend

- Demo API under `/api/v1/demo` for simulations, incidents, responder directory, missions, acknowledgement, and messages.
- Provider-neutral `IncidentAdvisor` contract with an OpenAI implementation.
- OpenAI Responses API request using strict JSON schema, `store: false`, low reasoning effort, and a 500-token output limit.
- Default model `gpt-5.6-luna`, configurable with `OPENAI_MODEL`.
- Advice runs as the queued, unique `GenerateIncidentAdvice` job.
- Candidate IDs are produced deterministically. Model output is rejected if it selects an ID outside the current candidate list.
- Advice failure leaves the deterministic ranking available.
- Operator approval accepts a current candidate override and records whether the advisor recommendation was accepted.
- A safe `GET /api/v1/demo/responders` directory supports the mobile demo without exposing phone numbers.

The user's real `OPENAI_API_KEY` is in `backend/.env`. A minimal live request returned HTTP 200 with a response ID on 2026-09-05. Never display or commit the key.

### Electron admin app

- Secure Electron shell with context isolation, renderer sandboxing, Node integration disabled, a narrow preload API, and external navigation controls.
- Professional dark control-room interface with simulation controls, crowd status, zone schematic, responder status, and incident coordination.
- Structured response brief with urgency, confidence, evidence, uncertainty, proposed action, and model metadata.
- Explicit operator responder selection and approval. The old React responder panel was removed.
- Linux AppImage packaging through `npm run build:desktop`.

### Flutter responder app

- Android and iOS Flutter scaffold.
- Environment-based API URL.
- Local demo call-sign selection persisted on the device.
- Five-second mission polling, foreground new-assignment banner, acknowledgement, en-route/on-scene updates, and mission messaging.
- Professional field-operations visual system aligned with the desktop app.

## Run locally

Backend terminals from `backend/`:

```sh
php artisan serve
php artisan reverb:start
php artisan queue:work
php artisan schedule:work
```

After backend or environment changes, run `php artisan config:clear` and restart the queue worker with `php artisan queue:restart`.

Electron app from `frontend/` using Node 24:

```sh
nvm use
npm ci
npm run dev
```

Flutter app from `responder-mobile/`:

```sh
flutter pub get
flutter run
```

The Android emulator defaults to `http://10.0.2.2:8000/api/v1/demo`. Use `--dart-define=API_URL=...` for iOS Simulator or a physical phone.

## Validation

```sh
cd backend
vendor/bin/pint --dirty --format agent
DB_CONNECTION=mysql DB_DATABASE=aman_test php artisan test --compact tests/Feature/IncidentAdviceTest.php
DB_CONNECTION=mysql DB_DATABASE=aman_test php artisan test --compact tests/Feature/DemoApiTest.php

cd ../frontend
nvm use
npm test
npm run build:desktop

cd ../responder-mobile
flutter analyze
flutter test
```

The focused backend tests, 9 renderer tests, Flutter analysis, Flutter widget test, and Electron AppImage build pass. The repository's older full backend suite has pre-existing failures because it references removed authenticated routes and service classes such as `NokiaNetwork`, `OrangePopulationDensity`, and `PublishDomainEvent`; do not attribute those failures to the new three-client work without checking the baseline history.

## Remaining work

1. Add Firebase/APNs credentials, device-token registration, and background push after the team chooses the relevant accounts. The current mobile notification is foreground polling only.
2. Add real operator/responder authentication when the prototype moves beyond local demo identities.
3. Add sequence-based realtime replay for Electron and Unity reconnects.
4. Give the Unity developer the snapshot/event fixtures in `docs/unity-events.md` and run the two-screen rehearsal.
5. Add branded desktop/mobile store icons if presentation time allows.
6. Run a full rehearsal: create and start a simulation, wait for an incident, inspect AI advice, override or accept the responder, acknowledge in Flutter, report on scene, and resolve after safe readings.

## Important files

- `notes.md`: architecture and delivery status.
- `docs/backend-api.md`: current demo API and invariants.
- `docs/unity-events.md`: Unity ownership and event contract.
- `backend/app/Services/Ai/OpenAiIncidentAdvisor.php`: structured model call and validation.
- `backend/app/Jobs/GenerateIncidentAdvice.php`: asynchronous advice lifecycle.
- `frontend/electron/main.cjs`: Electron security boundary.
- `frontend/src/components/OperatorPanel.jsx`: operator incident and advisor experience.
- `responder-mobile/lib/src/responder_app.dart`: responder workflow UI.
- `responder-mobile/lib/src/api.dart`: mobile demo API client.
