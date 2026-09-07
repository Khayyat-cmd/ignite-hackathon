import { useMemo } from 'react';
import { useI18n } from '../i18n';
import { riskClass } from './Shared';

const VIEW_W = 720;
const VIEW_H = 350;
const PAD = 16;

const finite = (value) => Number.isFinite(Number(value));
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

// One projection for the whole canvas: zone polygons, responder pins, and
// incident markers must share a frame or the overlay lies about geography.
export function buildProjection(zones) {
  const valid = (zones || []).filter((zone) => Array.isArray(zone.boundary) && zone.boundary.length >= 3 && zone.boundary.every((p) => finite(p.latitude) && finite(p.longitude)));
  if (!valid.length) return null;
  const points = valid.flatMap((zone) => zone.boundary);
  const latitude = Number(points[0].latitude);
  const longitude = Number(points[0].longitude);
  const flatten = (p) => [(Number(p.longitude) - longitude) * Math.cos(latitude * Math.PI / 180), latitude - Number(p.latitude)];
  const projected = points.map(flatten);
  const left = Math.min(...projected.map((p) => p[0]));
  const top = Math.min(...projected.map((p) => p[1]));
  const width = Math.max(...projected.map((p) => p[0])) - left;
  const height = Math.max(...projected.map((p) => p[1])) - top;
  const scale = Math.min(640 / (width || 1), 250 / (height || 1));
  const toCanvas = (point) => {
    const [x, y] = flatten(point);
    return [(x - left - width / 2) * scale + 360, (y - top - height / 2) * scale + 175];
  };
  const shapes = valid.map((zone) => {
    const polygon = zone.boundary.map(toCanvas);
    return {
      zone,
      points: polygon.map((p) => p.join(',')).join(' '),
      x: polygon.reduce((sum, p) => sum + p[0], 0) / polygon.length,
      y: polygon.reduce((sum, p) => sum + p[1], 0) / polygon.length,
      bounds: {
        minX: Math.min(...polygon.map((p) => p[0])),
        minY: Math.min(...polygon.map((p) => p[1])),
        maxX: Math.max(...polygon.map((p) => p[0])),
        maxY: Math.max(...polygon.map((p) => p[1])),
      },
    };
  });
  return { toCanvas, shapes };
}

export function projectZones(zones) {
  return buildProjection(zones)?.shapes ?? [];
}

