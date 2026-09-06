export function Notice({ children, error = false }) {
  if (!children) return null;
  return <p className={error ? 'notice error' : 'notice'} role={error ? 'alert' : 'status'}>{children}</p>;
}

export function Badge({ value = 'unknown', tone }) {
  const key = String(tone ?? value).toLowerCase().replace(/\s+/g, '_');
  return <span className={`badge badge-${key}`}>{String(value).replaceAll('_', ' ')}</span>;
}

export function time(value) {
  return value ? new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }) : 'No reading';
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
  if (!trend) return null;
  const arrow = trend.direction === 'up' ? '↑' : trend.direction === 'down' ? '↓' : '→';
  const amount = trend.direction === 'flat' ? 'steady' : `${trend.delta > 0 ? '+' : '−'}${Math.abs(trend.delta).toFixed(2)} /m²`;
  return <span className={`trend trend-${trend.direction}`}>{arrow} {amount} · {trend.seconds}s</span>;
}

// A short two-tone alert. Created per call so a suspended context from an
// unfocused window never leaves a dead reference behind.
export function playAlertTone() {
  const Context = window.AudioContext || window.webkitAudioContext;
  if (!Context) return;
  try {
    const context = new Context();
    const now = context.currentTime;
    [880, 1320].forEach((frequency, index) => {
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      const at = now + index * 0.16;
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(frequency, at);
      gain.gain.setValueAtTime(0.0001, at);
      gain.gain.exponentialRampToValueAtTime(0.09, at + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.14);
      oscillator.connect(gain).connect(context.destination);
      oscillator.start(at);
      oscillator.stop(at + 0.16);
    });
    window.setTimeout(() => context.close().catch(() => {}), 800);
  } catch {
    // Audio is a courtesy cue; the banner is the actual alert.
  }
}
