import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from './client';

beforeEach(() => vi.stubGlobal('window', { setTimeout, clearTimeout }));
afterEach(() => vi.unstubAllGlobals());

describe('apiRequest', () => {
  it('sends authorization and parses a successful response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: true })));
    vi.stubGlobal('fetch', fetchMock);
    await expect(apiRequest('secret', '/zones')).resolves.toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledWith('http://localhost:8000/api/v1/zones', expect.objectContaining({
      headers: expect.objectContaining({ Authorization: 'Bearer secret' }),
    }));
  });
  it('does not retry a failed mutation and exposes validation details', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ errors: { note: ['Note is required.'] } }), { status: 422 }));
    vi.stubGlobal('fetch', fetchMock);
    await expect(apiRequest('secret', '/incidents/id/resolve', { method: 'POST', body: {} })).rejects.toThrow('Note is required.');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
  it('handles unreachable servers and malformed responses', async () => {
    const fetchMock = vi.fn().mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValueOnce(new Response('<html>error</html>'));
    vi.stubGlobal('fetch', fetchMock);
    await expect(apiRequest('secret', '/zones')).rejects.toThrow('Cannot reach Laravel');
    await expect(apiRequest('secret', '/zones')).rejects.toThrow('unexpected response');
  });
});
