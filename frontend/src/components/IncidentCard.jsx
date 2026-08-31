import { useState } from 'react';
import { canResolve, time, words } from '../utils/format';
import { Status } from './Status';

export function IncidentCard({ incident, zone, responders, busy, onAction }) {
  const [reviewed, setReviewed] = useState(false);
  const [note, setNote] = useState('');
  const responder = responders.find((item) => item.id === incident.responder_id);
  const resolved = incident.status === 'resolved';
  return <article className="card">
    <div className="row"><h3>{zone?.name.replace('DEMO - ', '') || 'Unknown zone'}</h3><Status value={incident.status} tone={resolved ? 'normal' : 'warning'} /></div>
    <p className="small">Created {time(incident.created_at)} · crowd source: {incident.source}</p>
    <p>Responder: <strong>{responder?.name || 'Not selected'}</strong></p>
    {incident.decision && <p className="small">{words(incident.decision.reason)}</p>}
    {incident.acknowledgementOverdue && <p className="inline-error">No acknowledgement after 60 seconds. Follow-up is required.</p>}
    {!resolved && <div className="incident-actions">
      {['detected', 'awaiting_approval'].includes(incident.status) &&
        <button disabled={busy} onClick={() => onAction('recommend')}>Recheck recommendation</button>}
      {incident.status === 'awaiting_approval' && <>
        <label className="check"><input type="checkbox" checked={reviewed} onChange={(event) => setReviewed(event.target.checked)} /> I reviewed the simulated route and assignment.</label>
        <button className="primary" disabled={busy || !reviewed} onClick={() => onAction('approve', { routeReviewed: true })}>Approve assignment</button>
      </>}
      {incident.status === 'dispatched' && <>
        <p className="small">This test records acknowledgement here. No real message is sent.</p>
        <button disabled={busy} onClick={() => onAction('acknowledge')}>Record acknowledgement</button>
      </>}
      <label className="field">Resolution note<input value={note} maxLength={1000} placeholder="What did the operator confirm?" onChange={(event) => setNote(event.target.value)} /></label>
      <button disabled={busy || !canResolve(zone) || note.trim().length < 5} onClick={() => onAction('resolve', { note: note.trim() })}>Resolve incident</button>
      {!canResolve(zone) && <p className="small">Reduce the crowd first; resolution requires a fresh, non-critical reading.</p>}
    </div>}
    {incident.decision && <details><summary>Decision details</summary><pre>{JSON.stringify(incident.decision, null, 2)}</pre></details>}
  </article>;
}
