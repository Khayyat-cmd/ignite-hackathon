import { useEffect, useState } from 'react';
import { apiRequest } from '../api/client';
import { useAction } from '../hooks/useAction';
import { Badge, Notice, riskClass, time } from './Shared';
import Copilot from './Copilot';
import Messages from './Messages';
import VenueMap from './VenueMap';

function Metric({ label, value, sub, alert = false }) {
  return <div className={alert ? 'metric alert' : 'metric'}>
    <span className="metric-label">{label}</span>
    <span className="metric-value">{value}</span>
    <span className="metric-sub">{sub}</span>
  </div>;
}

function ZoneRow({ zone, stale, selected, onSelect }) {
  const reading = zone.latest_reading;
  const density = Number(reading?.densityPerSquareMeter) || 0;
  const fill = zone.critical_density ? Math.min(1, density / Number(zone.critical_density)) : 0;
  return <button
    type="button"
    className={`zone-row ${riskClass(zone.risk_level, stale)}${selected ? ' selected' : ''}`}
    aria-pressed={selected}
    onClick={() => onSelect?.(zone.id)}
  >
    <h3>{zone.name}</h3>
    <Badge value={stale ? 'stale' : zone.risk_level} />
    <span className="zone-figures">
      <span className="zone-count">{reading?.deviceCount?.toLocaleString() ?? '—'}</span>
      <span className="zone-unit">people</span>
      <span className="zone-density">{reading?.densityPerSquareMeter ?? '—'} /m²</span>
    </span>
    <span className="meter"><span style={{ width: `${Math.round(fill * 100)}%` }} /></span>
    <span className="zone-thresholds">warn ≥ {zone.warning_density} · crit ≥ {zone.critical_density} · {zone.area_sqm} m²</span>
  </button>;
}

function ResponderCard({ responder, index }) {
  const reachability = responder.signals?.reachability;
  const point = responder.signals?.simulationPoint;
  const reachable = reachability?.status === 'unknown' ? 'Unknown' : reachability?.dataReachable ? 'Reachable' : 'Not reachable';
  return <article className="responder-card">
    <div className="responder-top">
      <span className="responder-tag">R{String(index + 1).padStart(2, '0')}</span>
      <h3>{responder.name}</h3>
      <Badge value={responder.available ? 'available' : 'assigned'} />
    </div>
    <div className="signal">
      <i className={`dot ${reachability?.dataReachable ? 'normal' : reachability?.status === 'unknown' ? '' : 'critical'}`} />
      <span>Mobile data</span><b>{reachable}</b>
    </div>
    <div className="signal"><span>Congestion</span><Badge value={responder.signals?.congestion?.[0]?.congestionLevel || 'unknown'} /></div>
    <div className="signal">
      <span>Stadium position</span>
      <b>x {point?.x?.toFixed?.(1) ?? '—'} · z {point?.y?.toFixed?.(1) ?? '—'}</b>
    </div>
    <Notice error>{reachability?.error}</Notice>
    <details>
      <summary>Connection detail</summary>
      <p className="signal-meta">Nokia sandbox {reachability?.sandboxDevice} · checked {time(reachability?.checkedAt)}</p>
      <p className="hint">{responder.signals?.communicationAdvice}</p>
      <pre>{JSON.stringify(responder.signals?.rawResponses, null, 2)}</pre>
    </details>
  </article>;
}

