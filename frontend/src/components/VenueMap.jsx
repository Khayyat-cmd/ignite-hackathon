import { useMemo } from 'react';
import { riskClass } from './Shared';

export function projectZones(zones) {
  const valid = zones.filter((zone) => Array.isArray(zone.boundary) && zone.boundary.length >= 3 && zone.boundary.every((p) => Number.isFinite(Number(p.latitude)) && Number.isFinite(Number(p.longitude))));
  if (!valid.length) return [];
  const points = valid.flatMap((zone) => zone.boundary);
  const latitude = Number(points[0].latitude);
  const longitude = Number(points[0].longitude);
  const project = (p) => [(Number(p.longitude) - longitude) * Math.cos(latitude * Math.PI / 180), latitude - Number(p.latitude)];
  const projected = points.map(project);
  const left = Math.min(...projected.map((p) => p[0]));
  const top = Math.min(...projected.map((p) => p[1]));
  const width = Math.max(...projected.map((p) => p[0])) - left;
  const height = Math.max(...projected.map((p) => p[1])) - top;
  const scale = Math.min(640 / (width || 1), 250 / (height || 1));
  return valid.map((zone) => {
    const polygon = zone.boundary.map(project).map(([x, y]) => [(x - left - width / 2) * scale + 360, (y - top - height / 2) * scale + 175]);
    return { zone, points: polygon.map((p) => p.join(',')).join(' '), x: polygon.reduce((sum, p) => sum + p[0], 0) / polygon.length, y: polygon.reduce((sum, p) => sum + p[1], 0) / polygon.length };
  });
}

export default function VenueMap({ zones, stale, selectedId, onSelect }) {
  const shapes = useMemo(() => projectZones(zones), [zones]);
  return <section className="map-section">
    <div className="pane-head">Venue<span className="meta">{zones.length} monitored zones</span></div>
    <div className="map-canvas">
      {shapes.length ? <svg viewBox="0 0 720 350" role="group" aria-label="Interactive zone boundary map">
        {shapes.map(({ zone, points, x, y }) => <g
          key={zone.id}
          role="button"
          tabIndex={0}
          aria-label={`${zone.name}: ${stale ? 'outdated' : zone.risk_level}`}
          aria-pressed={selectedId === zone.id}
          onClick={() => onSelect?.(zone.id)}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect?.(zone.id); } }}
          className={`map-zone ${riskClass(zone.risk_level, stale)}${selectedId === zone.id ? ' map-selected' : ''}`}
        >
          <polygon points={points} />
          <text x={x} y={y - 4} textAnchor="middle">{zone.name}</text>
          <text className="map-count" x={x} y={y + 15} textAnchor="middle">{zone.latest_reading?.deviceCount?.toLocaleString() ?? '—'}</text>
        </g>)}
      </svg> : <p className="map-empty">Zone geometry is not available for this event.</p>}
      <span className="map-north">N ↑</span>
      <span className="map-caption">ZONE SCHEMATIC · NOT A NAVIGATION MAP</span>
    </div>
    <div className="map-legend">
      <span><i className="dot normal" />Normal</span>
      <span><i className="dot warning" />Elevated</span>
      <span><i className="dot critical" />Critical</span>
      <span><i className="dot" />Uncertain / outdated</span>
    </div>
  </section>;
}
