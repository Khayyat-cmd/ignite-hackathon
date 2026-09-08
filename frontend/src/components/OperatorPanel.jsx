import { useEffect, useMemo, useRef, useState } from 'react';
import { apiRequest } from '../api/client';
import { useAction } from '../hooks/useAction';
import { useZoneHistory, trendOf } from '../hooks/useZoneHistory';
import { useI18n } from '../i18n';
import { Badge, Notice, Sparkline, TrendTag, playAlertTone, riskClass } from './Shared';
import Copilot from './Copilot';
import Messages from './Messages';
import VenueMap from './VenueMap';

const MUTE_KEY = 'aman.alertMuted';
const ALARM_REPEAT_MS = 6000;
const FOCUS_DEBOUNCE_MS = 250;
const BASE_TITLE = 'AMAN Command Center';
const FRESH_MS = 12000;
const DEFAULT_STABLE_SECONDS = 15;

function readMuted() {
  if (typeof window === 'undefined') return false;
  try { return window.localStorage.getItem(MUTE_KEY) === '1'; } catch { return false; }
}

function Metric({ label, value, sub, alert = false }) {
  return <div className={alert ? 'metric alert' : 'metric'}>
    <span className="metric-label">{label}</span>
    <span className="metric-value">{value}</span>
    <span className="metric-sub">{sub}</span>
  </div>;
}

function ZoneRow({ zone, stale, selected, linked, trend, onSelect }) {
  const { t, n } = useI18n();
  const reading = zone.latest_reading;
  const density = Number(reading?.densityPerSquareMeter) || 0;
  const fill = zone.critical_density ? Math.min(1, density / Number(zone.critical_density)) : 0;
  return <button
    type="button"
    className={`zone-row ${riskClass(zone.risk_level, stale)}${selected ? ' selected' : ''}${linked ? ' linked' : ''}`}
    aria-pressed={selected}
    onClick={() => onSelect?.(zone.id)}
  >
    <h3>{zone.name}</h3>
    <Badge value={stale ? 'stale' : zone.risk_level} />
    <span className="zone-figures">
      <span className="zone-count">{reading?.deviceCount == null ? '—' : n(reading.deviceCount)}</span>
      <span className="zone-unit">{t('zone.people')}</span>
      <span className="zone-density" dir="ltr">{reading?.densityPerSquareMeter ?? '—'} /m²</span>
    </span>
    <span className="meter"><span style={{ width: `${Math.round(fill * 100)}%` }} /></span>
    {trend
      ? <span className="zone-trend"><Sparkline samples={trend.samples} /><TrendTag trend={trend} /></span>
      : <span className="zone-thresholds">{t('zone.thresholds', { warn: zone.warning_density, crit: zone.critical_density, area: zone.area_sqm })}</span>}
  </button>;
}

function ResponderCard({ responder, index, assignment, linked, onSelect }) {
  const { t, term } = useI18n();
  const reachability = responder.signals?.reachability;
  const reachable = reachability?.status === 'unknown'
    ? t('responder.unknown')
    : reachability?.dataReachable ? t('responder.reachable') : t('responder.notReachable');
  const select = () => onSelect?.(responder.id, assignment);
  return <article
    className={`responder-card${linked ? ' linked' : ''}${assignment ? ' engaged' : ''}`}
    role="button"
    tabIndex={0}
    aria-pressed={linked}
    onClick={select}
    onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(); } }}
  >
    <div className="responder-top">
      <span className="responder-tag">R{String(index + 1).padStart(2, '0')}</span>
      <h3>{responder.name}</h3>
      <Badge value={responder.available ? 'available' : 'assigned'} />
    </div>
    <div className="signal">
      <i className={`dot ${reachability?.dataReachable ? 'normal' : reachability?.status === 'unknown' ? '' : 'critical'}`} />
      <span>{t('responder.mobileData')}</span><b>{reachable}</b>
    </div>
    {assignment && <p className="responder-assignment">
      <i className="dot warning" />{t('responder.assignedTo', { zone: assignment.zoneName, status: term(assignment.status) })}
    </p>}
    <Notice error>{reachability?.error}</Notice>
  </article>;
}

