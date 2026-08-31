export const words = (value = '') => value.replaceAll('_', ' ');
export const number = (value, digits = 0) =>
  typeof value === 'number' && Number.isFinite(value)
    ? new Intl.NumberFormat(undefined, { maximumFractionDigits: digits }).format(value)
    : 'Unknown';
export const time = (value) =>
  value && Number.isFinite(Date.parse(value)) ? new Date(value).toLocaleTimeString() : 'Not checked';
export const canResolve = (zone) =>
  Boolean(zone && zone.dataFreshness === 'fresh' && ['normal', 'warning'].includes(zone.risk_level));
export function responderReachability(responder) {
  if (responder.networkFreshness !== 'fresh') return responder.networkFreshness === 'stale' ? 'Stale evidence' : 'Not checked';
  const result = responder.signals?.reachability;
  return result == null ? 'Unknown' : result.dataReachable ? 'Data reachable' : 'Not data reachable';
}
