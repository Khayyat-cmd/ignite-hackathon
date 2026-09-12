# AMAN implementation handoff

Last updated: 2026-09-12

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

`ml/agent/` is a fourth process, not a client: the CAMARA orchestration agent
(OpenAI Agents SDK). Only Laravel calls it, and it only calls Laravel back.

Laravel is the source of truth. Clients do not communicate directly. The AI advisor is decision support: it may explain an incident and recommend an eligible responder, but an operator must approve or override the selection before dispatch.

## Implemented

### Laravel backend

- Demo API under `/api/v1/demo` for simulations, incidents, responder directory, missions, acknowledgement, and messages.
- Attendee positions live on disk, not in the run row: `PositionStore` writes
  `storage/app/private/simulation/positions/{run}.json` each tick and the `client=unity`
  snapshot pages its slices from there. Persisting 1.45 MB of positions in
  `simulation_runs.snapshot` every five seconds is what filled the server's disk with
  MySQL binary logs on 2026-09-12.
- Provider-neutral `IncidentAdvisor` contract with two implementations. `AgentIncidentAdvisor` calls the CAMARA orchestration agent and is bound whenever `AMAN_AGENT_URL` is set; `OpenAiIncidentAdvisor` is the single-call fallback for a machine not running the agent.
- `POST /api/internal/agent/camara` is the agent's private tool gateway, served by `CamaraEvidenceGateway`. Bearer-token authenticated, 503 when unconfigured, denied by the public vhost, and reachable in production only over a loopback nginx listener on `127.0.0.1:8127`.
- The gateway declares provenance per operation and never upgrades it: Device Reachability is a live Nokia Network-as-Code call (`live_camara`); location verification and congestion insights come from the venue simulation (`simulated_fixture`). A run touching any simulated source reports `evidenceMode: simulated_fixture`.
- The gateway refuses a `responderId` the backend did not rank as eligible for that incident, so a hallucinated ID never reaches a provider.
- Default model `gpt-5.6-luna`, configurable with `OPENAI_MODEL`.
- Advice runs as the queued, unique `GenerateIncidentAdvice` job (55s timeout; an agent run makes several CAMARA calls before it answers).
- Candidate IDs are produced deterministically. Advisor output is rejected if it selects an ID outside the current candidate list, or if it fails the response contract.
- Advice failure, and a `degraded` agent run, both leave the deterministic ranking as the operational answer.
- `GET /missions` carries a per-mission `brief` of `{urgency, networkCongested}` — the field-facing slice of the approved advice. The agent's prose is deliberately not forwarded, because it is written for the operator deciding a dispatch.
- Operator approval accepts a current candidate override and records whether the advisor recommendation was accepted.
- A safe `GET /api/v1/demo/responders` directory supports the mobile demo without exposing phone numbers.

The user's real `OPENAI_API_KEY` is in `backend/.env`. A minimal live request returned HTTP 200 with a response ID on 2026-09-05. Never display or commit the key.

### Electron admin app

- Secure Electron shell with context isolation, renderer sandboxing, Node integration disabled, a narrow preload API, and external navigation controls.
- Fixed three-pane control-room shell that fills the window and never scrolls the page: a left rail with the situation summary and zone conditions, a centre stage with the zone schematic and the response team, and a right rail with the incident queue and the selected incident.
- The zone schematic carries the operational overlay: an incident badge in each affected zone's top-right corner, a pin per located responder (from `signals.location`), and a dashed link with the backend distance between the focused incident and its responder. Zone name and headcount sit in a label band above each polygon so pins and badges never cover them. The band is laid out left to right and a label wider than its own zone lifts to the row above, so a narrow zone's headcount is never painted over by its neighbour's label.
- An attention bar above the workspace counts incidents awaiting dispatch approval, names their zones, jumps to the oldest, and sounds a short WebAudio cue when the count rises. The cue is mutable and the preference persists in `localStorage`; new queue rows flash briefly on arrival.
- Zone rows carry a client-side density trend: a sparkline over the last 24 polls plus a signed delta and window (`↑ +0.28 /m² · 30s`). History lives only in the renderer and resets with the window.
- One focus links the three panes. Selecting an incident highlights its zone on the map and in the left rail and highlights the responder assigned or recommended for it; selecting a zone filters the queue and opens its incident; selecting a responder opens the incident it is assigned to. Responder cards show their current assignment and their live mobile-data reachability; the old "Updated <time>" and "Location ±<n> m" tags were removed as unreadable mid-incident.
- The operator approval and resolution blocks are sticky to the bottom of the right rail, so the primary decision is never below the fold.
- Simulation transport (start/resume, pause, stop, reset) and event selection live in the top bar; the rehearsal-data disclaimer, sample time, revision, and backend reachability live in a single status strip.
- Neutral graphite chrome with a single blue interaction accent, so green/amber/red are only ever used for risk and status. Type, spacing, and radii follow one token scale; numeric readouts are tabular.
- Structured response brief with urgency, confidence, evidence, uncertainty, proposed action, and the agent runtime and version.
- The brief carries the agent's CAMARA evidence trail: a collapsed "N network checks" panel with an `M of N live` chip, then each call in the order the agent made it — API name, LIVE or SIM provenance, the sanitized result, the agent's own reason, and the endpoint and time. A `degraded` run is badged as carrying no AI recommendation instead of implying one.
- The agent writes in English even for an Arabic operator, so its own sentences carry `dir="auto"`; the browser then reads direction from the text rather than parking an English full stop against the right-hand edge.
- Explicit operator responder selection and approval. The old React responder panel was removed.
- English and Arabic, chosen from a switch in the top bar and persisted in `localStorage`. Selecting Arabic sets `dir="rtl"` and `lang="ar"` on the document; the layout follows through CSS logical properties, and Arabic drops the letter-spacing that would break letter joining.
- Selecting a zone or an incident publishes the operator's focus to the backend, and the standalone Unity screen follows it by polling its own snapshot. The venue pane names what the second screen is framing. The contract, including the camera target in simulation metres and the `sequence` Unity uses to ignore late snapshots, is in `docs/unity-events.md`.
- Linux AppImage packaging through `npm run build:desktop`.

