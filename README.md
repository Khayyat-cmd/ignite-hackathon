# AMAN

AMAN is a crowd-risk monitoring prototype for venues and public events. A Laravel backend receives network information and simulated crowd readings, evaluates zone risk, coordinates responder incidents, and broadcasts live updates to the website and Unity WebGL simulation.

## Local setup

Requirements: PHP 8.3+, Composer 2 and MySQL 8+.

```sh
cd backend
composer install
php artisan aman:configure
```

Create a MySQL database named `aman`, configure `backend/.env`, then run:

```sh
php artisan migrate
php artisan aman:token teammate@example.com --abilities=read,operate,ingest
```

Start these processes from `backend/` in separate terminals:

```sh
php artisan serve
php artisan reverb:start
php artisan queue:work
php artisan schedule:work
```

API base URL: `http://localhost:8000/api/v1`.

## Provider setup

Copy the required variable names from `backend/.env.example` into the private `.env`. Never commit `.env`, API keys, client secrets or access tokens.

```sh
php artisan config:clear
php artisan aman:camara-probe
php artisan aman:population-probe
php artisan test
```

The probes verify Nokia and Orange separately. Provider playground responses are suitable for the demo but must not be presented as real people or live production-network data.