export default function VenueMap({
  zones,
  stale,
  selectedId,
  onSelect,
  responders = [],
  incidents = [],
  focusedIncidentId,
  focusedResponderId,
  linkedZoneId,
  onSelectResponder,
}) {
  const { t, n, term } = useI18n();
  const projection = useMemo(() => buildProjection(zones), [zones]);
  const shapes = projection?.shapes ?? [];
  const centres = useMemo(() => {
    const map = {};
    for (const shape of shapes) map[shape.zone.id] = shape;
    return map;
  }, [shapes]);

  const pins = useMemo(() => {
    if (!projection) return [];
    return responders.map((responder, index) => {
      const location = responder.signals?.location;
      if (!location || !finite(location.latitude) || !finite(location.longitude)) return null;
      const [x, y] = projection.toCanvas(location);
      if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
      return {
        responder,
        tag: `R${String(index + 1).padStart(2, '0')}`,
        x: clamp(x, PAD, VIEW_W - PAD),
        y: clamp(y, PAD, VIEW_H - 24),
      };
    }).filter(Boolean);
  }, [projection, responders]);

  // Markers sit in the zone's top-right corner rather than its centre, so the
  // headcount underneath the zone name stays readable.
  const markers = useMemo(() => {
    const perZone = {};
    return incidents
      .filter((incident) => incident.active_zone_id && incident.status !== 'resolved' && centres[incident.zone_id])
      .map((incident) => {
        const { bounds } = centres[incident.zone_id];
        const index = perZone[incident.zone_id] = (perZone[incident.zone_id] ?? -1) + 1;
        return {
          incident,
          x: clamp(bounds.maxX - 13 - index * 24, PAD, VIEW_W - PAD),
          y: clamp(bounds.minY + 15, PAD, VIEW_H - PAD),
        };
      });
  }, [incidents, centres]);

  const focusedMarker = markers.find((marker) => marker.incident.id === focusedIncidentId) || null;
  const focusedPin = pins.find((pin) => pin.responder.id === focusedResponderId) || null;
  const link = focusedMarker && focusedPin ? { from: focusedPin, to: focusedMarker } : null;
  const linkSpan = link ? Math.hypot(link.to.x - link.from.x, link.to.y - link.from.y) : 0;
  const linkDistance = link && linkSpan > 54
    ? (focusedMarker.incident.decision?.candidates || []).find((item) => item.responderId === focusedPin.responder.id)?.distanceMeters
    : null;
  // Sit the distance clear of the line it measures, on the line's normal.
  const linkLabel = linkDistance == null ? null : {
    x: (link.from.x + link.to.x) / 2 - ((link.to.y - link.from.y) / linkSpan) * 11,
    y: (link.from.y + link.to.y) / 2 + ((link.to.x - link.from.x) / linkSpan) * 11 + 3.5,
  };
  const assignments = useMemo(() => {
    const map = {};
    for (const incident of incidents) {
      const id = incident.assigned_responder_id;
      if (id && incident.active_zone_id && incident.status !== 'resolved') map[id] = incident;
    }
    return map;
  }, [incidents]);

  return <section className="map-section">
    <div className="pane-head">{t('map.head')}<span className="meta">{t('map.meta', { zones: n(zones.length), responders: n(pins.length) })}</span></div>
    <div className="map-canvas">
      {shapes.length ? <svg viewBox={`0 0 ${VIEW_W} ${VIEW_H}`} role="group" aria-label={t('map.aria')}>
        {shapes.map(({ zone, points, bounds }) => <g
          key={zone.id}
          role="button"
          tabIndex={0}
          aria-label={t('map.zoneAria', { name: zone.name, state: stale ? t('map.outdated') : term(zone.risk_level) })}
          aria-pressed={selectedId === zone.id}
          onClick={() => onSelect?.(zone.id)}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect?.(zone.id); } }}
          className={`map-zone ${riskClass(zone.risk_level, stale)}${selectedId === zone.id ? ' map-selected' : ''}${linkedZoneId === zone.id ? ' map-linked' : ''}`}
        >
          <polygon points={points} />
          <text x={bounds.minX} y={Math.max(11, bounds.minY - 7)}>
            {zone.name}
            <tspan className="map-count" dx="8">{zone.latest_reading?.deviceCount == null ? '—' : n(zone.latest_reading.deviceCount)}</tspan>
          </text>
        </g>)}

        {link && <g className="map-link" aria-hidden="true">
          <line x1={link.from.x} y1={link.from.y} x2={link.to.x} y2={link.to.y} />
          {linkLabel && <text x={linkLabel.x} y={linkLabel.y} textAnchor="middle">{t('map.meters', { count: linkDistance })}</text>}
        </g>}

        {markers.map(({ incident, x, y }) => <g
          key={incident.id}
          className={`map-incident${focusedIncidentId === incident.id ? ' map-focus' : ''}${incident.status === 'awaiting_approval' ? ' map-urgent' : ''}`}
          aria-hidden="true"
        >
          <circle className="map-incident-pulse" cx={x} cy={y} r="13" />
          <circle className="map-incident-dot" cx={x} cy={y} r="8" />
          <text x={x} y={y + 4} textAnchor="middle">!</text>
        </g>)}

        {pins.map(({ responder, tag, x, y }) => {
          const assignment = assignments[responder.id];
          return <g
            key={responder.id}
            role="button"
            tabIndex={0}
            aria-label={t('map.responderAria', { name: responder.name, state: assignment ? t('responder.assigned') : t('responder.available') })}
            aria-pressed={focusedResponderId === responder.id}
            onClick={() => onSelectResponder?.(responder.id)}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelectResponder?.(responder.id); } }}
            className={`map-pin${assignment ? ' map-pin-assigned' : ''}${focusedResponderId === responder.id ? ' map-focus' : ''}`}
          >
            <circle className="map-pin-knockout" cx={x} cy={y} r="11.5" />
            <circle cx={x} cy={y} r="9" />
            <text x={x} y={y + 3.5} textAnchor="middle">{tag}</text>
            {focusedResponderId === responder.id
              && <text className="map-pin-name" x={x} y={y + 23} textAnchor="middle">{responder.name}</text>}
          </g>;
        })}
      </svg> : <p className="map-empty">{t('map.empty')}</p>}
      <span className="map-north">{t('map.north')}</span>
      <span className="map-caption">{t('map.caption')}</span>
    </div>
    <div className="map-legend">
      <span><i className="dot normal" />{t('map.legend.normal')}</span>
      <span><i className="dot warning" />{t('map.legend.elevated')}</span>
      <span><i className="dot critical" />{t('map.legend.critical')}</span>
      <span><i className="dot" />{t('map.legend.uncertain')}</span>
      <span className="legend-end"><i className="glyph glyph-incident">!</i>{t('map.legend.incident')}</span>
      <span><i className="glyph glyph-pin" />{t('map.legend.available')}</span>
      <span><i className="glyph glyph-pin assigned" />{t('map.legend.assigned')}</span>
    </div>
  </section>;
}
