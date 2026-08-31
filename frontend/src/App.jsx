import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useOperations } from './hooks/useOperations';
import { ZoneCard } from './components/ZoneCard';
import { RespondersPanel } from './components/RespondersPanel';
import { IncidentCard } from './components/IncidentCard';
import { EventsPanel } from './components/EventsPanel';
import { Message } from './components/Status';
import { time } from './utils/format';

function Operations({ token, onDisconnect }) {
  const { query, run, post, busy, notice, actionError, automaticError, automaticRecommendations, setAutomaticRecommendations } = useOperations(token);
  const [showResolved, setShowResolved] = useState(false);
  const data = query.data;
  const disabled = busy || query.isError;

  async function prepare() {
    const setup = await post('/demo/setup');
    for (const zone of setup.zones) {
      if (!zone.scenario) await post(`/demo/zones/${zone.id}/scenario`, { action: 'calm' });
    }
    await post('/demo/network/refresh');
    for (const zone of setup.zones) await post(`/demo/zones/${zone.id}/population/refresh`);
  }

  const incidents = (data?.incidents || []).filter((incident) => showResolved || incident.status !== 'resolved');
  return <main>
    <header className="page-heading"><div><h1>AMAN <span>Operations test</span></h1><p>Prepare → increase crowd → approve response → acknowledge → recover → resolve.</p></div><button onClick={onDisconnect}>Disconnect</button></header>
    <p className="disclosure">Crowd counts and stadium positions are simulated. Nokia and Orange use official playground responses. No Unity or real message delivery.</p>
    <div className="toolbar">
      <button className="primary" disabled={disabled} onClick={() => run(prepare, 'Stadium prepared. Provider requests are queued; wait for updated evidence timestamps.')}>{busy ? 'Working…' : data?.zones.length ? 'Prepare / refresh demo' : 'Prepare stadium'}</button>
      <button disabled={disabled || !data?.zones.some((zone) => zone.scenario?.active)}
        onClick={() => run(() => Promise.all(data.zones.map((zone) => post(`/demo/zones/${zone.id}/scenario`, { action: 'pause' }))), 'All crowd scenarios paused.')}>Pause all</button>
      <button disabled={query.isFetching} onClick={() => query.refetch()}>Refresh</button>
      <span className="small">{query.isPending ? 'Loading…' : `Updated ${query.dataUpdatedAt ? time(new Date(query.dataUpdatedAt).toISOString()) : 'not yet'}`}</span>
    </div>
    {query.isError && <Message error>{query.error.message} {data && 'The last successful snapshot remains visible but may be outdated.'}</Message>}
    {actionError && <Message error>{actionError}</Message>}
    {automaticError && <Message error>Automatic recommendation: {automaticError}</Message>}
    {notice && <Message>{notice}</Message>}
    {data && (data.integrations.nokia.mode !== 'sandbox' || !data.integrations.nokia.credentialsConfigured) && <Message error>Nokia sandbox is not ready. The UI will not fabricate network results.</Message>}
    {data && (data.integrations.populationDensity.provider !== 'orange_playground' || !data.integrations.populationDensity.credentialsConfigured) && <Message error>Orange playground is not ready. Crowd scenarios still work independently.</Message>}

    <section><div className="section-heading"><h2>Zones</h2><span className="small">The scheduler updates active scenarios every 5 seconds.</span></div>
      {!data?.zones.length ? <p className="empty">{query.isPending ? 'Loading…' : 'Select Prepare stadium. Older local demo zones are intentionally hidden.'}</p> :
        <div className="grid three">{data.zones.map((zone) => <ZoneCard key={zone.id} zone={zone} busy={disabled}
          onScenario={(action) => run(() => post(`/demo/zones/${zone.id}/scenario`, { action }), `${zone.name}: ${action} requested.`)}
          onPopulation={() => run(() => post(`/demo/zones/${zone.id}/population/refresh`), 'Orange context requested.')} />)}</div>}
    </section>

    <section><div className="section-heading"><h2>Incidents</h2><label className="check"><input type="checkbox" checked={showResolved} onChange={(event) => setShowResolved(event.target.checked)} /> Include resolved</label></div>
      <label className="check"><input type="checkbox" checked={automaticRecommendations} onChange={(event) => setAutomaticRecommendations(event.target.checked)} /> Automatically request a recommendation after danger is detected</label>
      <p className="small">Detection and recommendation can be automatic. Approval and resolution remain operator decisions.</p>
      {!incidents.length ? <p className="empty">No active incident. Increase a zone's crowd.</p> :
        <div className="grid two">{incidents.map((incident) => <IncidentCard key={incident.id} incident={incident}
          zone={data?.zones.find((zone) => zone.id === incident.zone_id)} responders={data?.responders || []} busy={disabled}
          onAction={(action, body) => run(() => post(`/incidents/${incident.id}/${action}`, body), `Incident action completed: ${action}.`)} />)}</div>}
    </section>

    <RespondersPanel responders={data?.responders || []} zones={data?.zones || []} busy={disabled}
      onRefresh={() => run(() => post('/demo/network/refresh'), 'Nokia refresh queued. A queued response is not proof of provider success.')} />
    <EventsPanel events={data?.events || []} />
  </main>;
}

export default function App() {
  const [token, setToken] = useState(() => sessionStorage.getItem('aman_operator_token') || '');
  const [input, setInput] = useState('');
  const queryClient = useQueryClient();
  const connect = (event) => {
    event.preventDefault();
    const value = input.trim();
    if (!value) return;
    sessionStorage.setItem('aman_operator_token', value);
    setToken(value);
    setInput('');
  };
  const disconnect = () => {
    queryClient.clear();
    sessionStorage.removeItem('aman_operator_token');
    setToken('');
  };
  if (token) return <Operations token={token} onDisconnect={disconnect} />;
  return <main className="connect-page">
    <h1>AMAN <span>Operations test</span></h1>
    <p>Connect this standalone React app to the Laravel backend.</p>
    <section className="card connect">
      <h2>Operator access</h2>
      <p>Paste a backend token with <code>read,operate</code> permissions. It is stored only for this browser tab.</p>
      <form onSubmit={connect}><label className="field">Bearer token<input type="password" autoComplete="off" value={input} onChange={(event) => setInput(event.target.value)} /></label><button className="primary" disabled={!input.trim()}>Connect</button></form>
      <p className="small">After connecting, the complete demo flow is controlled through buttons; Postman is not needed.</p>
    </section>
  </main>;
}
