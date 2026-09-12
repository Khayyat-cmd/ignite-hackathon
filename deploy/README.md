# AMAN VPS deployment

Target: `aman.baraaelbaba.com` on `62.171.175.172`, alongside the other projects
already hosted there. Everything AMAN owns lives in `/var/www/aman`, the `aman`
MySQL database, the `aman-agent` / `aman-queue` / `aman-schedule` systemd units,
and one nginx vhost. Nothing else on the box is touched.

## What gets served

| Path | Serves |
| --- | --- |
| `/` | Operator console — the Electron renderer built as a static site (`frontend/dist`) |
| `/api/…` | Laravel front controller through PHP-FPM (`backend/public/index.php`) |
| `/downloads/aman-responder.apk` | Signed-with-debug-key release APK for attendees |

The console is built with `VITE_API_URL=https://aman.baraaelbaba.com/api/v1/demo`
and the APK with the same URL plus `ALLOW_SERVER_CONFIGURATION=false`, so nobody
has to type a server address on the day.

Two things are not public. The CAMARA orchestration agent (`ml/agent`) runs as
`aman-agent.service` on `127.0.0.1:8091`, and Laravel's private tool gateway is
served to it by a loopback-only nginx listener on `127.0.0.1:8127`; the public
vhost denies `/api/internal/`. `release.sh` generates the two bearer tokens on
the server the first time and reuses them after that, so neither is ever typed
or committed. The agent's `OPENAI_API_KEY` lives only in
`/var/www/aman/agent/agent.env` (root-owned, `0640`, group `www-data`).

## Deploy

```sh
OPENAI_API_KEY=… DB_PASSWORD=… bash deploy/release.sh
```

It builds both artifacts, rsyncs them up, writes the production `.env` values,
uploads the Firebase service account if one is linked locally, then runs
`deploy/provision.sh` on the server (composer install, migrate, caches, nginx,
certbot, systemd) and verifies the three URLs.

`OPENAI_API_KEY` is only needed on the first deploy or when the key changes; it
goes straight into the server `.env` and is never echoed. Redeploys can omit it.

## Server-side pieces

- `nginx-aman.conf` — vhost template; `__PHP_FPM_SOCK__` is substituted at provision time.
- `provision.sh` — idempotent server setup, safe to re-run.
- `systemd/aman-queue.service` — `queue:work` for advice generation and push sends.
- `systemd/aman-schedule.service` — `schedule:work`; the simulation ticks every five seconds, so cron-per-minute is not enough.

## Notes

- Broadcasting (Reverb) is not deployed: neither client opens a websocket, both poll.
- The APK is signed with the Flutter debug key. Fine for a hackathon sideload; attendees must allow "install unknown apps".
- Push notifications need `storage/app/private/firebase-service-account.json` on the server, mode 600, owned by `www-data`.
