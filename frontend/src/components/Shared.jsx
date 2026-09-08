import { useI18n } from '../i18n';

export function Notice({ children, error = false }) {
  if (!children) return null;
  return <p className={error ? 'notice error' : 'notice'} role={error ? 'alert' : 'status'}>{children}</p>;
}

// `value` stays the raw backend vocabulary so the risk colour keeps deriving
// from it; only the text is translated. `label` overrides the text where the
// badge reads as a phrase rather than a single term.
export function Badge({ value = 'unknown', tone, label }) {
  const { term } = useI18n();
  const key = String(tone ?? value).toLowerCase().replace(/\s+/g, '_');
  return <span className={`badge badge-${key}`}>{label ?? term(value)}</span>;
}

export function clock(seconds = 0) {
  const total = Math.max(0, Math.floor(seconds));
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function riskClass(level, stale = false) {
  return `risk-${stale ? 'unknown' : level || 'unknown'}`;
}

// Monitoring mark: a signal arc over a point. Drawn, not lettered, so the app
// chrome does not rely on a placeholder initial.
export function Mark() {
  return <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
    <circle cx="9" cy="13.2" r="2" fill="currentColor" />
    <path d="M4.9 9.9a5.6 5.6 0 0 1 8.2 0" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    <path d="M1.9 6.3a9.6 9.6 0 0 1 14.2 0" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" opacity=".45" />
  </svg>;
}

// Sparkline over the client-side density series. Purely a direction cue, so it
// carries no axis and no colour of its own.
export function Sparkline({ samples = [] }) {
  if (samples.length < 2) return null;
  const values = samples.map((sample) => sample.density);
  const low = Math.min(...values);
  const span = Math.max(...values) - low || 1;
  const points = values
    .map((value, index) => `${((index / (values.length - 1)) * 44).toFixed(1)},${(12 - ((value - low) / span) * 10).toFixed(1)}`)
    .join(' ');
  return <svg className="sparkline" viewBox="0 0 44 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <polyline points={points} />
  </svg>;
}

export function TrendTag({ trend }) {
  const { t, dir } = useI18n();
  if (!trend) return null;
  // The flat arrow points along the reading direction, so "no change" never
  // looks like it is pointing back at the previous sample.
  const flat = dir === 'rtl' ? '←' : '→';
  const arrow = trend.direction === 'up' ? '↑' : trend.direction === 'down' ? '↓' : flat;
  const amount = trend.direction === 'flat'
    ? t('trend.steady')
    : `${trend.delta > 0 ? '+' : '−'}${Math.abs(trend.delta).toFixed(2)} /m²`;
  return <span className={`trend trend-${trend.direction}`} dir="ltr">{arrow} {amount} · {t('trend.seconds', { count: trend.seconds })}</span>;
}

// A two-tone emergency siren. An incident is a life-safety event, so the cue is
// loud and sweeps rather than chimes. Created per call so a suspended context
// from an unfocused window never leaves a dead reference behind.
export function playAlertTone() {
  const Context = window.AudioContext || window.webkitAudioContext;
  if (!Context) return;
  try {
    const context = new Context();
    const now = context.currentTime;
    const master = context.createGain();
    master.gain.setValueAtTime(0.32, now);
    master.connect(context.destination);
    // Three rising sweeps, 620 Hz to 1180 Hz, the way a two-tone siren wails.
    for (let sweep = 0; sweep < 3; sweep += 1) {
      const at = now + sweep * 0.42;
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'sawtooth';
      oscillator.frequency.setValueAtTime(620, at);
      oscillator.frequency.linearRampToValueAtTime(1180, at + 0.22);
      oscillator.frequency.linearRampToValueAtTime(680, at + 0.34);
      gain.gain.setValueAtTime(0.0001, at);
      gain.gain.exponentialRampToValueAtTime(1, at + 0.03);
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.36);
      oscillator.connect(gain).connect(master);
      oscillator.start(at);
      oscillator.stop(at + 0.4);
    }
    window.setTimeout(() => context.close().catch(() => {}), 2200);
  } catch {
    // Audio is a courtesy cue; the banner is the actual alert.
  }
}
