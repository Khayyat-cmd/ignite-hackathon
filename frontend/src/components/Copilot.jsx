import { useMemo, useState } from 'react';
import { apiRequest } from '../api/client';
import { useAction } from '../hooks/useAction';
import { Badge, Notice } from './Shared';

const STARTERS = ['What needs attention?', 'Why is this zone critical?', 'Who should respond and why?'];

export default function Copilot({ data, onReviewSuggestion }) {
  const [messages, setMessages] = useState([]);
  const [question, setQuestion] = useState('');
  const action = useAction();
  const proactive = useMemo(() => {
    const incident = (data.incidents || []).find((item) => item.active_zone_id && item.decision?.advice?.recommendedResponderId);
    if (!incident) return null;
    const advice = incident.decision.advice;
    return {
      incidentId: incident.id,
      zoneId: incident.zone_id,
      responderId: advice.recommendedResponderId,
      reason: advice.proposedAction,
    };
  }, [data.incidents]);

  function ask(value = question) {
    const nextQuestion = value.trim();
    if (!nextQuestion) return;
    const history = messages.map(({ role, content }) => ({ role, content })).slice(-12);
    setQuestion('');
    setMessages((current) => [...current, { role: 'user', content: nextQuestion }]);
    action.run(async () => {
      const response = await apiRequest(`/simulations/${data.id}/copilot`, {
        method: 'POST',
        body: { question: nextQuestion, history },
      });
      setMessages((current) => [...current, {
        role: 'assistant',
        content: response.answer,
        suggestion: response.suggestion,
        followUpPrompt: response.followUpPrompt,
      }]);
    });
  }

  return <section className="copilot" aria-label="Situation assistant">
    <div className="copilot-head">
      <span className="label">Situation assistant</span>
      <span className="meta">Review before action</span>
    </div>
    {messages.length === 0 && <>
      <p className="copilot-intro">Ask about live crowd conditions, incidents, uncertainty, or which responder should be reviewed.</p>
      {proactive && <div className="copilot-suggestion">
        <strong>Dispatch review suggested</strong>
        <p>{proactive.reason}</p>
        <button type="button" className="btn" onClick={() => onReviewSuggestion(proactive)}>Review dispatch</button>
      </div>}
      <div className="copilot-starters">
        {STARTERS.map((starter) => <button type="button" key={starter} disabled={action.busy} onClick={() => ask(starter)}>{starter}</button>)}
      </div>
    </>}
    {messages.length > 0 && <div className="copilot-messages" aria-live="polite">
      {messages.map((message, index) => <article className={`copilot-message ${message.role}`} key={`${message.role}-${index}`}>
        <small>{message.role === 'user' ? 'You' : 'Assistant'}</small>
        <p>{message.content}</p>
        {message.suggestion && <div className="copilot-suggestion compact">
          <strong>Review before dispatch</strong>
          <p>{message.suggestion.reason}</p>
          <button type="button" className="btn" onClick={() => onReviewSuggestion(message.suggestion)}>Review dispatch proposal</button>
        </div>}
        {message.followUpPrompt && <button type="button" className="copilot-followup" onClick={() => ask(message.followUpPrompt)}>{message.followUpPrompt}</button>}
      </article>)}
      {action.busy && <p className="hint">Reviewing the latest situation…</p>}
    </div>}
    <form className="copilot-compose" onSubmit={(event) => { event.preventDefault(); ask(); }}>
      <input aria-label="Ask about the situation" value={question} maxLength={1000} placeholder="Ask about the current situation…" disabled={action.busy} onChange={(event) => setQuestion(event.target.value)} />
      <button type="submit" className="btn btn-primary" disabled={action.busy || question.trim().length < 2}>Ask</button>
    </form>
    <Notice error>{action.error}</Notice>
  </section>;
}
