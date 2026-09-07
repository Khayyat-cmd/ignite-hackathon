import { useMemo, useState } from 'react';
import { apiRequest } from '../api/client';
import { useAction } from '../hooks/useAction';
import { useI18n } from '../i18n';
import { Notice } from './Shared';

const STARTERS = ['copilot.starter.attention', 'copilot.starter.critical', 'copilot.starter.responder'];

export default function Copilot({ data, onReviewSuggestion }) {
  const { t, locale } = useI18n();
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

  return <section className="copilot" aria-label={t('copilot.aria')}>
    <div className="copilot-head">
      <span className="label">{t('copilot.label')}</span>
      <span className="meta">{t('copilot.meta')}</span>
    </div>
    {messages.length === 0 && <>
      <p className="copilot-intro">{t('copilot.intro')}</p>
      {/* The model is prompted in English and answers in English; say so rather
          than letting an Arabic operator read the reply as a failure. */}
      {locale !== 'en' && <p className="hint">{t('copilot.answerLanguageNote')}</p>}
      {proactive && <div className="copilot-suggestion">
        <strong>{t('copilot.suggestionTitle')}</strong>
        <p>{proactive.reason}</p>
        <button type="button" className="btn" onClick={() => onReviewSuggestion(proactive)}>{t('copilot.reviewDispatch')}</button>
      </div>}
      <div className="copilot-starters">
        {STARTERS.map((key) => <button type="button" key={key} disabled={action.busy} onClick={() => ask(t(key))}>{t(key)}</button>)}
      </div>
    </>}
    {messages.length > 0 && <div className="copilot-messages" aria-live="polite">
      {messages.map((message, index) => <article className={`copilot-message ${message.role}`} key={`${message.role}-${index}`}>
        <small>{message.role === 'user' ? t('copilot.you') : t('copilot.assistant')}</small>
        <p>{message.content}</p>
        {message.suggestion && <div className="copilot-suggestion compact">
          <strong>{t('copilot.reviewBeforeDispatch')}</strong>
          <p>{message.suggestion.reason}</p>
          <button type="button" className="btn" onClick={() => onReviewSuggestion(message.suggestion)}>{t('copilot.reviewProposal')}</button>
        </div>}
        {message.followUpPrompt && <button type="button" className="copilot-followup" onClick={() => ask(message.followUpPrompt)}>{message.followUpPrompt}</button>}
      </article>)}
      {action.busy && <p className="hint">{t('copilot.thinking')}</p>}
    </div>}
    <form className="copilot-compose" onSubmit={(event) => { event.preventDefault(); ask(); }}>
      <input aria-label={t('copilot.inputAria')} value={question} maxLength={1000} placeholder={t('copilot.placeholder')} disabled={action.busy} onChange={(event) => setQuestion(event.target.value)} />
      <button type="submit" className="btn btn-primary" disabled={action.busy || question.trim().length < 2}>{t('copilot.ask')}</button>
    </form>
    <Notice error>{action.error}</Notice>
  </section>;
}
