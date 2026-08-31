import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { ZoneCard } from './ZoneCard';
import { IncidentCard } from './IncidentCard';
import { RespondersPanel } from './RespondersPanel';

const zone = {
  id: 'zone-1', name: 'DEMO - East Entrance', area_sqm: 1000, critical_density: 2,
  risk_level: 'unknown', dataFreshness: 'missing', latest_reading: null,
  last_observed_at: null, scenario: null, population_context: null, boundary: [],
};

describe('operations components', () => {
  it('handles a zone before its first reading', () => {
    const html = renderToStaticMarkup(createElement(ZoneCard, {
      zone, busy: false, onScenario() {}, onPopulation() {},
    }));
    expect(html).toContain('East Entrance');
    expect(html).toContain('Unknown');
    expect(html).toContain('Scenario paused');
  });
  it('requires review before approval', () => {
    const incident = { id: 'i-1', zone_id: zone.id, status: 'awaiting_approval', responder_id: null, source: 'demo', created_at: new Date().toISOString(), decision: null };
    const html = renderToStaticMarkup(createElement(IncidentCard, { incident, zone, responders: [], busy: false, onAction() {} }));
    expect(html).toContain('I reviewed the simulated route');
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*>Approve assignment/);
  });
  it('shows a safe empty responder state', () => {
    const html = renderToStaticMarkup(createElement(RespondersPanel, { responders: [], zones: [], busy: false, onRefresh() {} }));
    expect(html).toContain('Prepare the stadium');
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*>Refresh Nokia/);
  });
});
