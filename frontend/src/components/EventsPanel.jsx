import { time, words } from '../utils/format';

export function EventsPanel({ events }) {
  return <section><h2>Recent backend events</h2><p className="small">Latest 25 events received by polling; no Unity or WebSocket connection is required.</p>
    {!events.length ? <p className="empty">Events will appear after the demo starts.</p> : <div className="card event-list">{events.map((event) =>
      <details key={event.eventId}><summary><time>{time(event.occurredAt)}</time>{words(event.event)} <span className="small">#{event.sequence}</span></summary><pre>{JSON.stringify(event, null, 2)}</pre></details>)}</div>}
  </section>;
}