function DecisionFacts({ incident, responder }) {
  const { t, role } = useI18n();
  const decision = incident.decision || {};
  const candidate = (decision.candidates || []).find((item) => item.responderId === responder?.id);
  const advised = decision.advice?.recommendedResponderId === responder?.id;
  if (!candidate && !advised) return null;
  return <div className="decision-facts" aria-label={t('facts.aria')}>
    <span className="label">{t('facts.label')}</span>
    <div className="fact-tags">
      {candidate?.distanceMeters != null && <span>{t('facts.distance', { count: candidate.distanceMeters })}</span>}
      {responder?.signals?.reachability?.dataReachable && <span>{t('facts.dataConfirmed')}</span>}
      {responder?.role && <span>{role(responder.role)}</span>}
      {advised && <span>{t('facts.recommended')}</span>}
    </div>
    <p className="hint">{t('facts.hint')}</p>
  </div>;
}

function Brief({ decision, busy, retry }) {
  const { t, term, time } = useI18n();
  const advice = decision?.advice;
  const status = decision?.adviceStatus || 'disabled';
  if (status === 'pending') {
    return <section className="brief brief-pending">
      <div className="brief-head"><span className="label">{t('brief.label')}</span><Badge value="analyzing" /></div>
      <p className="hint">{t('brief.pendingHint')}</p>
    </section>;
  }
  if (status === 'failed') {
    return <section className="brief brief-failed">
      <div className="brief-head"><span className="label">{t('brief.label')}</span><Badge value="unavailable" /></div>
      <p className="hint">{t('brief.failedHint')}</p>
      <div><button type="button" className="btn" disabled={busy} onClick={retry}>{t('brief.retry')}</button></div>
    </section>;
  }
  if (!advice) {
    return <section className="brief">
      <div className="brief-head"><span className="label">{t('brief.label')}</span><Badge value="offline" /></div>
      <p className="hint">{t('brief.offlineHint')}</p>
    </section>;
  }
  return <section className="brief">
    <div className="brief-head">
      <span className="label">{t('brief.label')}</span>
      <Badge value={advice.urgency} />
      <Badge value={advice.confidence} tone={advice.confidence} label={t('brief.confidence', { level: term(advice.confidence) })} />
    </div>
    <p className="brief-summary">{advice.summary}</p>
    <p className="brief-action"><span>{t('brief.proposedAction')}</span>{advice.proposedAction}</p>
    <ul className="evidence">{advice.evidence.map((item) => <li key={item}>{item}</li>)}</ul>
    {advice.uncertainties.length > 0 && <details>
      <summary>{t('brief.uncertainty')}</summary>
      <ul className="evidence">{advice.uncertainties.map((item) => <li key={item}>{item}</li>)}</ul>
    </details>}
    <span className="brief-meta">{t('brief.meta', { time: time(advice.generatedAt) })}</span>
  </section>;
}

