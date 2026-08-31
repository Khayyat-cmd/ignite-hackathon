import { Component } from 'react';

export class ErrorBoundary extends Component {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch() {}
  render() {
    if (this.state.failed) return <main><h1>Dashboard error</h1><p>No action was retried. Reload to reconnect to the backend.</p><button onClick={() => location.reload()}>Reload</button></main>;
    return this.props.children;
  }
}