function Brief({ decision, busy, retry }) {
  const advice = decision?.advice;
  const status = decision?.adviceStatus || 'disabled';
  if (status === 'pending') {
    return <section className="brief brief-pending">
      <div className="brief-head"><span className="label">Response brief</span><Badge value="analyzing" /></div>
      <p className="hint">Assessing the incident and eligible responders…</p>
    </section>;
  }
  if (status === 'failed') {
    return <section className="brief brief-failed">
      <div className="brief-head"><span className="label">Response brief</span><Badge value="unavailable" /></div>
      <p className="hint">{decision.adviceError}</p>
      <div><button type="button" className="btn" disabled={busy} onClick={retry}>Retry analysis</button></div>
    </section>;
  }
  if (!advice) {
    return <section className="brief">
      <div className="brief-head"><span className="label">Response brief</span><Badge value="offline" /></div>
      <p className="hint">Model analysis is unavailable. The verified responder ranking remains active.</p>
    </section>;
  }
  return <section className="brief">
    <div className="brief-head">
      <span className="label">Response brief</span>
      <Badge value={advice.urgency} />
      <Badge value={`${advice.confidence} confidence`} tone={advice.confidence} />
    </div>
    <p className="brief-summary">{advice.summary}</p>
    <p className="brief-action"><span>Proposed action</span>{advice.proposedAction}</p>
    <ul className="evidence">{advice.evidence.map((item) => <li key={item}>{item}</li>)}</ul>
    {advice.uncertainties.length > 0 && <details>
      <summary>Uncertainty to review</summary>
      <ul className="evidence">{advice.uncertainties.map((item) => <li key={item}>{item}</li>)}</ul>
    </details>}
    <span className="brief-meta">{advice.model} · generated {time(advice.generatedAt)}<br />Operator approval required</span>
  </section>;
}

function IncidentDetail({ incident, refresh, responders, zones, stale, stopped, proposedResponderId }) {
  const [reviewed, setReviewed] = useState(false);
  const advisedResponderId = incident.decision?.advice?.recommendedResponderId;
  const [selectedResponderId, setSelectedResponderId] = useState(advisedResponderId || incident.responder_id || '');
  const action = useAction();
  useEffect(() => setSelectedResponderId(advisedResponderId || incident.responder_id || ''), [advisedResponderId, incident.responder_id]);
  useEffect(() => {
    if (proposedResponderId) {
      setSelectedResponderId(proposedResponderId);
      setReviewed(false);
    }
  }, [proposedResponderId]);
  const selected = responders.find((r) => r.id === (incident.assigned_responder_id || selectedResponderId || incident.responder_id));
  const zone = zones.find((item) => item.id === incident.zone_id);
  const resolution = zone?.resolution;
  const active = Boolean(incident.active_zone_id) && !stopped;
  const dispatched = ['dispatched', 'acknowledged'].includes(incident.status);
  const arrived = !stale && incident.decision?.arrivalVerification?.verificationResult === 'TRUE';
  function mutate(path, body) {
    action.run(async () => { await apiRequest(`/incidents/${incident.id}/${path}`, { method: 'POST', body }); refresh?.(); });
  }
  return <div className="detail">
    <div className="detail-title">
      <h2>{zone?.name || 'Unknown zone'}</h2>
      <Badge value={incident.status} />
    </div>

    <Brief decision={incident.decision} busy={action.busy} retry={() => mutate('advice')} />

    <div className="block">
      <span className="label">Assignment</span>
      <div className="row"><span>Responder</span><b>{selected?.name || 'No eligible responder'}</b></div>
      {dispatched && <p className="arrival-status">
        <i className={`dot ${incident.status === 'dispatched' ? 'warning' : arrived ? 'normal' : ''}`} />
        {incident.status === 'dispatched' ? 'Waiting for acknowledgment' : arrived ? 'Arrived at the assigned area' : 'Tracking responder — arrival not yet verified'}
      </p>}
      {incident.decision?.workStartedAt && <p className="hint">Responder is managing the crowd.</p>}
      {incident.decision && <details>
        <summary>Why this responder?</summary>
        <pre>{JSON.stringify(incident.decision, null, 2)}</pre>
      </details>}
      <Notice error={Boolean(action.error)}>{action.error}</Notice>
    </div>

    {active && ['detected', 'awaiting_approval'].includes(incident.status) && <div className="block">
      <span className="label">Operator approval</span>
      {incident.responder_id ? <>
        <label className="field">
          <span>Dispatch</span>
          <select value={selectedResponderId} onChange={(event) => { setSelectedResponderId(event.target.value); setReviewed(false); }}>
            {(incident.decision?.candidates || []).map((candidate) => {
              const responder = responders.find((item) => item.id === candidate.responderId);
              return <option key={candidate.responderId} value={candidate.responderId}>{responder?.name || candidate.responderId} · {candidate.distanceMeters} m</option>;
            })}
          </select>
        </label>
        <label className="check">
          <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />
          I reviewed the evidence and route
        </label>
        <button
          type="button"
          className="btn btn-primary btn-lg btn-block"
          disabled={action.busy || !reviewed || stale || !selectedResponderId}
          onClick={() => mutate('approve', { routeReviewed: true, responderId: selectedResponderId })}
        >{selectedResponderId === advisedResponderId ? 'Accept advice & dispatch' : 'Dispatch selected responder'}</button>
      </> : <p className="hint">No responder is currently eligible for this incident.</p>}
      <button type="button" className="btn btn-quiet" disabled={action.busy || stale} onClick={() => mutate('recommend')}>Refresh recommendation</button>
    </div>}

    {active && incident.status === 'acknowledged' && <div className="block">
      <span className="label">Resolution</span>
      {zone?.risk_level === 'critical' && <p className="hint">This zone is still Critical. The 15-second confirmation starts once its reading becomes Normal or Warning.</p>}
      {zone?.risk_level === 'unknown' && <p className="hint">Location evidence is uncertain. Wait for a live Normal or Warning reading before resolving.</p>}
      {!['critical', 'unknown'].includes(zone?.risk_level) && <>
        <div className="row"><span>Stable safe readings</span><b>{Math.min(resolution?.stableForSeconds || 0, resolution?.requiredStableSeconds || 15)} / {resolution?.requiredStableSeconds || 15} s</b></div>
        <span className="meter"><span style={{ width: `${Math.round(Math.min(1, (resolution?.stableForSeconds || 0) / (resolution?.requiredStableSeconds || 15)) * 100)}%` }} /></span>
      </>}
      <button type="button" className="btn btn-lg btn-block" disabled={action.busy || stale || !resolution?.ready} onClick={() => mutate('resolve', {})}>
        {resolution?.ready ? 'Resolve incident' : 'Waiting for safe readings…'}
      </button>
    </div>}

    {dispatched && <Messages incidentId={incident.id} active={active} />}
    {incident.status === 'resolved' && <Messages incidentId={incident.id} active={false} />}
  </div>;
}

