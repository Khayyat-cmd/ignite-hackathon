import { describe, expect, it } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import OperatorPanel from './OperatorPanel';
import { projectZones } from './VenueMap';

const zone = { id: 'east', name: 'East Entrance', risk_level: 'critical', area_sqm: 900, warning_density: 1, critical_density: 2, latest_reading: { deviceCount: 2100, densityPerSquareMeter: 2.33 }, boundary: [{ latitude: 33.9, longitude: 35.5 }, { latitude: 33.9, longitude: 35.501 }, { latitude: 33.901, longitude: 35.501 }, { latitude: 33.901, longitude: 35.5 }] };
const base = { attendeeCount: 3000, quality: { located: 2100, outside: 900 }, zones: [zone], responders: [], incidents: [], status: 'running', stale: false };

describe('Command center', () => {
  it('waits for acknowledgment even when an older snapshot has verified location', () => {
    const incident = { id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'dispatched', decision: { arrivalVerification: { verificationResult: 'TRUE' } } };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, incidents: [incident] }} />);
    expect(html).toContain('Waiting for acknowledgment');
    expect(html).not.toContain('Arrived at the assigned area');
    const arrived = renderToStaticMarkup(<OperatorPanel data={{ ...base, incidents: [{ ...incident, status: 'acknowledged' }] }} />);
    expect(arrived).toContain('Arrived at the assigned area');
    expect(arrived).toContain('arrival-status');
  });
  it('renders backend counts and an empty incident state without fabricated alerts', () => {
    const html = renderToStaticMarkup(<OperatorPanel data={base} />);
    expect(html).toContain('2,100');
    expect(html).toContain('No incidents to review');
    expect(html).toContain('East Entrance');
    expect(html).toContain('ZONE SCHEMATIC');
  });
  it('lifts a zone label that would be painted over by its neighbour', () => {
    // A narrow zone beside a wide one: the label is wider than the zone it names.
    const narrow = { ...zone, id: 'east', name: 'East Entrance', boundary: [{ latitude: 33.9, longitude: 35.5 }, { latitude: 33.9, longitude: 35.5004 }, { latitude: 33.901, longitude: 35.5004 }, { latitude: 33.901, longitude: 35.5 }] };
    const wide = { ...zone, id: 'north', name: 'North Concourse', risk_level: 'normal', latest_reading: { deviceCount: 3001, densityPerSquareMeter: 1.25 }, boundary: [{ latitude: 33.9, longitude: 35.5004 }, { latitude: 33.9, longitude: 35.503 }, { latitude: 33.901, longitude: 35.503 }, { latitude: 33.901, longitude: 35.5004 }] };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, zones: [narrow, wide] }} />);
    const rows = [...html.matchAll(/<text x="[\d.]+" y="([\d.]+)">([^<]+)</g)].map(([, y, name]) => [name, Number(y)]);
    const east = rows.find(([name]) => name === 'East Entrance');
    const north = rows.find(([name]) => name === 'North Concourse');
    expect(east).toBeDefined();
    expect(north).toBeDefined();
    expect(east[1]).not.toBe(north[1]);
    // Both counts survive; the old layout clipped the narrow zone's.
    expect(html).toContain('2,100');
    expect(html).toContain('3,001');
  });
  it('tells the operator what the Unity second screen is framing', () => {
    const venue = renderToStaticMarkup(<OperatorPanel data={base} />);
    expect(venue).toContain('Second screen · whole venue');

    // Selecting an incident links its zone, and that is what Unity follows.
    const incident = { id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', decision: {} };
    const focused = renderToStaticMarkup(<OperatorPanel data={{ ...base, incidents: [incident] }} />);
    expect(focused).toContain('Second screen · East Entrance');
  });
  it('marks map conditions outdated when the snapshot is stale', () => {
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, stale: true }} />);
    expect(html).toContain('East Entrance: outdated');
    expect(html).not.toContain('risk-critical');
  });
  it('renders a dispatch recommendation and responder stadium position', () => {
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders: [{ id: 'r1', name: 'Concourse Marshal', role: 'crowd_marshal', available: true, signals: { simulationPoint: { x: 32.5, y: 0 }, location: { accuracyMeters: 1 }, reachability: { dataReachable: true, checkedAt: '2026-09-05T12:00:00Z' }, rawResponses: { privateDebugData: true } } }], incidents: [{ id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', responder_id: 'r1', decision: { candidates: [{ responderId: 'r1', distanceMeters: 42 }] } }] }} />);
    expect(html).toContain('Concourse Marshal');
    expect(html).not.toContain('Stadium position');
    expect(html).not.toContain('x 32.5');
    expect(html).toContain('I reviewed the situation and route');
    expect(html).toContain('Dispatch selected responder');
    expect(html).toContain('42 m away');
    expect(html).toContain('Mobile data confirmed');
    // The responder card carries the live signal, not a timestamp and an
    // accuracy radius an operator has to interpret mid-incident.
    expect(html).not.toContain('Location ±1 m');
    expect(html).not.toContain('Updated 12:00:00');
    expect(html).not.toContain('privateDebugData');
    expect(html).not.toContain('Connection detail');
  });
  it('renders structured decision support and keeps operator approval explicit', () => {
    const incident = {
      id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', responder_id: 'r1',
      decision: {
        candidates: [{ responderId: 'r1', distanceMeters: 42 }],
        adviceStatus: 'ready',
        advice: {
          summary: 'Crowd density is rising at the east entrance.', urgency: 'high', confidence: 'medium',
          proposedAction: 'Send the nearest reachable marshal and open the alternate lane.',
          recommendedResponderId: 'r1', evidence: ['Critical density reading', 'Responder is data reachable'],
          uncertainties: ['Camera confirmation is unavailable'], model: 'internal-model-name', generatedAt: '2026-09-05T12:00:00Z',
        },
      },
    };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders: [{ id: 'r1', name: 'Concourse Marshal', available: true, signals: {} }], incidents: [incident] }} />);
    expect(html).toContain('Response brief');
    expect(html).toContain('Crowd density is rising');
    expect(html).toContain('Dispatch recommended responder');
    expect(html).toContain('Review before dispatch');
    expect(html).not.toContain('internal-model-name');
  });
  it('tells the operator what the escalation did when a dispatch goes unacknowledged', () => {
    const responders = [{ id: 'r1', name: 'Concourse Marshal', available: false, signals: {} }];
    const reminded = {
      id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'dispatched', responder_id: 'r1', assigned_responder_id: 'r1',
      decision: { escalation: { outcome: 'reminder_sent', responderId: 'r1', handledAt: '2026-09-12T18:02:00Z' } },
    };
    const remindedHtml = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders, incidents: [reminded] }} />);
    expect(remindedHtml).toContain('No acknowledgement — reminder sent to Concourse Marshal.');

    const dropped = {
      id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'detected', responder_id: null,
      decision: { escalation: { outcome: 'reassignment_requested', responderId: 'r1', reason: 'mobile_data_unreachable', handledAt: '2026-09-12T18:02:00Z' }, candidates: [], adviceStatus: 'pending' },
    };
    const droppedHtml = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders, incidents: [dropped] }} />);
    expect(droppedHtml).toContain('Concourse Marshal is unreachable — the AI is finding another responder.');
    expect(droppedHtml).toContain('escalation-reassigned');

    const stale = { ...dropped, decision: { ...dropped.decision, escalation: { ...dropped.decision.escalation, reason: 'reachability_unavailable_or_stale' } } };
    const staleHtml = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders, incidents: [stale] }} />);
    expect(staleHtml).toContain('Reachability for Concourse Marshal is unknown');

    const clean = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders, incidents: [{ ...reminded, decision: {} }] }} />);
    expect(clean).not.toContain('escalation');
  });
  it('orders the queue as a worklist and shows how long each incident has been open', () => {
    const south = { ...zone, id: 'south', name: 'South Concourse', risk_level: 'normal' };
    const closed = { id: 'i-old', zone_id: 'south', active_zone_id: null, status: 'resolved', created_at: '2026-09-12T18:05:00Z' };
    const open = { id: 'i-new', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', responder_id: 'r1', created_at: '2026-09-12T18:00:00Z', decision: { candidates: [{ responderId: 'r1', distanceMeters: 42 }], adviceStatus: 'ready' } };
    const html = renderToStaticMarkup(<OperatorPanel data={{
      ...base, zones: [zone, south], observedAt: '2026-09-12T18:02:05Z',
      responders: [{ id: 'r1', name: 'Concourse Marshal', available: true, signals: {} }],
      // Newest first, the order the backend sends: the decision still has to come first.
      incidents: [closed, open],
    }} />);
    expect(html.indexOf('East Entrance')).toBeLessThan(html.indexOf('South Concourse'));
    expect(html).toContain('open 2:05');
    expect(html).toContain('queue-clock');
    expect(html).toContain('Closed · ');
  });
  it('says the agent is still working rather than claiming nobody is eligible', () => {
    // While the advice job runs the backend holds back a selection, so the incident has
    // ranked candidates and no responder_id. Calling that "no eligible responder"
    // contradicted the brief's own analyzing badge on the same screen.
    const incident = {
      id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'detected', responder_id: null,
      decision: { candidates: [{ responderId: 'r1', distanceMeters: 42 }], fallbackResponderId: 'r1', adviceStatus: 'pending' },
    };
    const data = { ...base, responders: [{ id: 'r1', name: 'Concourse Marshal', available: true, signals: {} }], incidents: [incident] };
    const html = renderToStaticMarkup(<OperatorPanel data={data} />);
    expect(html).toContain('Awaiting AI recommendation');
    expect(html).toContain('The dispatch options open as soon as the AI answers.');
    expect(html).not.toContain('No eligible responder');
    expect(html).not.toContain('No responder is currently eligible');
    const empty = { ...incident, decision: { candidates: [], adviceStatus: 'pending' } };
    const nobody = renderToStaticMarkup(<OperatorPanel data={{ ...data, incidents: [empty] }} />);
    expect(nobody).toContain('No responder is currently eligible');
    expect(nobody).not.toContain('Awaiting AI recommendation');
  });
  it('shows the CAMARA calls the agent made and which were live', () => {
    const advice = {
      summary: 'The nearest marshal is data reachable and inside the zone.', urgency: 'critical', confidence: 'medium',
      proposedAction: 'Dispatch the nearest marshal after review.', recommendedResponderId: 'r1',
      evidence: ['Device Reachability confirms mobile data.'], uncertainties: [],
      model: 'internal-model-name', agentRuntime: 'openai-agents-python', agentStatus: 'completed',
      evidenceMode: 'simulated_fixture', agentVersion: '2.0.0', generatedAt: '2026-09-12T12:00:00Z',
      toolTrace: [
        { sequence: 1, tool: 'device_reachability', reason: 'Confirm the marshal can be reached.', responderId: 'r1', provider: 'nokia_network_as_code', api: 'device-status/device-reachability-status/v1', source: 'live_camara', status: 'ok', checkedAt: '2026-09-12T12:00:00Z', result: { dataReachable: true } },
        { sequence: 2, tool: 'congestion_insights', reason: 'The zone is critical, so check comms quality.', responderId: null, provider: 'aman_venue_simulation', api: 'network-insights/congestion-insights/v0', source: 'simulated_fixture', status: 'ok', checkedAt: '2026-09-12T12:00:00Z', result: { congestionLevel: 'high' } },
      ],
    };
    const incident = { id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', responder_id: 'r1', decision: { candidates: [{ responderId: 'r1', distanceMeters: 42 }], adviceStatus: 'ready', advice } };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders: [{ id: 'r1', name: 'Concourse Marshal', available: true, signals: {} }], incidents: [incident] }} />);
    expect(html).toContain('2 network checks');
    expect(html).toContain('Device Reachability Status');
    expect(html).toContain('Congestion Insights');
    expect(html).toContain('Mobile data confirmed');
    expect(html).toContain('Congestion: HIGH');
    // Mixed provenance must never read as fully live.
    expect(html).toContain('1 of 2 live');
    expect(html).not.toContain('&gt;live network&lt;');
    // The agent runtime is named for the operator; the internal model name is
    // still not exposed, as elsewhere in the brief.
    expect(html).toContain('openai-agents-python v2.0.0');
    expect(html).not.toContain('internal-model-name');
  });

  it('flags a degraded agent run instead of implying a recommendation', () => {
    const advice = {
      summary: 'No AI recommendation was issued because trusted evidence was incomplete.',
      urgency: 'critical', confidence: 'low', proposedAction: 'Choose a responder manually.',
      recommendedResponderId: null, evidence: ['CAMARA evidence was unavailable.'], uncertainties: [],
      agentStatus: 'degraded', evidenceMode: 'none', toolTrace: [], generatedAt: '2026-09-12T12:00:00Z',
    };
    const incident = { id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', responder_id: 'r1', decision: { candidates: [{ responderId: 'r1', distanceMeters: 42 }], adviceStatus: 'ready', advice } };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders: [{ id: 'r1', name: 'Concourse Marshal', available: true, signals: {} }], incidents: [incident] }} />);
    expect(html).toContain('No AI recommendation');
    expect(html).toContain('brief-degraded');
    expect(html).not.toContain('network checks');
    // The operator still gets the deterministic option to approve.
    expect(html).toContain('Dispatch selected responder');
  });

  it('overlays located responders and awaiting incidents on the map', () => {
    const responder = { id: 'r1', name: 'Concourse Marshal', role: 'crowd_marshal', available: true, signals: { location: { latitude: 33.9005, longitude: 35.5005, accuracyMeters: 1 }, reachability: { dataReachable: true } } };
    const incident = { id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval', responder_id: 'r1', decision: { candidates: [{ responderId: 'r1', distanceMeters: 42 }] } };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders: [responder], incidents: [incident] }} />);
    expect(html).toContain('map-pin');
    expect(html).toContain('map-incident');
    expect(html).toContain('map-link');
    expect(html).toContain('1 incident awaiting dispatch approval');
    expect(html).toContain('Review East Entrance');
    expect(html).not.toContain('NaN');
  });

  it('leaves the map clean when a responder has no fix and no incident is open', () => {
    const responder = { id: 'r1', name: 'Concourse Marshal', available: true, signals: { location: { accuracyMeters: 1 } } };
    const html = renderToStaticMarkup(<OperatorPanel data={{ ...base, responders: [responder] }} />);
    expect(html).not.toContain('map-pin');
    expect(html).not.toContain('awaiting dispatch approval</strong>');
    expect(html).toContain('0 responders located');
  });

  it('projects boundaries within the view and handles missing geometry', () => {
    expect(projectZones([])).toEqual([]);
    expect(projectZones([{ id: 'bad' }])).toEqual([]);
    const [shape] = projectZones([zone]);
    expect(shape.x).toBeGreaterThan(0);
    expect(shape.x).toBeLessThan(720);
    expect(shape.y).toBeGreaterThan(0);
    expect(shape.y).toBeLessThan(350);
    expect(shape.points).not.toContain('NaN');
  });
});
