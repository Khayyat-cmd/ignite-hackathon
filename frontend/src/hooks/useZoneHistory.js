import { useEffect, useRef, useState } from 'react';

const LIMIT = 24;
// Below this the reading is noise, not a direction an operator should act on.
const FLAT = 0.05;

// Keep a short client-side density series per zone. The backend snapshot is
// instantaneous; an operator decides on direction, not level.
export function useZoneHistory(zones = [], revision, observedAt) {
  const [series, setSeries] = useState({});
  const lastRevision = useRef(null);
  useEffect(() => {
    if (revision == null || lastRevision.current === revision) return;
    lastRevision.current = revision;
    const at = observedAt ? Date.parse(observedAt) : Date.now();
    if (!Number.isFinite(at)) return;
    setSeries((current) => {
      const next = {};
      for (const zone of zones) {
        const density = Number(zone.latest_reading?.densityPerSquareMeter);
        const previous = current[zone.id] || [];
        next[zone.id] = Number.isFinite(density)
          ? [...previous, { at, density }].slice(-LIMIT)
          : previous;
      }
      return next;
    });
  }, [zones, revision, observedAt]);
  return series;
}

export function trendOf(samples) {
  if (!samples || samples.length < 2) return null;
  const first = samples[0];
  const last = samples[samples.length - 1];
  const delta = last.density - first.density;
  return {
    samples,
    delta,
    seconds: Math.max(0, Math.round((last.at - first.at) / 1000)),
    direction: Math.abs(delta) < FLAT ? 'flat' : delta > 0 ? 'up' : 'down',
  };
}
