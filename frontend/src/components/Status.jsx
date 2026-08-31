import { words } from '../utils/format';

export function Status({ value, tone = '' }) {
  return <span className={`badge ${tone}`}>{words(value)}</span>;
}

export function Message({ children, error = false }) {
  return <div className={`message ${error ? 'error' : ''}`} role={error ? 'alert' : 'status'}>{children}</div>;
}
