import { Component } from 'react';
import { I18nContext } from '../i18n';

export class ErrorBoundary extends Component {
  static contextType = I18nContext;
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch() {}
  render() {
    if (this.state.failed) {
      const { t } = this.context;
      return <main className="crash">
        <h1>{t('crash.title')}</h1>
        <p className="hint">{t('crash.hint')}</p>
        <div><button className="btn" onClick={() => location.reload()}>{t('crash.reload')}</button></div>
      </main>;
    }
    return this.props.children;
  }
}
