import { useEffect, useState } from 'react';
import { apiRequest } from '../api/client';
import { useAction } from '../hooks/useAction';
import { Badge, Notice, time } from './Shared';
import Messages from './Messages';
import VenueMap from './VenueMap';

function AdviceCard({ decision, busy, retry }) {
  const advice = decision?.advice;
  const status = decision?.adviceStatus || 'disabled';
  if (status === 'pending') return <section className="advisor-card advisor-pending"><div className="advisor-heading"><strong>Response brief</strong><Badge value="analyzing" /></div><p>Assessing the incident and eligible responders…</p></section>;
  if (status === 'failed') return <section className="advisor-card advisor-failed"><div className="advisor-heading"><strong>Response brief</strong><Badge value="unavailable" /></div><p>{decision.adviceError}</p><button disabled={busy} onClick={retry}>Retry analysis</button></section>;
  if (!advice) return <section className="advisor-card"><div className="advisor-heading"><strong>Response brief</strong><Badge value="offline" /></div><p className="muted">Model analysis is unavailable. The verified responder ranking remains active.</p></section>;
  return <section className="advisor-card">
    <div className="advisor-heading"><div><p className="eyebrow">Decision support</p><strong>Response brief</strong></div><div><Badge value={advice.urgency} /> <Badge value={`${advice.confidence} confidence`} /></div></div>
    <p className="advisor-summary">{advice.summary}</p>
    <p><strong>Proposed action:</strong> {advice.proposedAction}</p>
    <ul>{advice.evidence.map((item) => <li key={item}>{item}</li>)}</ul>
    {advice.uncertainties.length > 0 && <details><summary>Uncertainty to review</summary><ul>{advice.uncertainties.map((item) => <li key={item}>{item}</li>)}</ul></details>}
    <small>Model: {advice.model} · Generated {time(advice.generatedAt)} · Operator approval required</small>
  </section>;
}

function IncidentActions({ incident, refresh, responders, zones, stale, stopped }) {
  const [reviewed, setReviewed] = useState(false);
  const advisedResponderId = incident.decision?.advice?.recommendedResponderId;
  const [selectedResponderId, setSelectedResponderId] = useState(advisedResponderId || incident.responder_id || '');
  const action = useAction();
  useEffect(() => setSelectedResponderId(advisedResponderId || incident.responder_id || ''), [advisedResponderId, incident.responder_id]);
  const selected = responders.find((r) => r.id === (incident.assigned_responder_id || selectedResponderId || incident.responder_id));
  const zone = zones.find((item) => item.id === incident.zone_id);
  const resolution = zone?.resolution;
  const active = Boolean(incident.active_zone_id) && !stopped;
  const dispatched = ['dispatched', 'acknowledged'].includes(incident.status);
  function mutate(path, body) {
    action.run(async () => { await apiRequest(`/incidents/${incident.id}/${path}`, { method: 'POST', body }); refresh(); });
  }
  return <div>
    <AdviceCard decision={incident.decision} busy={action.busy} retry={() => mutate('advice')} />
    <p>Recommended responder: <strong>{selected?.name || 'No eligible responder'}</strong></p>
    {dispatched && <p className="arrival-status">{incident.status === 'dispatched' ? 'Waiting for acknowledgment' : !stale && incident.decision?.arrivalVerification?.verificationResult === 'TRUE' ? 'Arrived at the assigned area' : 'Tracking responder — arrival not yet verified'}</p>}
    {incident.decision?.workStartedAt && <p>Responder is managing the crowd.</p>}
    {incident.decision && <details><summary>Why this responder?</summary><pre>{JSON.stringify(incident.decision, null, 2)}</pre></details>}
    <Notice error>{action.error}</Notice>
    {active && ['detected', 'awaiting_approval'].includes(incident.status) && <div className="action-row">
      <button disabled={action.busy || stale} onClick={() => mutate('recommend')}>Refresh recommendation</button>
      {incident.responder_id && <><label><span>Operator selection</span><select value={selectedResponderId} onChange={(event) => { setSelectedResponderId(event.target.value); setReviewed(false); }}>
        {(incident.decision?.candidates || []).map((candidate) => { const responder = responders.find((item) => item.id === candidate.responderId); return <option key={candidate.responderId} value={candidate.responderId}>{responder?.name || candidate.responderId} · {candidate.distanceMeters} m</option>; })}
      </select></label><label className="checkbox"><input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />I reviewed the evidence and route</label>
        <button className="primary" disabled={action.busy || !reviewed || stale || !selectedResponderId} onClick={() => mutate('approve', { routeReviewed: true, responderId: selectedResponderId })}>{selectedResponderId === advisedResponderId ? 'Accept advice & dispatch' : 'Dispatch selected responder'}</button></>}
    </div>}
    {active && incident.status === 'acknowledged' && <div className="resolution-action">
      {zone?.risk_level === 'critical' && <p className="muted">This zone is still Critical. The 15-second confirmation starts once its reading becomes Normal or Warning.</p>}
      {zone?.risk_level === 'unknown' && <p className="muted">Location evidence is uncertain. Wait for a live Normal or Warning reading before resolving.</p>}
      {!['critical', 'unknown'].includes(zone?.risk_level) && <p className="muted">Stable safe readings: {Math.min(resolution?.stableForSeconds || 0, resolution?.requiredStableSeconds || 15)} / {resolution?.requiredStableSeconds || 15} seconds.</p>}
      <button disabled={action.busy || stale || !resolution?.ready} onClick={() => mutate('resolve', {})}>{resolution?.ready ? 'Resolve incident' : 'Waiting for safe readings…'}</button>
    </div>}
    {dispatched && <Messages incidentId={incident.id} active={active} />}
    {incident.status === 'resolved' && <Messages incidentId={incident.id} active={false} />}
  </div>;
}

