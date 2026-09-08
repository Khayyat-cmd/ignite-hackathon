const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1/demo').replace(/\/$/, '');

// The responder APK is published next to the API, so the desktop build links to
// the same host the console talks to rather than to a path it cannot serve.
export const APK_URL = new URL('/downloads/aman-responder.apk', API_URL).href;

export class ApiError extends Error {
  constructor(message, status = 0, retryAfter = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.retryAfter = retryAfter;
  }
}

export async function apiRequest(path, { method = 'GET', body, signal } = {}) {
  const controller = new AbortController();
  const stop = () => controller.abort();
  signal?.addEventListener('abort', stop, { once: true });
  if (signal?.aborted) controller.abort();
  const timeout = window.setTimeout(stop, 45_000);

  try {
    const response = await fetch(`${API_URL}${path}`, {
      method,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
      const validation = payload?.errors ? Object.values(payload.errors).flat().join(' ') : '';
      const fallback = {
        403: 'This action is not available in the current state.',
        409: 'The state changed or the evidence is stale. Refresh and try the correct next action.',
        422: 'The request is not valid for the current state.',
        429: 'Too many requests. Wait before retrying.',
        503: 'A required service is currently unavailable.',
      };
      const retryAfter = Number(response.headers.get('Retry-After'));
      throw new ApiError(validation || payload?.message || fallback[response.status] || 'Request failed.', response.status, retryAfter || null);
    }
    if (payload === null) throw new ApiError('The backend returned an unexpected response.', response.status);
    return payload;
  } catch (error) {
    if (signal?.aborted) throw error;
    if (error instanceof ApiError) throw error;
    if (controller.signal.aborted) throw new ApiError('The request timed out. Check the backend before retrying an action.');
    throw new ApiError('Cannot reach AMAN. Confirm the Laravel backend is running on localhost:8000.');
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener('abort', stop);
  }
}
