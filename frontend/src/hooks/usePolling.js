import { useCallback, useEffect, useState } from 'react';

// Schedule the next read after completion: slow requests never overlap.
export function usePolling(load, { enabled = true, interval = 5000 } = {}) {
  const [state, setState] = useState({ data: null, error: '', loading: true });
  const [revision, setRevision] = useState(0);
  const refresh = useCallback(() => setRevision((value) => value + 1), []);
  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    let timer;
    const poll = async () => {
      try {
        const data = await load(controller.signal);
        if (!controller.signal.aborted) setState({ data, error: '', loading: false });
      } catch (error) {
        if (!controller.signal.aborted) setState((current) => ({ ...current, error: error.message, loading: false }));
      } finally {
        if (!controller.signal.aborted) timer = setTimeout(poll, interval);
      }
    };
    poll();
    return () => { controller.abort(); clearTimeout(timer); };
  }, [load, enabled, interval, revision]);
  return { ...state, refresh };
}