export default function OperatorPanel({ data, refresh }) {
  const [selectedId, setSelectedId] = useState('');
  const [zoneId, setZoneId] = useState(null);
  const incidents = data.incidents || [];
  const selected = incidents.find((i) => i.id === selectedId) || incidents.find((i) => i.active_zone_id) || incidents[0];
  const zoneName = (id) => data.zones.find((z) => z.id === id)?.name || 'Unknown zone';
  const quality = data.quality || {};
  const activeIncidents = incidents.filter((i) => i.active_zone_id && i.status !== 'resolved');
  const activeZone = data.zones.find((z) => z.id === zoneId);
  const visibleIncidents = activeZone ? incidents.filter((i) => i.zone_id === zoneId) : incidents;
  const focused = activeZone ? visibleIncidents.find((i) => i.id === selectedId) || visibleIncidents.find((i) => i.active_zone_id) || visibleIncidents[0] : selected;
  return <>
    <section className="stats" aria-label="Event summary">
      <article><small>Located attendees</small><strong>{(quality.located || 0).toLocaleString()}</strong><small>of {data.attendeeCount.toLocaleString()} simulated attendees</small></article>
      <article className={activeIncidents.length ? 'stat-alert' : ''}><small>Active incidents</small><strong>{activeIncidents.length.toString().padStart(2, '0')}</strong><small>{activeIncidents.filter((i) => i.status === 'awaiting_approval').length} awaiting dispatch approval</small></article>
      <article><small>Available responders</small><strong>{data.responders.filter((r) => r.available).length}<em> / {data.responders.length}</em></strong><small>{data.responders.filter((r) => r.signals?.reachability?.dataReachable).length} data reachable</small></article>
      <article><small>Uncertain locations</small><strong>{((quality.ambiguous || 0) + (quality.stale || 0) + (quality.missing || 0)).toLocaleString()}</strong><small>{(quality.outside || 0).toLocaleString()} confirmed outside monitored zones</small></article>
    </section>
    <div className="operations-grid"><div>
    <VenueMap zones={data.zones} stale={data.stale} selectedId={zoneId} onSelect={(id) => setZoneId(zoneId === id ? null : id)} />
    <div className="section-heading"><h2>Zone conditions</h2><span className="muted">{data.zones.length} monitored areas</span></div>
    <section className="zone-grid">{data.zones.map((zone) => <article className={`panel zone-card risk-${data.stale ? 'unknown' : zone.risk_level}`} key={zone.id}>
      <div className="section-heading"><h2>{zone.name}</h2><Badge value={data.stale ? 'stale' : zone.risk_level} /></div>
      <strong className="big-number">{zone.latest_reading?.deviceCount?.toLocaleString() ?? '—'}</strong><span className="muted"> people</span>
      <p className="density-line">{zone.latest_reading?.densityPerSquareMeter ?? '—'} <span className="muted">people / m²</span></p>
      <details><summary>Zone details</summary><small>Warning ≥ {zone.warning_density} · Critical ≥ {zone.critical_density} · Area {zone.area_sqm} m²<br />Counts assume one simulated device per person.</small></details>
    </article>)}</section>
    </div><section className="panel incident-panel"><div className="section-heading"><div><p className="eyebrow">Response coordination</p><h2>Active incidents</h2></div><span className="count-chip">{visibleIncidents.length}</span></div>
      {activeZone && <button className="filter-chip" onClick={() => setZoneId(null)}>{activeZone.name} · Clear filter ×</button>}
      {!visibleIncidents.length ? <div className="empty-state"><div className="empty-symbol">✓</div><h3>No incidents to review</h3><p>Monitoring continues. New incidents will appear here when a zone requires attention.</p></div> : <>
        <div className="incident-list">{visibleIncidents.map((incident) => <button className={focused?.id === incident.id ? 'selected' : ''} key={incident.id} onClick={() => setSelectedId(incident.id)}>
          {zoneName(incident.zone_id)} <Badge value={incident.status} />
        </button>)}</div>
        <h3>{zoneName(focused.zone_id)}</h3>
        <IncidentActions key={focused.id} incident={focused} refresh={refresh} responders={data.responders} zones={data.zones} stale={data.stale} stopped={data.status === 'stopped'} />
      </>}
    </section></div>
    <section><div className="section-heading"><div><p className="eyebrow">Ground operations</p><h2>Response team</h2></div><span className="muted">Availability & communication health</span></div><div className="responder-grid">{data.responders.map((responder, index) => <article className="panel responder-card" key={responder.id}>
      <div className="responder-avatar">R{String(index + 1).padStart(2, '0')}</div>
      <div className="section-heading"><h3>{responder.name}</h3><Badge value={responder.available ? 'available' : 'assigned'} /></div>
      <p>Mobile data: <strong>{responder.signals?.reachability?.status === 'unknown' ? 'Unknown' : responder.signals?.reachability?.dataReachable ? 'Reachable' : 'Not reachable'}</strong></p>
      <small>Nokia sandbox · {responder.signals?.reachability?.sandboxDevice} · Checked {time(responder.signals?.reachability?.checkedAt)}</small>
      <Notice error>{responder.signals?.reachability?.error}</Notice>
      <p>Congestion: <Badge value={responder.signals?.congestion?.[0]?.congestionLevel} /></p>
      <p className="position-line">Stadium position: <strong>x {responder.signals?.simulationPoint?.x?.toFixed?.(1) ?? '—'} · z {responder.signals?.simulationPoint?.y?.toFixed?.(1) ?? '—'}</strong></p>
      <small>{responder.signals?.communicationAdvice}</small>
      <details><summary>Connection details</summary><p className="muted">Network checked {time(responder.signals?.checkedAt)}</p><pre>{JSON.stringify(responder.signals?.rawResponses, null, 2)}</pre></details>
    </article>)}</div></section>
  </>;
}
