import { useCallback, useState } from 'react';
import { apiRequest } from './api/client';
import { usePolling } from './hooks/usePolling';
import { useAction } from './hooks/useAction';
import OperatorPanel from './components/OperatorPanel';
import { Badge, Notice, time } from './components/Shared';

function EventView({ id, onRunChanged }) {
  const load = useCallback((signal) => apiRequest(`/simulations/${id}`, { signal }), [id]);
  const feed = usePolling(load);
  const action = useAction();
  function control(value) {
    if (['reset', 'stop'].includes(value) && !window.confirm(value === 'reset'
      ? 'Archive this rehearsal and create a new paused event? Demo phone access will be revoked.'
      : 'Stop this rehearsal? Pending missions will end and demo phone access will be revoked.')) return;
    action.run(async () => {
      const result = await apiRequest(`/simulations/${id}/control`, { method: 'POST', body: { action: value } });
      if (result.id !== id) onRunChanged(result.id);
      else feed.refresh();
    });
  }
  if (!feed.data) return <Notice error={Boolean(feed.error)}>{feed.error || 'Loading event…'}</Notice>;
  const data = feed.data;
  return <>
    <div className="monitor-bar"><span><i className={`dot ${data.stale || feed.error ? 'warning' : data.status === 'running' ? 'normal' : 'unknown'}`} />{feed.error ? 'Connection interrupted' : data.stale ? 'Data outdated' : data.status === 'running' ? 'Live' : data.status === 'paused' ? 'Paused' : 'Stopped'}</span><span>Stadium rehearsal · Event {data.id}</span><span className="muted">{data.zones.length} monitored zones</span></div>
    <section className="demo-controls"><section className="event-controls">
      <div><p className="eyebrow">Simulation control</p><h2>{data.phase.replaceAll('_', ' ')}</h2>
        <p><Badge value={data.status} /> {data.elapsedSeconds}s elapsed · Last sample {time(data.observedAt)} · Revision {data.revision}</p></div>
      <div className="action-row">
        <button className="primary" disabled={action.busy || data.status !== 'paused'} onClick={() => control('start')}>{data.elapsedSeconds ? 'Resume' : 'Start simulation'}</button>
        <button disabled={action.busy || data.status !== 'running'} onClick={() => control('pause')}>Pause</button>
        <button disabled={action.busy || data.status === 'stopped'} onClick={() => control('stop')}>Stop</button>
        <button disabled={action.busy} onClick={() => control('reset')}>Reset</button>
      </div>
    </section></section>
    <Notice error>{feed.error || action.error}</Notice>
    {data.stale && <Notice>Readings are stale. If the event is running, check that Laravel’s scheduler is running. Displayed counts are the last received sample.</Notice>}
    <OperatorPanel data={feed.error ? { ...data, stale: true } : data} refresh={feed.refresh} />
  </>;
}

function Workspace() {
  const load = useCallback((signal) => apiRequest('/simulations', { signal }), []);
  const list = usePolling(load, { interval: 15000 });
  const [selected, setSelected] = useState(null);
  const [attendees, setAttendees] = useState(6000);
  const action = useAction();
  const runs = list.data?.data?.data || [];
  const id = selected ?? runs.find((run) => run.status !== 'stopped')?.id ?? runs[0]?.id;
  function changeRun(next) { setSelected(next); list.refresh(); }
  function create() {
    action.run(async () => {
      const run = await apiRequest('/simulations', { method: 'POST', body: { attendeeCount: Number(attendees) } });
      changeRun(run.id);
    });
  }
  return <main className="workspace">
    <div className="app-chrome"><span className="brand"><span className="brand-mark">A</span> AMAN <span className="brand-divider">/</span><small>OPERATIONS CONSOLE</small></span><span className="chrome-label">Control room · Desktop</span></div>
    <header><div><p className="eyebrow">Stadium rehearsal</p><h1>Event operations</h1><p className="header-subtitle">Crowd conditions, active incidents, and field response.</p></div>
    </header>
    <p className="demo-label"><span className="test-label">REHEARSAL DATA</span> Attendee positions are simulated. Responder reachability uses Nokia sandbox devices.</p>
    <Notice error>{list.error || action.error}</Notice>
    {list.loading && !list.data && <p>Loading events…</p>}
    <section className="setup"><h2>Event selection</h2>
      {runs.length > 0 && <label><span>Rehearsal</span><select value={id || ''} onChange={(e) => changeRun(Number(e.target.value))}>{runs.map((run) => <option key={run.id} value={run.id}>Event #{run.id} · {run.status} · {run.attendee_count} attendees</option>)}</select></label>}
      {!list.loading && !runs.some((run) => run.status !== 'stopped') && <form onSubmit={(e) => { e.preventDefault(); create(); }}>
        <label><span>Fictional attendees (6,000 recommended)</span><input type="number" min="100" max="10000" step="1" value={attendees} onChange={(e) => setAttendees(e.target.value)} required /></label>
        <button className="primary" disabled={action.busy}>{action.busy ? 'Creating…' : 'Create rehearsal'}</button>
      </form>}
      <p className="muted">Start → dispatch → acknowledge → arrive → start managing the crowd → watch dispersal → resolve.</p>
    </section>
    {id && <EventView key={id} id={id} onRunChanged={changeRun} />}
    <footer>AMAN Operations Console<span>Hackathon rehearsal environment</span></footer>
  </main>;
}

export default function App() {
  return <Workspace />;
}
