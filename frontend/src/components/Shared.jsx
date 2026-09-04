export function Notice({ children, error = false }) {
  if (!children) return null;
  return <p className={error ? 'notice error' : 'notice'} role={error ? 'alert' : 'status'}>{children}</p>;
}

export function Badge({ value = 'unknown' }) {
  return <span className={`badge badge-${String(value).toLowerCase()}`}>{String(value).replaceAll('_', ' ')}</span>;
}

export function time(value) {
  return value ? new Date(value).toLocaleTimeString() : 'No reading';
}

export function Field({ label, ...props }) {
  return <label><span>{label}</span><input {...props} /></label>;
}
