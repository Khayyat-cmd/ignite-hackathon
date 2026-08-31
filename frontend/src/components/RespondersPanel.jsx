import { number, responderReachability, time, words } from '../utils/format';
import { Status } from './Status';

export function RespondersPanel({ responders, zones, busy, onRefresh }) {
  return <section>
    <div className="section-heading"><h2>Responders & Nokia evidence</h2><button disabled={busy || !responders.length} onClick={onRefresh}>Refresh Nokia</button></div>
    <p className="small">Nokia reachability controls eligibility. Distance uses clearly simulated stadium positions.</p>
    {!responders.length && <p className="empty">Prepare the stadium to create responders.</p>}
    <div className="grid two">{responders.map((responder) => <article className="card" key={responder.id}>
      <div className="row"><h3>{responder.name}</h3><Status value={responder.available ? 'available' : 'assigned'} /></div>
      <p><Status value={responderReachability(responder)}
        tone={responder.networkFreshness === 'fresh' && responder.signals?.reachability?.dataReachable ? 'normal' : 'muted'} /></p>
      <p className="small">Simulated position: {zones.find((zone) => zone.id === responder.demo_position?.zoneId)?.name || 'Not set'}</p>
      <p className="small">Nokia check: {time(responder.signals?.checkedAt)} · {responder.networkFreshness || 'missing'}</p>
      <p className="small">Network congestion: {responder.signals?.congestion?.map((item) => item.congestionLevel).join(', ') || 'Unknown'}</p>
      <p className="small">Verification: {responder.signals?.verification?.verificationResult || 'Not available'} · broad demo area only</p>
      {responder.signals?.location && <p className="small">Nokia location uncertainty: {number(responder.signals.location.accuracyMeters)} m radius</p>}
      {responder.signals?.errors && <p className="inline-error">{Object.entries(responder.signals.errors).map(([key, value]) => `${words(key)}: ${words(value)}`).join(' · ')}</p>}
      <details><summary>Raw Nokia response</summary><pre>{JSON.stringify(responder.signals, null, 2)}</pre></details>
    </article>)}</div>
  </section>;
}
