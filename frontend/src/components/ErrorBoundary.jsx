import { Component } from 'react';

export class ErrorBoundary extends Component {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch() {}
  render() {
    if (this.state.failed) {
      return <main className="crash">
        <h1>Console error</h1>
        <p className="hint">No action was retried. Reload to reconnect to the backend.</p>
        <div><button className="btn" onClick={() => location.reload()}>Reload console</button></div>
      </main>;
    }
    return this.props.children;
  }
}
