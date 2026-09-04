import { useRef, useState } from 'react';

export function useAction() {
  const pending = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function run(action) {
    if (pending.current) return;
    pending.current = true;
    setBusy(true);
    setError('');
    try { return await action(); }
    catch (error) { setError(error.message); }
    finally { pending.current = false; setBusy(false); }
  }
  return { busy, error, run };
}