### Flutter responder app

- Android and iOS Flutter scaffold.
- Environment-based API URL.
- Local demo call-sign selection persisted on the device.
- Five-second mission polling, acknowledgement, en-route/on-scene updates, and mission messaging.
- A new assignment triggers haptic feedback and a full-screen takeover that stays until it is acknowledged, with repeated pulses capped at six. The old snackbar remains as the fallback for an assignment that arrives already acknowledged.
- The mission action (acknowledge, then heading there / on scene) is pinned to the bottom of the screen and hides while the keyboard is open.
- The connection pill reports the real poll state — LIVE, DELAYED, RECONNECTING, or OFFLINE — with the age of the last successful sync.
- The mission thread scrolls inside its own bounded box, labels each message with its sender and time, renders progress events as centred system lines, and shows an unread badge for control-room instructions until the responder scrolls or taps the thread.
- English and Arabic, chosen from a switch in the app header and persisted in `SharedPreferences`. Flutter's `flutter_localizations` delegates supply the right-to-left direction and the Material widget strings.
- A control-room brief block on the mission card, shown only when it has something to say: a CRITICAL marker with the zone's density state, and the agent's Congestion Insights finding turned into an instruction — use radio if a message does not go through. A calm zone on a healthy network earns no block rather than a reassuring one to read.
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

CAMARA orchestration agent from `ml/agent/` (its own virtualenv):

```sh
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
AMAN_CAMARA_MODE=backend \
AMAN_AGENT_SERVICE_TOKEN=… AMAN_CAMARA_TOOL_TOKEN=… \
OPENAI_API_KEY=… .venv/bin/python service.py
```

Set the matching `AMAN_AGENT_URL`, `AMAN_AGENT_SERVICE_TOKEN` and
`AMAN_CAMARA_GATEWAY_TOKEN` in `backend/.env`. Without `AMAN_AGENT_URL` Laravel
falls back to the single-call advisor, so the console still shows a brief — just
no tool trace. `AMAN_CAMARA_MODE=fixture` exercises the agent loop with no
backend and no CAMARA quota.

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
DB_CONNECTION=mysql DB_DATABASE=aman_test php artisan test --compact tests/Feature/AgentIncidentAdviceTest.php

cd ../ml/agent
.venv/bin/python -m unittest discover -s tests
.venv/bin/python evaluate_contract.py

cd ../../frontend
nvm use
npm test
npm run build:desktop

