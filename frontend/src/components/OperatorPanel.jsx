import { useState } from 'react';
import { apiRequest } from '../api/client';
import { useAction } from '../hooks/useAction';
import { Badge, Notice, time } from './Shared';
import Messages from './Messages';
import VenueMap from './VenueMap';

function IncidentActions({ incident, refresh, openMobile, responders, zones, stale, stopped }) {
  const [reviewed, setReviewed] = useState(false);
  const action = useAction();
  const selected = responders.find((r) => r.id === (incident.assigned_responder_id || incident.responder_id));
  const zone = zones.find((item) => item.id === incident.zone_id);
  const resolution = zone?.resolution;
  const active = Boolean(incident.active_zone_id) && !stopped;
  const dispatched = ['dispatched', 'acknowledged'].includes(incident.status);
  function mutate(path, body) {
    action.run(async () => { await apiRequest(`/incidents/${incident.id}/${path}`, { method: 'POST', body }); refresh(); });
  }
  return <div>
    <p>Recommended responder: <strong>{selected?.name || 'No eligible responder'}</strong></p>
    {incident.decision && <details><summary>Why this responder?</summary><pre>{JSON.stringify(incident.decision, null, 2)}</pre></details>}
    <Notice error>{action.error}</Notice>
    {active && ['detected', 'awaiting_approval'].includes(incident.status) && <div className="action-row">
      <button disabled={action.busy || stale} onClick={() => mutate('recommend')}>Refresh recommendation</button>
      {incident.responder_id && <><label className="checkbox"><input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />I reviewed the route</label>
        <button className="primary" disabled={action.busy || !reviewed || stale} onClick={() => mutate('approve', { routeReviewed: true })}>Dispatch responder</button></>}
    </div>}
    {active && dispatched && <button onClick={() => openMobile(incident.assigned_responder_id)}>Open this responder’s phone</button>}
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

export default function OperatorPanel({ data, refresh, openMobile }) {
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
      <article><small>People in monitored zones</small><strong>{(quality.located || 0).toLocaleString()}</strong><small>{data.attendeeCount.toLocaleString()} simulated attendees total</small></article>
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
    </div><section className="panel incident-panel"><div className="section-heading"><div><p className="eyebrow">Response coordination</p><h2>Incident desk</h2></div><span className="count-chip">{visibleIncidents.length}</span></div>
      {activeZone && <button className="filter-chip" onClick={() => setZoneId(null)}>{activeZone.name} · Clear filter ×</button>}
      {!visibleIncidents.length ? <div className="empty-state"><div className="empty-symbol">✓</div><h3>No incidents to review</h3><p>Monitoring continues. New incidents will appear here when a zone requires attention.</p></div> : <>
        <div className="incident-list">{visibleIncidents.map((incident) => <button className={focused?.id === incident.id ? 'selected' : ''} key={incident.id} onClick={() => setSelectedId(incident.id)}>
          {zoneName(incident.zone_id)} <Badge value={incident.status} />
        </button>)}</div>
        <h3>{zoneName(focused.zone_id)}</h3>
        <IncidentActions key={focused.id} incident={focused} refresh={refresh} openMobile={openMobile} responders={data.responders} zones={data.zones} stale={data.stale} stopped={data.status === 'stopped'} />
      </>}
    </section></div>
    <section><div className="section-heading"><div><p className="eyebrow">Ground operations</p><h2>Response team</h2></div><span className="muted">Availability & communication health</span></div><div className="responder-grid">{data.responders.map((responder, index) => <article className="panel responder-card" key={responder.id}>
      <div className="responder-avatar">R{String(index + 1).padStart(2, '0')}</div>
      <div className="section-heading"><h3>{responder.name}</h3><Badge value={responder.available ? 'available' : 'assigned'} /></div>
      <p>Mobile data: <strong>{responder.signals?.reachability?.dataReachable ? 'Reachable' : 'Not reachable'}</strong></p>
      <p>Congestion: <Badge value={responder.signals?.congestion?.[0]?.congestionLevel} /></p>
      <p className="position-line">Stadium position: <strong>x {responder.signals?.simulationPoint?.x?.toFixed?.(1) ?? '—'} · z {responder.signals?.simulationPoint?.y?.toFixed?.(1) ?? '—'}</strong></p>
      <small>{responder.signals?.communicationAdvice}</small>
      <details><summary>Connection details</summary><p className="muted">Network checked {time(responder.signals?.checkedAt)}</p><pre>{JSON.stringify(responder.signals?.rawResponses, null, 2)}</pre></details>
    </article>)}</div></section>
  </>;
}
