import { number, time } from '../utils/format';
import { Status } from './Status';

export function ZoneCard({ zone, busy, onScenario, onPopulation }) {
  const context = zone.population_context;
  return <article className="card">
    <div className="row">
      <h3>{zone.name.replace('DEMO - ', '')}</h3>
      <Status value={zone.dataFreshness === 'fresh' ? zone.risk_level : zone.dataFreshness}
        tone={zone.dataFreshness === 'fresh' ? zone.risk_level : 'muted'} />
    </div>
    <div className="metrics">
      <div><strong>{number(zone.latest_reading?.deviceCount)}</strong><span>simulated devices</span></div>
      <div><strong>{number(zone.latest_reading?.densityPerSquareMeter, 2)}</strong><span>estimated people / m²</span></div>
    </div>
    <p className="small">Area {number(zone.area_sqm)} m² · Critical threshold {number(zone.critical_density, 2)} / m² (demo only)</p>
    <p className="small">Reading {time(zone.last_observed_at)} · {zone.scenario?.active ? `Scenario: ${zone.scenario.action}` : 'Scenario paused'}</p>
    <div className="buttons">
      <button disabled={busy} onClick={() => onScenario('crowded')}>Increase crowd</button>
      <button disabled={busy} onClick={() => onScenario('recover')}>Reduce crowd</button>
      <button disabled={busy || !zone.scenario?.active} onClick={() => onScenario('pause')}>Pause</button>
      <button disabled={busy || Boolean(zone.scenario?.active)} onClick={() => onScenario('calm')}>Resume calm</button>
    </div>
    <details>
      <summary>Orange context · {context?.state || 'not requested'}</summary>
      <p className="small">Mocked area estimate. It does not trigger crowd danger.</p>
      {context && <p className="small">Checked {time(context.checkedAt)} · {context.result?.status || context.error}</p>}
      <button disabled={busy} onClick={onPopulation}>Refresh Orange</button>
      {context?.result && <pre>{JSON.stringify(context.result, null, 2)}</pre>}
    </details>
    <details><summary>Zone boundary and ID</summary><p className="small">{zone.id}</p><pre>{JSON.stringify(zone.boundary, null, 2)}</pre></details>
  </article>;
}