function IncidentDetail({ incident, refresh, responders, zones, stale, stopped, proposedResponderId }) {
  const { t, n } = useI18n();
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
  const requiredStable = resolution?.requiredStableSeconds || DEFAULT_STABLE_SECONDS;
  const stableFor = Math.min(resolution?.stableForSeconds || 0, requiredStable);
  function mutate(path, body) {
    action.run(async () => { await apiRequest(`/incidents/${incident.id}/${path}`, { method: 'POST', body }); refresh?.(); });
  }
  return <div className="detail">
    <div className="detail-title">
      <h2>{zone?.name || t('incident.unknownZone')}</h2>
      <Badge value={incident.status} />
    </div>

    <Brief decision={incident.decision} busy={action.busy} retry={() => mutate('advice')} />

    <div className="block">
      <span className="label">{t('assignment.label')}</span>
      <div className="row"><span>{t('assignment.responder')}</span><b>{selected?.name || t('assignment.none')}</b></div>
      {dispatched && <p className="arrival-status">
        <i className={`dot ${incident.status === 'dispatched' ? 'warning' : arrived ? 'normal' : ''}`} />
        {incident.status === 'dispatched' ? t('arrival.waiting') : arrived ? t('arrival.arrived') : t('arrival.tracking')}
      </p>}
      {incident.decision?.workStartedAt && <p className="hint">{t('assignment.working')}</p>}
      <DecisionFacts incident={incident} responder={selected} />
      <Notice error={Boolean(action.error)}>{action.error}</Notice>
    </div>

    {dispatched && <Messages incidentId={incident.id} active={active} />}
    {incident.status === 'resolved' && <Messages incidentId={incident.id} active={false} />}

    {active && ['detected', 'awaiting_approval'].includes(incident.status) && <div className="block block-action">
      <span className="label">{t('approval.label')}</span>
      {incident.responder_id ? <>
        <label className="field">
          <span>{t('approval.dispatch')}</span>
          <select value={selectedResponderId} onChange={(event) => { setSelectedResponderId(event.target.value); setReviewed(false); }}>
            {(incident.decision?.candidates || []).map((candidate) => {
              const responder = responders.find((item) => item.id === candidate.responderId);
              return <option key={candidate.responderId} value={candidate.responderId}>
                {t('approval.option', { name: responder?.name || candidate.responderId, distance: candidate.distanceMeters })}
              </option>;
            })}
          </select>
        </label>
        <label className="check">
          <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />
          {t('approval.reviewed')}
        </label>
        <button
          type="button"
          className="btn btn-primary btn-lg btn-block"
          disabled={action.busy || !reviewed || stale || !selectedResponderId}
          onClick={() => mutate('approve', { routeReviewed: true, responderId: selectedResponderId })}
        >{selectedResponderId === advisedResponderId ? t('approval.dispatchRecommended') : t('approval.dispatchSelected')}</button>
      </> : <p className="hint">{t('approval.noneEligible')}</p>}
      <button type="button" className="btn btn-quiet" disabled={action.busy || stale} onClick={() => mutate('recommend')}>{t('approval.refresh')}</button>
    </div>}

    {active && incident.status === 'acknowledged' && <div className="block block-action">
      <span className="label">{t('resolution.label')}</span>
      {zone?.risk_level === 'critical' && <p className="hint">{t('resolution.criticalHint', { seconds: requiredStable })}</p>}
      {zone?.risk_level === 'unknown' && <p className="hint">{t('resolution.unknownHint')}</p>}
      {!['critical', 'unknown'].includes(zone?.risk_level) && <>
        <div className="row"><span>{t('resolution.stable')}</span><b dir="ltr">{t('resolution.progress', { done: n(stableFor), total: n(requiredStable) })}</b></div>
        <span className="meter"><span style={{ width: `${Math.round(Math.min(1, (resolution?.stableForSeconds || 0) / requiredStable) * 100)}%` }} /></span>
      </>}
      <button type="button" className="btn btn-lg btn-block" disabled={action.busy || stale || !resolution?.ready} onClick={() => mutate('resolve', {})}>
        {resolution?.ready ? t('resolution.resolve') : t('resolution.waiting')}
      </button>
    </div>}
  </div>;
}

function AttentionBar({ items, zoneName, muted, onReview, onToggleMute }) {
  const { t } = useI18n();
  if (!items.length) return null;
  const oldest = items[items.length - 1];
  return <div className="attentionbar" role="alert">
    <i className="dot critical" />
    <strong>{t('attention.count', { count: items.length })}</strong>
    <span className="attention-zones">{items.map((item) => zoneName(item.zone_id)).join(' · ')}</span>
    <button type="button" className="btn btn-primary" onClick={() => onReview(oldest)}>{t('attention.review', { zone: zoneName(oldest.zone_id) })}</button>
    <button type="button" className="btn btn-quiet" aria-pressed={muted} onClick={onToggleMute}>{muted ? t('attention.soundOff') : t('attention.soundOn')}</button>
  </div>;
}

