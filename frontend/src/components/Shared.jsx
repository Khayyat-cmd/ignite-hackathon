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
