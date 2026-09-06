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
    expect(html).toContain('Location ±1 m');
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
