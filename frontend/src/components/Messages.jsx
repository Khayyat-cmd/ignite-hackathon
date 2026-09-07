import { useCallback, useRef, useState } from 'react';
import { apiRequest } from '../api/client';
import { usePolling } from '../hooks/usePolling';
import { useAction } from '../hooks/useAction';
import { useI18n } from '../i18n';
import { Notice } from './Shared';

export default function Messages({ incidentId, responderId, mobile = false, active = true, revision = 0 }) {
  const { t, n, kind, time } = useI18n();
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
  return <section className="block conversation">
    <span className="label">{mobile ? t('messages.mobileLabel') : t('messages.controlLabel')}</span>
    <Notice error>{feed.error}</Notice>
    <div className="messages" aria-live="polite">
      {!feed.data?.length && <p className="hint">{t('messages.empty')}</p>}
      {feed.data?.map((message) => <article className={message.kind === 'instruction' ? 'bubble instruction' : 'bubble'} key={message.id}>
        <small>{kind(message.kind)} · {time(message.created_at)}</small>
        <p>{message.body}</p>
      </article>)}
    </div>
    <form className="compose" onSubmit={send}>
      <textarea
        value={body}
        maxLength={1000}
        required
        disabled={!active}
        aria-label={mobile ? t('messages.replyAria') : t('messages.instructionAria')}
        onChange={(e) => { setBody(e.target.value); setSent(false); }}
        placeholder={mobile ? t('messages.replyPlaceholder') : t('messages.instructionPlaceholder')}
      />
      <Notice error>{action.error}</Notice>
      <div className="compose-foot">
        <small className="hint" role="status">{sent ? t('messages.saved') : t('messages.counter', { count: n(body.length) })}</small>
        <button className="btn btn-primary" disabled={action.busy || !active || !body.trim()}>{action.busy ? t('messages.sending') : t('messages.send')}</button>
      </div>
    </form>
  </section>;
}
