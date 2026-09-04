import { useCallback, useRef, useState } from 'react';
import { apiRequest } from '../api/client';
import { usePolling } from '../hooks/usePolling';
import { useAction } from '../hooks/useAction';
import { Badge, Notice } from './Shared';
import Messages from './Messages';

function Phone({ responderId, refreshOperator }) {
  const load = useCallback((signal) => apiRequest(`/missions?responderId=${responderId}`, { signal }), [responderId]);
  const feed = usePolling(load);
  const [selectedId, setSelectedId] = useState('');
  const [messageRevision, setMessageRevision] = useState(0);
  const [confirmation, setConfirmation] = useState('');
  const attempt = useRef(null);
  const action = useAction();
  const missions = feed.data?.data || [];
  const mission = missions.find((m) => m.id === selectedId) || missions[0];
  function report(kind) {
    action.run(async () => {
      if (kind === 'acknowledge') {
        await apiRequest(`/missions/${mission.id}/acknowledge`, { method: 'POST', body: { responderId } });
      } else {
        const messages = { en_route: 'On my way to the assigned zone.', on_scene: 'Started managing the crowd and safely redirecting attendees.' };
        if (!attempt.current || attempt.current.kind !== kind || attempt.current.missionId !== mission.id) {
          attempt.current = { missionId: mission.id, clientMessageId: crypto.randomUUID(), kind, body: messages[kind] };
        }
        const { missionId, ...body } = attempt.current;
        await apiRequest(`/missions/${missionId}/messages`, { method: 'POST', body: { ...body, responderId } });
        attempt.current = null;
      }
      const recoverySeconds = mission.destination.name === 'South Concourse' ? 55 : 80;
      setConfirmation(kind === 'on_scene' ? `Work started. Simulated redirection has begun; ${mission.destination.name} clears gradually over about ${recoverySeconds} seconds.` : 'Update sent to the command center.');
      feed.refresh(); refreshOperator(); setMessageRevision((r) => r + 1);
    });
  }
  return <div className="phone"><div className="phone-bar">AMAN · responder</div>
    <Notice error>{feed.error}</Notice>
    <Notice error>{action.error}</Notice>
    {feed.loading && <p>Loading assigned missions…</p>}
    {!feed.loading && !mission && <p className="empty-phone">No active missions. Waiting for the operator to dispatch you. Resolved missions disappear from this list.</p>}
    {missions.length > 1 && <label><span>Mission</span><select value={mission.id} onChange={(e) => { setSelectedId(e.target.value); setConfirmation(''); }}>{missions.map((m) => <option key={m.id} value={m.id}>{m.destination.name}</option>)}</select></label>}
    {mission && <section key={mission.id}>
      <Badge value={mission.status} /><h2>{mission.destination.name}</h2><p>Proceed to this zone and follow the operator’s instructions.</p>
      <div className="phone-actions">
        <button className="primary" disabled={action.busy || mission.status !== 'dispatched'} onClick={() => report('acknowledge')}>Acknowledge mission</button>
        <button disabled={action.busy || mission.status !== 'acknowledged'} onClick={() => report('en_route')}>I’m on my way</button>
        <button disabled={action.busy || Boolean(feed.error) || mission.status !== 'acknowledged' || mission.arrivalVerification?.verificationResult !== 'TRUE' || Boolean(mission.workStartedAt)} onClick={() => report('on_scene')}>{mission.workStartedAt ? 'Managing the crowd' : 'Start managing the crowd'}</button>
      </div>
      <Notice>{confirmation}</Notice>
      <p className="arrival-status" role="status">{mission.status === 'dispatched' ? 'Acknowledge the mission to begin.' : !feed.error && mission.arrivalVerification?.verificationResult === 'TRUE' ? 'Arrived at the assigned area.' : 'Tracking your location — arrival not yet verified.'}</p>
      <Messages key={mission.id} incidentId={mission.id} responderId={responderId} mobile revision={messageRevision} />
    </section>}
  </div>;
}

export default function ResponderPanel({ data, requestedId, connection, setConnection, refreshOperator }) {
  const [selectedId, setSelectedId] = useState(connection?.responderId || requestedId || data.responders[0]?.id || '');
  function connect() {
    setConnection({ responderId: selectedId });
  }
  return <div className="mobile-layout"><section className="panel">
    <h2>Responder mobile test</h2><p>Select a simulated responder to view only their assigned missions.</p>
    <label><span>Simulate this responder</span><select value={selectedId} onChange={(e) => { setSelectedId(e.target.value); setConnection(null); }}>
      {data.responders.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
    </select></label>
    <button className="primary" disabled={!selectedId || data.status === 'stopped'} onClick={connect}>Open demo phone</button>
    <p className="muted">No real calls, SMS, or push notifications. Messages refresh every five seconds while this tab is open.</p>
  </section>{connection ? <Phone key={connection.responderId} responderId={connection.responderId} refreshOperator={refreshOperator} /> : <div className="phone"><p className="empty-phone">Choose a responder and open their demo phone.</p></div>}</div>;
}