cd ../responder-mobile
flutter analyze
flutter test
```

The agent checks are offline and spend no OpenAI or CAMARA quota.

The focused backend tests (including 5 new agent tests), 23 agent contract tests, 25 renderer tests, Flutter analysis, 14 Flutter widget tests, and the Electron AppImage build pass. The backend tests above were not re-run for the UI work; the renderer, Flutter, and packaging commands were. The repository's older full backend suite has pre-existing failures because it references removed authenticated routes and service classes such as `NokiaNetwork`, `OrangePopulationDensity`, and `PublishDomainEvent`; do not attribute those failures to the new three-client work without checking the baseline history.

## Localization

Both clients ship English and Arabic. There is no translation framework and no build step: each app holds one flat key/value catalogue per language.

- Electron: `frontend/src/i18n/strings.js` holds the catalogues, `frontend/src/i18n/index.jsx` the provider, the `useI18n()` hook, and the `LanguageToggle`. Nothing calls `toLocaleString` directly — `n()`, `time()`, `term()`, `role()` and `kind()` go through the hook, so one locale change moves every figure and every backend status word at once. Arabic keeps Latin digits (`ar-u-nu-latn`) so a reading looks like the same number in both languages, and plural keys resolve through `Intl.PluralRules`, which gives Arabic its six cardinal forms.
- Flutter: `responder-mobile/lib/src/l10n.dart` holds both catalogues, the `Strings` lookup, and the `L10n` inherited scope. The scope is installed through `MaterialApp.builder` so pushed routes — the full-screen assignment takeover among them — read the same language.
- The venue schematic never mirrors. Geography is not text, so the SVG stays `direction: ltr` and a zone an operator learned on the left stays on the left.
- Both catalogue tests fail the build on a key that Arabic is missing, on placeholders that differ between languages, and on an Arabic value still written in Latin script.
- Text that comes from outside the catalogues is not translated: zone and responder names as the backend stores them, operator instructions and responder replies as they were typed, and the model's own words in the response brief and the assistant. The assistant tells an Arabic operator that it answers in English.

## Deployment

The demo is live at **https://aman.baraaelbaba.com** on the shared VPS `62.171.175.172`,
alongside the other projects hosted there. AMAN owns only `/var/www/aman`, the `aman`
MySQL database, the `aman-agent` / `aman-queue` / `aman-schedule` systemd units, and its
own nginx vhost.

| Path | Serves |
| --- | --- |
| `/` | Operator console, the Electron renderer built as a static site |
| `/api/…` | Laravel front controller through PHP 8.4 FPM |
| `/downloads/aman-responder.apk` | Release APK for attendees |
| `127.0.0.1:8091` | CAMARA orchestration agent, `aman-agent.service` — loopback only |
| `127.0.0.1:8127` | nginx listener serving the agent Laravel's tool gateway — loopback only, its own vhost file so certbot never rewrites it |

Deploy with `OPENAI_API_KEY=… CAMARA_API_KEY=… DB_PASSWORD=… bash deploy/release.sh`.
It builds both artifacts, ships them along with `ml/agent`, writes the production
environment, provisions the server, and verifies the URLs. See `deploy/README.md`.

The two bearer tokens the agent path needs — Laravel to agent, and agent back to the
tool gateway — are generated on the server on the first deploy and reused after that,
so neither is ever typed, printed, or committed. The agent's `OPENAI_API_KEY` lives only
in `/var/www/aman/agent/agent.env`, root-owned and `0640` to group `www-data`; Laravel's
own copy is no longer what produces advice. The public vhost denies `/api/internal/`.

The console is built with `VITE_API_URL=https://aman.baraaelbaba.com/api/v1/demo` and the
APK with `--dart-define=API_URL=` the same value. Baking that URL in is what retires the
server-address screen: `AmanApi._allowServerConfiguration` defaults to false whenever
`API_URL` differs from the emulator default, and `test/widget_test.dart` pins that. The
separate `ALLOW_SERVER_CONFIGURATION` define is still accepted but must not be relied on —
passing it to `flutter build apk` produced a byte-identical `libapp.so` either way, which is
why the first APK published here still asked for an IP address. Reverb is not deployed: neither client opens a websocket.

### Disk exhaustion, 2026-09-12

The demo went down with every `/api/…` request hanging until the console's 45-second
timeout, while the static console still loaded. The VPS root disk was 100% full:
`/var/lib/mysql` held 1375 binary logs totalling 134 GB, against databases of about
100 MB. MySQL's default `binlog_expire_logs_seconds` is 30 days and nothing on this
box replicates, so nothing ever trimmed them; with the disk full every write blocked
(`errno 28`, then `1205 Lock wait timeout exceeded` on the `cache` table) and the
PHP-FPM pool filled with stuck requests.

What produced that volume: the five-second tick rewrote `simulation_runs.snapshot`,
a 1.45 MB JSON blob of all attendee positions, and row-format binary logging records
the before and after image of the row — roughly 3 MB of binary log per tick, about
2 GB for every hour of simulation.

Two fixes: `App\Services\Simulation\PositionStore` now keeps attendee positions in
`storage/app/private/simulation/positions/{run}.json` instead of the run row, so a tick
writes kilobytes to MySQL and the Unity read path slices the same list from disk; and
`deploy/provision.sh` writes `/etc/mysql/mysql.conf.d/zz-aman-binlog.cnf` with a
one-day retention and `binlog_row_image = MINIMAL`, then purges. The API contract is
unchanged — `client=unity` still returns paged `positions` with `nextOffset`.

