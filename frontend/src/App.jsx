import { useCallback, useState } from 'react';
import { apiRequest } from './api/client';
import { usePolling } from './hooks/usePolling';
import { useAction } from './hooks/useAction';
import { useI18n, LanguageToggle } from './i18n';
import OperatorPanel from './components/OperatorPanel';
import { Mark, Notice, clock } from './components/Shared';

const STEPS = ['start', 'dispatch', 'acknowledge', 'arrive', 'manage', 'disperse', 'resolve'];
const RECOMMENDED_ATTENDEES = 6000;

function TopBar({ session, run }) {
  const { t, n, term } = useI18n();
  const data = run?.data;
  const live = data?.status === 'running' && !data.stale && !run.error;
  const warn = Boolean(data && (data.stale || run.error));
  const label = run?.error
    ? t('state.noLink')
    : data?.stale
      ? t('state.stale')
      : data?.status === 'running'
        ? t('state.live')
        : data?.status === 'paused'
          ? t('state.paused')
          : t('state.stopped');
  const phase = data?.phase ? t(`phase.${data.phase}`) : '';
  return <header className="topbar">
    <div className="brand"><Mark /><span className="brand-name">AMAN</span><span className="brand-sub">{t('topbar.brandSub')}</span></div>
    <span className="topbar-rule" />
    {session.runs.length > 0 && <label className="topbar-select">
      <select aria-label={t('topbar.eventSelect')} value={session.id || ''} onChange={(event) => session.onSelect(Number(event.target.value))}>
        {session.runs.map((run) => <option key={run.id} value={run.id}>
          {t('topbar.eventOption', { id: run.id, status: term(run.status), count: n(run.attendee_count) })}
        </option>)}
      </select>
    </label>}
    <button type="button" className="btn btn-quiet" disabled={session.hasActive || session.creating} onClick={session.onNew}>{t('topbar.newRehearsal')}</button>
    <span className="topbar-spacer" />
    {data && <>
      <div className="run-state">
        <span className={`state-pill${live ? ' state-live' : warn ? ' state-warn' : ''}`}>
          <i className={`dot ${live ? 'live' : warn ? 'warning' : ''}`} />{label}
        </span>
        <span className="run-clock" dir="ltr">{clock(data.elapsedSeconds)}</span>
        <span className="run-phase">{phase}</span>
      </div>
      <span className="topbar-rule" />
      <div className="topbar-actions">
        <button type="button" className="btn btn-primary" disabled={run.busy || data.status !== 'paused'} onClick={() => run.control('start')}>{data.elapsedSeconds ? t('topbar.resume') : t('topbar.start')}</button>
        <button type="button" className="btn" disabled={run.busy || data.status !== 'running'} onClick={() => run.control('pause')}>{t('topbar.pause')}</button>
        <button type="button" className="btn" disabled={run.busy || data.status === 'stopped'} onClick={() => run.control('stop')}>{t('topbar.stop')}</button>
        <button type="button" className="btn btn-quiet" disabled={run.busy} onClick={() => run.control('reset')}>{t('topbar.reset')}</button>
      </div>
    </>}
    <span className="topbar-rule" />
    <LanguageToggle />
  </header>;
}

function StatusBar({ data, error }) {
  const { t, n, time } = useI18n();
  return <div className="statusbar">
    <span className="tag">{t('status.rehearsalTag')}</span>
    <span>{t('status.simulatedNote')}</span>
    <span className="statusbar-end">
      <span>{t('status.sample')} <b>{time(data?.observedAt)}</b></span>
      <span>{t('status.revision')} <b>{data?.revision == null ? '—' : n(data.revision)}</b></span>
      <span>{t('status.backend')} <b>{error ? t('status.unreachable') : t('status.connected')}</b></span>
    </span>
  </div>;
}

function NewRunBar({ session }) {
  const { t, n } = useI18n();
  return <form className="newbar" onSubmit={(event) => { event.preventDefault(); session.onCreate(); }}>
    <span className="label">{t('newrun.title')}</span>
    <label className="newbar-field">
      <span>{t('newrun.attendees')}</span>
      <input type="number" min="100" max="10000" step="1" value={session.attendees} onChange={(event) => session.setAttendees(event.target.value)} required />
    </label>
    <span className="hint">{t('newrun.recommended', { count: n(RECOMMENDED_ATTENDEES) })}</span>
    <button className="btn btn-primary" disabled={session.busy}>{session.busy ? t('newrun.creating') : t('newrun.createEvent')}</button>
    <button type="button" className="btn btn-quiet" onClick={session.onCancel}>{t('common.cancel')}</button>
  </form>;
}

