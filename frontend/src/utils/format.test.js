import { describe, expect, it } from 'vitest';
import { canResolve, number, responderReachability, time } from './format';

describe('display safety rules', () => {
  it('does not display missing values as zero', () => {
    expect(number(null)).toBe('Unknown');
    expect(number(undefined)).toBe('Unknown');
    expect(number(0)).toBe('0');
    expect(time('invalid')).toBe('Not checked');
  });
  it('never presents stale reachability as current', () => {
    expect(responderReachability({ networkFreshness: 'stale', signals: { reachability: { dataReachable: true } } })).toBe('Stale evidence');
    expect(responderReachability({ networkFreshness: 'fresh', signals: { reachability: { dataReachable: true } } })).toBe('Data reachable');
  });
  it('requires fresh non-critical evidence before resolution', () => {
    expect(canResolve()).toBe(false);
    expect(canResolve({ dataFreshness: 'stale', risk_level: 'normal' })).toBe(false);
    expect(canResolve({ dataFreshness: 'fresh', risk_level: 'critical' })).toBe(false);
    expect(canResolve({ dataFreshness: 'fresh', risk_level: 'normal' })).toBe(true);
  });
});