Recovering a full disk needs care: `PURGE BINARY LOGS` itself hangs at zero free space,
because MySQL cannot rewrite `binlog.index`. Free a few GB elsewhere first, then purge.

Verified live on 2026-09-07: HTTPS with the redirect from port 80, simulation ticking every
five seconds, Nokia reachability returning three of four responders data-reachable, an
incident detected at critical density, queued OpenAI advice returning a validated brief,
operator approval producing a dispatched mission the responder API serves, and FCM
authenticating with the server-side service account.

Verified locally on 2026-09-12, against the real agent and the real Nokia sandbox: an
incident at critical density produced a `completed` agent run with five CAMARA calls —
three live Device Reachability checks, one at a time, one per candidate, then Congestion
Insights because the incident was critical, then Location Verification of the responder
it settled on. The console rendered the trace as `5 network checks · 3 of 5 live` in both
languages, and `GET /missions` carried `{"urgency":"critical","networkCongested":false}`
to the phone. The deployed path had not been exercised at the time of writing.

## Remaining work

1. Deliver a push to a real handset. Firebase credentials, device-token registration, and the queued send are in place and FCM accepts the server's service account, but no physical device has been registered yet.
2. Add real operator/responder authentication when the prototype moves beyond local demo identities.
3. Add sequence-based realtime replay for Electron and Unity reconnects.
4. Run the two-screen rehearsal with the Unity developer. The backend contract and fixtures are in `docs/unity-events.md`; the camera-follow side is served and verified, but nothing has consumed it in Unity yet.
5. Pass the operator's language to the AI so the response brief, the tool-trace reasons, and the assistant answer in Arabic. The brief is generated by a queued, server-initiated job, so the run needs to carry a language before the advisor and `OpenAiOperatorCopilot` can be told which one to write in. For the agent that means a language field on `AdviceRequest` and a line in its instructions; the console already shows the English text with `dir="auto"` in the meantime.
6. Add branded desktop/mobile store icons if presentation time allows.
7. iOS distribution is deliberately out of scope for the demo. There is no sideload path on iPhone: TestFlight or ad-hoc signing both need a Mac and a paid Apple Developer account, and ad-hoc also needs every device's UDID registered. The `ios/` target still builds for anyone with a Mac. iPhone attendees follow the operator console instead.
8. Run a full rehearsal: create and start a simulation, wait for an incident, inspect AI advice, override or accept the responder, acknowledge in Flutter, report on scene, and resolve after safe readings.
9. Serve Congestion Insights and Location Verification from Nokia Network-as-Code instead of the venue simulation. Only Device Reachability is live today, which is why `evidenceMode` on a real run reads `simulated_fixture`. Nothing in the agent changes: the gateway declares provenance per operation, so each one flips to `live_camara` as it is wired up. `app/Services/Ai/CamaraEvidenceGateway.php` is the single place to change.
10. The agent's `evidenceSummary` says "Simulated fixture: …" for venue-simulation evidence, which is honest but reads oddly next to a live Nokia line. Once item 9 lands most of those disappear; if it does not, the wording is worth softening in `ml/agent/orchestrator.py`.

## Important files

- `notes.md`: architecture and delivery status.
- `docs/backend-api.md`: current demo API and invariants.
- `docs/unity-events.md`: Unity ownership and event contract.
- `ml/README.md`: the agent's own decision flow, contract, and offline checks.
- `ml/agent/orchestrator.py`: the agent, its instructions, and the invariants a recommendation must pass.
- `backend/app/Services/Ai/AgentIncidentAdvisor.php`: the call to the agent and the mapping onto the console's advice shape.
- `backend/app/Services/Ai/CamaraEvidenceGateway.php`: CAMARA evidence normalization and per-operation provenance.
- `backend/app/Http/Controllers/AgentCamaraController.php`: the private tool gateway and its token check.
- `backend/app/Services/Ai/OpenAiIncidentAdvisor.php`: the single-call fallback advisor.
- `backend/app/Jobs/GenerateIncidentAdvice.php`: asynchronous advice lifecycle.
- `frontend/electron/main.cjs`: Electron security boundary.
- `frontend/src/App.jsx`: window shell, top bar, status strip, and simulation transport.
- `frontend/src/components/OperatorPanel.jsx`: operator incident and advisor experience.
- `frontend/src/styles.css`: the console's colour, type, and spacing tokens, plus the right-to-left rules.
- `frontend/src/i18n/strings.js`: the console's English and Arabic copy.
- `responder-mobile/lib/src/l10n.dart`: the responder app's English and Arabic copy.
- `responder-mobile/lib/src/responder_app.dart`: responder workflow UI.
- `responder-mobile/lib/src/api.dart`: mobile demo API client.