export default function OperatorPanel({ data, refresh }) {
  const { t, n, time } = useI18n();
  const [selectedId, setSelectedId] = useState('');
  const [filterZoneId, setFilterZoneId] = useState(null);
  const [pinnedResponderId, setPinnedResponderId] = useState(null);
  const [copilotProposal, setCopilotProposal] = useState(null);
  const [decisionView, setDecisionView] = useState('incidents');
  const [muted, setMuted] = useState(readMuted);
  const [freshIds, setFreshIds] = useState({});
  const [secondScreenLive, setSecondScreenLive] = useState(true);

  const incidents = data.incidents || [];
  const zoneName = (id) => data.zones.find((z) => z.id === id)?.name || t('incident.unknownZone');
  const quality = data.quality || {};
  const activeIncidents = incidents.filter((i) => i.active_zone_id && i.status !== 'resolved');
  const awaitingIncidents = activeIncidents.filter((i) => i.status === 'awaiting_approval');
  const awaiting = awaitingIncidents.length;
  const filterZone = data.zones.find((z) => z.id === filterZoneId);
  const visibleIncidents = filterZone ? incidents.filter((i) => i.zone_id === filterZoneId) : incidents;
  const focused = visibleIncidents.find((i) => i.id === selectedId) || visibleIncidents.find((i) => i.active_zone_id) || visibleIncidents[0];
  const availableResponders = data.responders.filter((r) => r.available).length;
  const uncertain = (quality.ambiguous || 0) + (quality.stale || 0) + (quality.missing || 0);

  // One focus, three panes. The incident under review decides which zone the
  // map and the left rail highlight, and which responder the map links to.
  const linkedZoneId = focused?.zone_id ?? filterZoneId ?? null;
  const linkedResponderId = pinnedResponderId
    || focused?.assigned_responder_id
    || focused?.decision?.advice?.recommendedResponderId
    || focused?.responder_id
    || null;
  const assignments = useMemo(() => {
    const map = {};
    for (const incident of incidents) {
      const id = incident.assigned_responder_id;
      if (id && incident.active_zone_id && incident.status !== 'resolved') {
        map[id] = { status: incident.status, zoneName: zoneName(incident.zone_id), incidentId: incident.id };
      }
    }
    return map;
  }, [incidents, data.zones]);

  const history = useZoneHistory(data.zones, data.revision, data.observedAt);

  // Flag arrivals so a new row is visible on a wall display, and sound a cue
  // when the number of decisions waiting on the operator goes up.
  const seen = useRef(null);
  const timers = useRef([]);
  const incidentKey = incidents.map((incident) => incident.id).join(',');
  useEffect(() => () => timers.current.forEach(clearTimeout), []);
  useEffect(() => {
    const ids = incidentKey ? incidentKey.split(',') : [];
    if (seen.current === null) { seen.current = new Set(ids); return; }
    const added = ids.filter((id) => !seen.current.has(id));
    ids.forEach((id) => seen.current.add(id));
    if (!added.length) return;
    setFreshIds((current) => ({ ...current, ...Object.fromEntries(added.map((id) => [id, true])) }));
    timers.current.push(setTimeout(() => setFreshIds((current) => {
      const next = { ...current };
      added.forEach((id) => delete next[id]);
      return next;
    }), FRESH_MS));
  }, [incidentKey]);

  const mutedRef = useRef(muted);
  mutedRef.current = muted;
  const lastAwaiting = useRef(null);
  useEffect(() => {
    if (lastAwaiting.current !== null && awaiting > lastAwaiting.current && !mutedRef.current) playAlertTone();
    lastAwaiting.current = awaiting;
  }, [awaiting]);

  // An incident nobody has approved keeps sounding. One cue is easy to miss in a
  // loud control room, so the siren repeats until the queue is cleared or muted.
  useEffect(() => {
    if (!awaiting || muted) return undefined;
    const timer = setInterval(playAlertTone, ALARM_REPEAT_MS);
    return () => clearInterval(timer);
  }, [awaiting, muted]);

  // The Unity screen is a separate application that never talks to Electron; it
  // follows the operator by polling the run, so the console publishes what it has
  // focused and Unity moves its camera there.
  const focusedIncidentId = focused?.id ?? null;
  useEffect(() => {
    if (!data.id) return undefined;
    const controller = new AbortController();
    // Debounced: clicking through the queue must not post once per row.
    const timer = setTimeout(() => {
      apiRequest(`/simulations/${data.id}/focus`, {
        method: 'POST',
        body: { zoneId: linkedZoneId, incidentId: focusedIncidentId },
        signal: controller.signal,
      })
        .then(() => setSecondScreenLive(true))
        .catch((error) => { if (error.name !== 'AbortError') setSecondScreenLive(false); });
    }, FOCUS_DEBOUNCE_MS);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [data.id, linkedZoneId, focusedIncidentId]);

  // The window is often behind the Unity screen, so the title carries the count too.
  useEffect(() => {
    if (!awaiting) {
      document.title = BASE_TITLE;
      return undefined;
    }
    let on = true;
    const paint = () => {
      document.title = on ? `\u26A0 ${awaiting} AWAITING DISPATCH` : BASE_TITLE;
      on = !on;
    };
    paint();
    const timer = setInterval(paint, 900);
    return () => {
      clearInterval(timer);
      document.title = BASE_TITLE;
    };
  }, [awaiting]);

  function toggleMute() {
    setMuted((current) => {
      const next = !current;
      try { window.localStorage.setItem(MUTE_KEY, next ? '1' : '0'); } catch { /* preference only */ }
      return next;
    });
  }

  function reviewIncident(incident) {
    setFilterZoneId(null);
    setSelectedId(incident.id);
    setPinnedResponderId(null);
    setDecisionView('incidents');
  }

  function selectZone(id) {
    const next = filterZoneId === id ? null : id;
    setFilterZoneId(next);
    setPinnedResponderId(null);
    if (next) {
      const inZone = incidents.filter((incident) => incident.zone_id === next);
      const target = inZone.find((incident) => incident.active_zone_id) || inZone[0];
      if (target) { setSelectedId(target.id); setDecisionView('incidents'); }
    }
  }

  function selectResponder(id, assignment) {
    setPinnedResponderId((current) => (current === id ? null : id));
    if (assignment?.incidentId) {
      setFilterZoneId(null);
      setSelectedId(assignment.incidentId);
      setDecisionView('incidents');
    }
  }

  const reviewSuggestion = (suggestion) => {
    setFilterZoneId(null);
    setSelectedId(suggestion.incidentId);
    setCopilotProposal(suggestion);
    setDecisionView('incidents');
  };

  return <>
    <AttentionBar items={awaitingIncidents} zoneName={zoneName} muted={muted} onReview={reviewIncident} onToggleMute={toggleMute} />
    <div className="workspace">
      <aside className="rail">
        <section className="situation" aria-label={t('rail.summaryAria')}>
          <Metric label={t('metric.located')} value={n(quality.located || 0)} sub={t('metric.locatedSub', { count: n(data.attendeeCount) })} />
          <Metric label={t('metric.activeIncidents')} value={activeIncidents.length.toString().padStart(2, '0')} sub={t('metric.activeIncidentsSub', { count: n(awaiting) })} alert={activeIncidents.length > 0} />
          <Metric label={t('metric.respondersAvailable')} value={<>{n(availableResponders)}<em> / {n(data.responders.length)}</em></>} sub={t('metric.respondersAvailableSub', { count: n(data.responders.filter((r) => r.signals?.reachability?.dataReachable).length) })} />
          <Metric label={t('metric.uncertain')} value={n(uncertain)} sub={t('metric.uncertainSub', { count: n(quality.outside || 0) })} />
        </section>
        <div className="pane-head">{t('zones.head')}<span className="meta">{t('zones.meta')}</span></div>
        <div className="scroll">
          {data.zones.map((zone) => <ZoneRow
            key={zone.id}
            zone={zone}
            stale={data.stale}
            selected={filterZoneId === zone.id}
            linked={filterZoneId !== zone.id && linkedZoneId === zone.id}
            trend={data.stale ? null : trendOf(history[zone.id])}
            onSelect={selectZone}
          />)}
        </div>
      </aside>

      <section className="stage">
        <VenueMap
          zones={data.zones}
          stale={data.stale}
          selectedId={filterZoneId}
          onSelect={selectZone}
          responders={data.responders}
          incidents={incidents}
          focusedIncidentId={focused?.id}
          focusedResponderId={linkedResponderId}
          secondScreenLive={secondScreenLive}
          secondScreenZone={linkedZoneId ? zoneName(linkedZoneId) : null}
          linkedZoneId={filterZoneId === linkedZoneId ? null : linkedZoneId}
          onSelectResponder={(id) => selectResponder(id, assignments[id])}
        />
        <section className="responders">
          <div className="pane-head">{t('responders.head')}<span className="meta">{t('responders.meta', { available: n(availableResponders), total: n(data.responders.length) })}</span></div>
          {data.responders.length
            ? <div className="responder-strip">{data.responders.map((responder, index) => <ResponderCard
              key={responder.id}
              responder={responder}
              index={index}
              assignment={assignments[responder.id]}
              linked={linkedResponderId === responder.id}
              onSelect={selectResponder}
            />)}</div>
            : <p className="responder-empty">{t('responders.empty')}</p>}
        </section>
      </section>

      <aside className="rail decision-rail">
        <nav className="decision-tabs" aria-label={t('tabs.aria')}>
          <button type="button" className={decisionView === 'incidents' ? 'active' : ''} aria-pressed={decisionView === 'incidents'} onClick={() => setDecisionView('incidents')}>
            <span>{t('tabs.incidents')}</span><span className={awaiting ? 'count-chip alert' : 'count-chip'}>{n(visibleIncidents.length)}</span>
          </button>
          <button type="button" className={decisionView === 'copilot' ? 'active' : ''} aria-pressed={decisionView === 'copilot'} onClick={() => setDecisionView('copilot')}>
            <span>{t('tabs.assistant')}</span>
          </button>
        </nav>
        <div className="decision-view" hidden={decisionView !== 'copilot'}>
          <Copilot data={data} onReviewSuggestion={reviewSuggestion} />
        </div>
        <div className="decision-view" hidden={decisionView !== 'incidents'}>
          <div className="pane-head">
            {t('queue.head')}
            <span className="meta">{t('queue.meta', { count: n(awaiting) })}</span>
          </div>
          {filterZone && <button type="button" className="filter-chip" onClick={() => selectZone(filterZone.id)}>{t('queue.filter', { zone: filterZone.name })}</button>}
          {visibleIncidents.length > 0 && <div className="queue">
            {visibleIncidents.map((incident) => {
              const zone = data.zones.find((z) => z.id === incident.zone_id);
              return <button
                type="button"
                key={incident.id}
                className={`queue-row ${riskClass(zone?.risk_level, data.stale)}${focused?.id === incident.id ? ' selected' : ''}${freshIds[incident.id] ? ' fresh' : ''}`}
                aria-pressed={focused?.id === incident.id}
                onClick={() => { setSelectedId(incident.id); setPinnedResponderId(null); }}
              >
                <strong>{zoneName(incident.zone_id)}</strong>
                <Badge value={incident.status} />
                <span className="queue-meta">{incident.active_zone_id ? t('queue.active') : t('queue.closed')}{incident.created_at ? ` · ${time(incident.created_at)}` : ''}</span>
              </button>;
            })}
          </div>}
          <div className="scroll">
            {focused
              ? <IncidentDetail key={focused.id} incident={focused} refresh={refresh} responders={data.responders} zones={data.zones} stale={data.stale} stopped={data.status === 'stopped'} proposedResponderId={copilotProposal?.incidentId === focused.id ? copilotProposal.responderId : null} />
              : <div className="empty-state">
                <div className="empty-rule" />
                <h3>{t('queue.emptyTitle')}</h3>
                <p>{t('queue.emptyBody')}</p>
              </div>}
          </div>
        </div>
      </aside>
    </div>
  </>;
}
