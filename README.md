# AMAN

AMAN is a crowd-risk monitoring prototype for venues and public events. A Laravel backend receives network information and simulated crowd readings, evaluates zone risk, coordinates responder incidents, and synchronizes three independent clients: an Electron control-room app, a Flutter responder app, and a standalone Unity simulation.

The Electron app lives in `frontend/`, where the existing React interface is used as its renderer. The Flutter app lives in `responder-mobile/`. The Unity application is developed separately by its owner. See [the architecture and delivery plan](notes.md), [the backend API](docs/backend-api.md), and [the standalone Unity contract](docs/unity-events.md).

## Local setup

Requirements: PHP 8.3+, Composer 2 and MySQL 8+.

```sh
cd backend
composer install
cp -n .env.example .env
php artisan key:generate
```

Create a MySQL database named `aman`, configure the database credentials in `backend/.env`, and set `AMAN_DEMO_ENABLED=true` for the local simulation. Then run:

```sh
php artisan migrate
php artisan config:clear
```

This build uses a local demo workspace created automatically on first use. No login or API token is required.

Start these processes from `backend/` in separate terminals:

```sh
php artisan serve
php artisan reverb:start
php artisan queue:work
php artisan schedule:work
```

Demo API base URL: `http://localhost:8000/api/v1/demo`.

The API base URL is a prefix, not a page. To check the API, open `http://localhost:8000/api/v1/demo/simulations`; the backend health endpoint is `http://localhost:8000/up`.

Start the Electron control-room app in another terminal:

```sh
cd frontend
nvm install
nvm use
npm ci
npm run dev
```

The frontend uses Node 24 (see `frontend/.nvmrc`); the commands above assume nvm is installed. `npm run build:desktop` creates a Linux AppImage in `frontend/release/`.

Run the Flutter responder app on an Android emulator:

```sh
cd responder-mobile
flutter pub get
flutter run
```

The Android emulator uses `http://10.0.2.2:8000/api/v1/demo` by default. For iOS Simulator or a physical phone, provide a reachable backend URL:

```sh
flutter run --dart-define=API_URL=http://127.0.0.1:8000/api/v1/demo
```

Replace `127.0.0.1` with the computer's LAN address for a physical phone. The hackathon build checks for missions every five seconds while open and shows new assignments in the app. Background push requires the team's Firebase and Apple credentials and is tracked as post-prototype setup.

## Provider setup

Copy the required variable names from `backend/.env.example` into the private `.env`. Never commit `.env`, API keys, client secrets or access tokens.

```sh
php artisan config:clear
php artisan aman:reachability-check
php artisan test
```

Set `OPENAI_API_KEY` to enable the incident response brief. The backend uses `gpt-5.6-luna` by default, sends structured incident context through the Responses API, and keeps dispatch behind explicit operator approval. Restart the queue worker after changing backend code or environment values.

The reachability check uses `CAMARA_API_KEY` with `NOKIA_REACHABILITY_ENABLED=true` to check the linked Nokia sandbox devices. Other network responses in the simulation are local fixtures. Sandbox responses and fictional attendees must not be presented as real people or live production-network data.
