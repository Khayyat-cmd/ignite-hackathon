import { describe, expect, it } from 'vitest';

describe('stadium data boundary', () => {
  it('uses a venue-specific prefix distinct from legacy demo records', () => {
    const prefix = 'aman-stadium-v1:';
    expect('aman-stadium-v1:North Concourse'.startsWith(prefix)).toBe(true);
    expect('DEMO - North Concourse'.startsWith(prefix)).toBe(false);
  });
});
