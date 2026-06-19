import { TabPanel } from '@wordpress/components';
import { Component } from '@wordpress/element';
import DashboardPage from './pages/DashboardPage';
import RoutesPage from './pages/RoutesPage';
import CapturesPage from './pages/CapturesPage';
import JobsPage from './pages/JobsPage';
import LogsPage from './pages/LogsPage';
import SchedulesPage from './pages/SchedulesPage';
import FunctionsPage from './pages/FunctionsPage';
import MessagesPage from './pages/MessagesPage';

class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }
  render() {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 24, border: '2px solid #d63638', borderRadius: 6, background: '#fde8e8', color: '#7a0000', margin: 16 }}>
          <strong>Something went wrong in the admin app.</strong>
          <p style={{ fontSize: 13, margin: '8px 0 0', fontFamily: 'monospace', wordBreak: 'break-all' }}>
            {this.state.error?.message || 'Unknown error'}
          </p>
          <button
            onClick={() => this.setState({ hasError: false, error: null })}
            style={{ marginTop: 12, cursor: 'pointer', padding: '4px 12px', borderRadius: 4, border: '1px solid #d63638', background: '#fff', color: '#d63638', fontWeight: 600 }}
          >
            Try again
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}

export default function App() {
  const initialTab = window.wrmAdminData?.initialTab || 'dashboard';
  const tabs = [
    { name: 'dashboard', title: 'Dashboard', className: 'tab-dashboard' },
    { name: 'routes',    title: 'Routes',    className: 'tab-routes' },
    { name: 'captures',  title: 'Captures',  className: 'tab-captures' },
    { name: 'jobs',      title: 'Jobs',      className: 'tab-jobs' },
    { name: 'schedules', title: 'Schedules', className: 'tab-schedules' },
    { name: 'messages',  title: 'Messages',  className: 'tab-messages' },
    { name: 'functions', title: 'Functions', className: 'tab-functions' },
    { name: 'logs',      title: 'Logs',      className: 'tab-logs' },
  ];
  return (
    <ErrorBoundary>
      <div className="wrm-admin-app">
        <h1 className="wp-heading-inline">Webhook Router &amp; Mapper</h1>
        <TabPanel tabs={tabs} initialTabName={initialTab}>
          {(tab) => (
            <ErrorBoundary key={tab.name}>
              {tab.name === 'dashboard' ? <DashboardPage /> :
               tab.name === 'routes'    ? <RoutesPage /> :
               tab.name === 'captures'  ? <CapturesPage /> :
               tab.name === 'jobs'      ? <JobsPage /> :
               tab.name === 'schedules' ? <SchedulesPage /> :
               tab.name === 'messages'  ? <MessagesPage /> :
               tab.name === 'functions' ? <FunctionsPage /> :
               tab.name === 'logs'      ? <LogsPage /> : null}
            </ErrorBoundary>
          )}
        </TabPanel>
      </div>
    </ErrorBoundary>
  );
}