function SetupScreen({ session, loading }) {
  const { t } = useI18n();
  if (loading) return <div className="setup"><p className="hint">{t('setup.loadingEvents')}</p></div>;
  return <div className="setup">
    <form className="setup-card" onSubmit={(event) => { event.preventDefault(); session.onCreate(); }}>
      <h2>{t('setup.title')}</h2>
      <p>{t('setup.blurb')}</p>
      <label className="field">
        <span>{t('newrun.attendees')}</span>
        <input type="number" min="100" max="10000" step="1" value={session.attendees} onChange={(event) => session.setAttendees(event.target.value)} required />
      </label>
      <button className="btn btn-primary btn-lg btn-block" disabled={session.busy}>{session.busy ? t('newrun.creating') : t('setup.create')}</button>
      <div className="setup-steps">{STEPS.map((step) => <span key={step}>{t(`setup.step.${step}`)}</span>)}</div>
      <Notice error>{session.error}</Notice>
    </form>
  </div>;
}

function EventShell({ id, session }) {
  const { t } = useI18n();
  const load = useCallback((signal) => apiRequest(`/simulations/${id}`, { signal }), [id]);
  const feed = usePolling(load);
  const action = useAction();
  const data = feed.data;

  function control(value) {
    const confirmations = { reset: t('confirm.reset'), stop: t('confirm.stop') };
    if (confirmations[value] && !window.confirm(confirmations[value])) return;
    action.run(async () => {
      const result = await apiRequest(`/simulations/${id}/control`, { method: 'POST', body: { action: value } });
      if (result.id !== id) session.onSelect(result.id);
      else feed.refresh();
    });
  }

  const failure = feed.error || action.error || session.error;
  const warning = !failure && data?.stale ? t('warn.staleReadings') : '';

  return <>
    <TopBar session={session} run={data ? { data, error: feed.error, busy: action.busy, control } : null} />
    <StatusBar data={data} error={feed.error} />
    {session.creating && <NewRunBar session={session} />}
    {(failure || warning) && <div className={failure ? 'alertbar error' : 'alertbar'} role={failure ? 'alert' : 'status'}>
      <i className={`dot ${failure ? 'critical' : 'warning'}`} />{failure || warning}
    </div>}
    {data
      ? <OperatorPanel data={feed.error ? { ...data, stale: true } : data} refresh={feed.refresh} />
      : <div className="setup"><p className="hint">{feed.error || t('event.loading')}</p></div>}
  </>;
}

export default function App() {
  const load = useCallback((signal) => apiRequest('/simulations', { signal }), []);
  const list = usePolling(load, { interval: 15000 });
  const [selected, setSelected] = useState(null);
  const [attendees, setAttendees] = useState(RECOMMENDED_ATTENDEES);
  const [creating, setCreating] = useState(false);
  const action = useAction();
  const runs = list.data?.data?.data || [];
  const id = selected ?? runs.find((run) => run.status !== 'stopped')?.id ?? runs[0]?.id;

  function changeRun(next) { setSelected(next); setCreating(false); list.refresh(); }
  function create() {
    action.run(async () => {
      const run = await apiRequest('/simulations', { method: 'POST', body: { attendeeCount: Number(attendees) } });
      changeRun(run.id);
    });
  }

  const session = {
    runs, id, attendees, creating,
    hasActive: runs.some((run) => run.status !== 'stopped'),
    busy: action.busy,
    error: action.error || list.error,
    setAttendees,
    onSelect: changeRun,
    onNew: () => setCreating(true),
    onCancel: () => setCreating(false),
    onCreate: create,
  };

  return <div className="app">
    {id
      ? <EventShell key={id} id={id} session={session} />
      : <><TopBar session={session} /><StatusBar error={list.error} /><SetupScreen session={session} loading={list.loading && !list.data} /></>}
  </div>;
}
