import { useCallback, useRef, useState } from 'react';
import { apiRequest } from '../api/client';
import { usePolling } from '../hooks/usePolling';
import { useAction } from '../hooks/useAction';
import { Notice, time } from './Shared';

export default function Messages({ incidentId, responderId, mobile = false, active = true, revision = 0 }) {
  const load = useCallback(async (signal) => {
    // Read all pages, including long conversations, using the backend's cursor.
    let after = 0;
    const messages = [];
    for (let page = 0; page < 20; page += 1) {
      const query = new URLSearchParams({ after: String(after), ...(responderId ? { responderId } : {}) });
      const result = await apiRequest(`/missions/${incidentId}/messages?${query}`, { signal });
      const rows = result.data || [];
      messages.push(...rows);
      if (rows.length < 100) return messages;
      after = rows.at(-1).id;
    }
    return messages;
  }, [incidentId, responderId, revision]);
  const feed = usePolling(load);
  const [body, setBody] = useState('');
  const [sent, setSent] = useState(false);
  const attempt = useRef(null);
  const action = useAction();
  function send(event) {
    event.preventDefault();
    action.run(async () => {
      const text = body.trim();
      if (!text) return;
      const kind = mobile ? 'message' : 'instruction';
      if (!attempt.current || attempt.current.body !== text) {
        attempt.current = { clientMessageId: crypto.randomUUID(), kind, body: text };
      }
      await apiRequest(`/missions/${incidentId}/messages`, { method: 'POST', body: { ...attempt.current, ...(responderId ? { responderId } : {}) } });
      attempt.current = null;
      setBody(''); setSent(true); feed.refresh();
    });
  }
  return <section className="conversation">
    <h3>{mobile ? 'Command center messages' : 'Instructions & field reports'}</h3>
    <Notice error>{feed.error}</Notice>
    <div className="messages" aria-live="polite">
      {!feed.data?.length && <p className="muted">No messages yet.</p>}
      {feed.data?.map((message) => <article className={message.kind === 'instruction' ? 'bubble instruction' : 'bubble'} key={message.id}>
        <small>{message.kind.replaceAll('_', ' ')} · {time(message.created_at)}</small><p>{message.body}</p>
      </article>)}
    </div>
    <form onSubmit={send}>
      <label><span>{mobile ? 'Send a reply' : 'Send an instruction'}</span>
        <textarea value={body} maxLength={1000} required disabled={!active} onChange={(e) => { setBody(e.target.value); setSent(false); }} placeholder={mobile ? 'Update the operator…' : 'Tell the responder what to do…'} /></label>
      <Notice error>{action.error}</Notice>
      {sent && <small role="status">Message saved. The other app will receive it on its next refresh.</small>}
      <button className="primary" disabled={action.busy || !active || !body.trim()}>{action.busy ? 'Sending…' : 'Send'}</button>
    </form>
  </section>;
}
