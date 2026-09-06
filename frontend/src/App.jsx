import { useCallback, useState } from 'react';
import { apiRequest } from './api/client';
import { usePolling } from './hooks/usePolling';
import { useAction } from './hooks/useAction';
import OperatorPanel from './components/OperatorPanel';
import { Mark, Notice, clock, time } from './components/Shared';

const STEPS = ['Start', 'Dispatch', 'Acknowledge', 'Arrive', 'Manage crowd', 'Disperse', 'Resolve'];

function TopBar({ session, run }) {
  const data = run?.data;
  const live = data?.status === 'running' && !data.stale && !run.error;
  const warn = Boolean(data && (data.stale || run.error));
  const label = run?.error ? 'No link' : data?.stale ? 'Stale' : data?.status === 'running' ? 'Live' : data?.status === 'paused' ? 'Paused' : 'Stopped';
  return <header className="topbar">
    <div className="brand"><Mark /><span className="brand-name">AMAN</span><span className="brand-sub">Operations console</span></div>
    <span className="topbar-rule" />
    {session.runs.length > 0 && <label className="topbar-select">
      <select aria-label="Rehearsal event" value={session.id || ''} onChange={(event) => session.onSelect(Number(event.target.value))}>
        {session.runs.map((run) => <option key={run.id} value={run.id}>Event #{run.id} · {run.status} · {run.attendee_count?.toLocaleString()} attendees</option>)}
      </select>
    </label>}
    <button type="button" className="btn btn-quiet" disabled={session.hasActive || session.creating} onClick={session.onNew}>New rehearsal</button>
    <span className="topbar-spacer" />
    {data && <>
      <div className="run-state">
        <span className={`state-pill${live ? ' state-live' : warn ? ' state-warn' : ''}`}>
          <i className={`dot ${live ? 'live' : warn ? 'warning' : ''}`} />{label}
        </span>
        <span className="run-clock">{clock(data.elapsedSeconds)}</span>
        <span className="run-phase">{(data.phase || '').replaceAll('_', ' ')}</span>
      </div>
      <span className="topbar-rule" />
      <div className="topbar-actions">
        <button type="button" className="btn btn-primary" disabled={run.busy || data.status !== 'paused'} onClick={() => run.control('start')}>{data.elapsedSeconds ? 'Resume' : 'Start'}</button>
        <button type="button" className="btn" disabled={run.busy || data.status !== 'running'} onClick={() => run.control('pause')}>Pause</button>
        <button type="button" className="btn" disabled={run.busy || data.status === 'stopped'} onClick={() => run.control('stop')}>Stop</button>
        <button type="button" className="btn btn-quiet" disabled={run.busy} onClick={() => run.control('reset')}>Reset</button>
      </div>
    </>}
  </header>;
}

function StatusBar({ data, error }) {
  return <div className="statusbar">
    <span className="tag">Rehearsal data</span>
    <span>Attendee positions are simulated. Responder reachability uses Nokia sandbox devices.</span>
    <span className="statusbar-end">
      <span>Sample <b>{time(data?.observedAt)}</b></span>
      <span>Revision <b>{data?.revision ?? '—'}</b></span>
      <span>Backend <b>{error ? 'unreachable' : 'connected'}</b></span>
    </span>
  </div>;
}

function NewRunBar({ session }) {
  return <form className="newbar" onSubmit={(event) => { event.preventDefault(); session.onCreate(); }}>
    <span className="label">New rehearsal</span>
    <label className="newbar-field">
      <span>Fictional attendees</span>
      <input type="number" min="100" max="10000" step="1" value={session.attendees} onChange={(event) => session.setAttendees(event.target.value)} required />
    </label>
    <span className="hint">6,000 recommended</span>
    <button className="btn btn-primary" disabled={session.busy}>{session.busy ? 'Creating…' : 'Create event'}</button>
    <button type="button" className="btn btn-quiet" onClick={session.onCancel}>Cancel</button>
  </form>;
}

function SetupScreen({ session, loading }) {
  if (loading) return <div className="setup"><p className="hint">Loading events…</p></div>;
  return <div className="setup">
    <form className="setup-card" onSubmit={(event) => { event.preventDefault(); session.onCreate(); }}>
      <h2>Create a rehearsal event</h2>
      <p>Simulated attendee positions drive zone density. Responder reachability comes from Nokia sandbox devices.</p>
      <label className="field">
        <span>Fictional attendees</span>
        <input type="number" min="100" max="10000" step="1" value={session.attendees} onChange={(event) => session.setAttendees(event.target.value)} required />
      </label>
      <button className="btn btn-primary btn-lg btn-block" disabled={session.busy}>{session.busy ? 'Creating…' : 'Create rehearsal'}</button>
      <div className="setup-steps">{STEPS.map((step) => <span key={step}>{step}</span>)}</div>
      <Notice error>{session.error}</Notice>
    </form>
  </div>;
}

function EventShell({ id, session }) {
  const load = useCallback((signal) => apiRequest(`/simulations/${id}`, { signal }), [id]);
  const feed = usePolling(load);
  const action = useAction();
  const data = feed.data;

  function control(value) {
    const confirmations = {
      reset: 'Archive this rehearsal and create a new paused event? Demo phone access will be revoked.',
      stop: 'Stop this rehearsal? Pending missions will end and demo phone access will be revoked.',
    };
    if (confirmations[value] && !window.confirm(confirmations[value])) return;
    action.run(async () => {
      const result = await apiRequest(`/simulations/${id}/control`, { method: 'POST', body: { action: value } });
      if (result.id !== id) session.onSelect(result.id);
      else feed.refresh();
    });
  }

  const failure = feed.error || action.error || session.error;
  const warning = !failure && data?.stale ? 'Readings are stale. If the event is running, check that Laravel’s scheduler is running. Displayed counts are the last received sample.' : '';

  return <>
    <TopBar session={session} run={data ? { data, error: feed.error, busy: action.busy, control } : null} />
    <StatusBar data={data} error={feed.error} />
    {session.creating && <NewRunBar session={session} />}
    {(failure || warning) && <div className={failure ? 'alertbar error' : 'alertbar'} role={failure ? 'alert' : 'status'}>
      <i className={`dot ${failure ? 'critical' : 'warning'}`} />{failure || warning}
    </div>}
    {data
      ? <OperatorPanel data={feed.error ? { ...data, stale: true } : data} refresh={feed.refresh} />
      : <div className="setup"><p className="hint">{feed.error || 'Loading event…'}</p></div>}
  </>;
}

export default function App() {
  const load = useCallback((signal) => apiRequest('/simulations', { signal }), []);
  const list = usePolling(load, { interval: 15000 });
  const [selected, setSelected] = useState(null);
  const [attendees, setAttendees] = useState(6000);
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