export default function OperatorPanel({ data, refresh }) {
  const [selectedId, setSelectedId] = useState('');
  const [zoneId, setZoneId] = useState(null);
  const [copilotProposal, setCopilotProposal] = useState(null);
  const [decisionView, setDecisionView] = useState('incidents');
  const incidents = data.incidents || [];
  const zoneName = (id) => data.zones.find((z) => z.id === id)?.name || 'Unknown zone';
  const quality = data.quality || {};
  const activeIncidents = incidents.filter((i) => i.active_zone_id && i.status !== 'resolved');
  const awaiting = activeIncidents.filter((i) => i.status === 'awaiting_approval').length;
  const activeZone = data.zones.find((z) => z.id === zoneId);
  const visibleIncidents = activeZone ? incidents.filter((i) => i.zone_id === zoneId) : incidents;
  const focused = visibleIncidents.find((i) => i.id === selectedId) || visibleIncidents.find((i) => i.active_zone_id) || visibleIncidents[0];
  const availableResponders = data.responders.filter((r) => r.available).length;
  const uncertain = (quality.ambiguous || 0) + (quality.stale || 0) + (quality.missing || 0);
  const selectZone = (id) => setZoneId(zoneId === id ? null : id);
  const reviewSuggestion = (suggestion) => {
    setZoneId(null);
    setSelectedId(suggestion.incidentId);
    setCopilotProposal(suggestion);
    setDecisionView('incidents');
  };

  return <div className="workspace">
    <aside className="rail">
      <section className="situation" aria-label="Event summary">
        <Metric label="Located attendees" value={(quality.located || 0).toLocaleString()} sub={`of ${data.attendeeCount.toLocaleString()} simulated attendees`} />
        <Metric label="Active incidents" value={activeIncidents.length.toString().padStart(2, '0')} sub={`${awaiting} awaiting dispatch approval`} alert={activeIncidents.length > 0} />
        <Metric label="Responders available" value={<>{availableResponders}<em> / {data.responders.length}</em></>} sub={`${data.responders.filter((r) => r.signals?.reachability?.dataReachable).length} data reachable`} />
        <Metric label="Uncertain locations" value={uncertain.toLocaleString()} sub={`${(quality.outside || 0).toLocaleString()} confirmed outside monitored zones`} />
      </section>
      <div className="pane-head">Zone conditions<span className="meta">1 device = 1 person</span></div>
      <div className="scroll">
        {data.zones.map((zone) => <ZoneRow key={zone.id} zone={zone} stale={data.stale} selected={zoneId === zone.id} onSelect={selectZone} />)}
      </div>
    </aside>

    <section className="stage">
      <VenueMap zones={data.zones} stale={data.stale} selectedId={zoneId} onSelect={selectZone} />
      <section className="responders">
        <div className="pane-head">Response team<span className="meta">{availableResponders} of {data.responders.length} available</span></div>
        {data.responders.length
          ? <div className="responder-strip">{data.responders.map((responder, index) => <ResponderCard key={responder.id} responder={responder} index={index} />)}</div>
          : <p className="responder-empty">No responders are registered for this event.</p>}
      </section>
    </section>

    <aside className="rail decision-rail">
      <nav className="decision-tabs" aria-label="Decision workspace">
        <button type="button" className={decisionView === 'incidents' ? 'active' : ''} aria-pressed={decisionView === 'incidents'} onClick={() => setDecisionView('incidents')}>
          <span>Incidents</span><span className={awaiting ? 'count-chip alert' : 'count-chip'}>{visibleIncidents.length}</span>
        </button>
        <button type="button" className={decisionView === 'copilot' ? 'active' : ''} aria-pressed={decisionView === 'copilot'} onClick={() => setDecisionView('copilot')}>
          <span>AMAN Copilot</span>
        </button>
      </nav>
      <div className="decision-view" hidden={decisionView !== 'copilot'}>
        <Copilot data={data} onReviewSuggestion={reviewSuggestion} />
      </div>
      <div className="decision-view" hidden={decisionView !== 'incidents'}>
          <div className="pane-head">
            Response coordination
            <span className="meta">{awaiting} awaiting approval</span>
          </div>
          {activeZone && <button type="button" className="filter-chip" onClick={() => setZoneId(null)}>Filtered to {activeZone.name} · clear ✕</button>}
          {visibleIncidents.length > 0 && <div className="queue">
            {visibleIncidents.map((incident) => {
              const zone = data.zones.find((z) => z.id === incident.zone_id);
              return <button
                type="button"
                key={incident.id}
                className={`queue-row ${riskClass(zone?.risk_level, data.stale)}${focused?.id === incident.id ? ' selected' : ''}`}
                aria-pressed={focused?.id === incident.id}
                onClick={() => setSelectedId(incident.id)}
              >
                <strong>{zoneName(incident.zone_id)}</strong>
                <Badge value={incident.status} />
                <span className="queue-meta">{incident.active_zone_id ? 'Active' : 'Closed'} · incident {incident.id}</span>
              </button>;
            })}
          </div>}
          <div className="scroll">
            {focused
              ? <IncidentDetail key={focused.id} incident={focused} refresh={refresh} responders={data.responders} zones={data.zones} stale={data.stale} stopped={data.status === 'stopped'} proposedResponderId={copilotProposal?.incidentId === focused.id ? copilotProposal.responderId : null} />
              : <div className="empty-state">
                <div className="empty-rule" />
                <h3>No incidents to review</h3>
                <p>Monitoring continues. New incidents appear here when a zone requires attention.</p>
              </div>}
          </div>
      </div>
    </aside>
  </div>;
}
