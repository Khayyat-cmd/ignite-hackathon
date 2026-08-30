// Optional integration check: Node 22+, PHP and a dedicated disposable MySQL DB.
// Never points at the main application database. No third-party JS packages needed.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

if (process.env.DB_DATABASE !== 'aman_realtime_test') {
  throw new Error('Set DB_DATABASE=aman_realtime_test and use a dedicated test MySQL instance.');
}
const cwd = fileURLToPath(new URL('../', import.meta.url));
const php = process.env.AMAN_PHP_BINARY || 'php';
const env = {
  ...process.env,
  APP_ENV: 'testing', APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  AMAN_DEMO_ENABLED: 'true', CAMARA_MODE: 'disabled', CAMARA_API_KEY: '',
  BROADCAST_CONNECTION: 'reverb', QUEUE_CONNECTION: 'database', CACHE_STORE: 'database',
  REVERB_APP_ID: 'smoke', REVERB_APP_KEY: 'smoke', REVERB_APP_SECRET: randomBytes(32).toString('hex'),
  REVERB_HOST: '127.0.0.1', REVERB_PORT: '8187', REVERB_SCHEME: 'http',
  REVERB_ALLOWED_ORIGINS: '*', // Isolated localhost-only test server, not application config.
};
const children = [];
let socket;
function artisan(...args) {
  const result = spawnSync(php, ['artisan', ...args], { cwd, env, encoding: 'utf8', timeout: 20000, windowsHide: true });
  if (result.status !== 0) throw new Error(`Artisan ${args[0]} failed: ${result.stderr || result.stdout}`);
  return result.stdout;
}
function start(args) {
  const child = spawn(php, args, { cwd, env, windowsHide: true, detached: process.platform !== 'win32', stdio: 'ignore' });
  children.push(child);
  child.on('error', error => { console.error('Test process startup failed:', error.message); });
  return child;
}
async function waitUntil(check, timeout = 15000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    try { if (await check()) return; } catch { /* server is starting */ }
    await delay(100);
  }
  throw new Error('Timed out waiting for the test service/event.');
}

try {
  artisan('migrate', '--force');
  artisan('aman:demo', '--count=60');
  const output = artisan('aman:token', 'realtime-test@example.test', '--abilities=read');
  const token = output.split(/\r?\n/).find(line => /^\d+\|\S+$/.test(line.trim()))?.trim();
  if (!token) throw new Error('Could not issue test access token.');

  start(['-S', '127.0.0.1:8017', '-t', 'public', 'public/index.php']);
  start(['artisan', 'reverb:start', '--host=127.0.0.1', '--port=8187']);
  start(['artisan', 'queue:work', '--sleep=1', '--tries=1', '--timeout=65']);
  await waitUntil(async () => (await fetch('http://127.0.0.1:8017/up')).ok);
  await delay(1000);

  const messages = [];
  socket = new WebSocket('ws://127.0.0.1:8187/app/smoke?protocol=7&client=aman-smoke&version=1.0');
  socket.addEventListener('message', message => messages.push(JSON.parse(message.data)));
  await waitUntil(() => messages.some(message => message.event === 'pusher:connection_established'));
  const connected = JSON.parse(messages.find(message => message.event === 'pusher:connection_established').data);
  const response = await fetch('http://127.0.0.1:8017/api/broadcasting/auth', {
    method: 'POST', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ socket_id: connected.socket_id, channel_name: 'private-operations' }),
  });
  if (!response.ok) throw new Error(`Channel authorization failed: ${response.status}`);
  socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'private-operations', ...(await response.json()) } }));
  await waitUntil(() => messages.some(message => message.event === 'pusher_internal:subscription_succeeded'));
  artisan('aman:demo', '--count=250');
  // schedule:work waits for the next minute boundary on startup; trigger the
  // scheduler directly after test data exists to avoid a timing-dependent test.
  start(['artisan', 'schedule:run']);
  await waitUntil(() => messages.some(message => message.event === 'density_updated' && JSON.parse(message.data).data.deviceCount === 250), 25000);
  const replay = await fetch('http://127.0.0.1:8017/api/v1/events', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  if (!replay.ok || !(await replay.json()).data.some(event => event.event === 'danger_detected')) {
    throw new Error('Durable event replay did not contain the incident.');
  }
  console.log('PASS: MySQL -> transactional outbox -> scheduler -> queue -> Reverb private channel -> WebSocket client; REST replay verified.');
} finally {
  socket?.close();
  for (const child of children.reverse()) {
    if (!child.pid) continue;
    try {
      if (process.platform === 'win32') {
        child.kill(); // All test PHP processes are started directly, without shell wrappers.
      } else {
        process.kill(-child.pid, 'SIGTERM');
      }
      child.unref();
    } catch { /* Already exited. */ }
  }
}
