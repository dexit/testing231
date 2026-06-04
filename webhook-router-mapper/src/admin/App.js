import { TabPanel } from '@wordpress/components';
import RoutesPage from './pages/RoutesPage';
import CapturesPage from './pages/CapturesPage';
import JobsPage from './pages/JobsPage';
import LogsPage from './pages/LogsPage';

export default function App() {
  const initialTab = window.wrmAdminData?.initialTab || 'routes';
  const tabs = [
    { name: 'routes',   title: 'Routes',   className: 'tab-routes' },
    { name: 'captures', title: 'Captures', className: 'tab-captures' },
    { name: 'jobs',     title: 'Jobs',     className: 'tab-jobs' },
    { name: 'logs',     title: 'Logs',     className: 'tab-logs' },
  ];
  return (
    <div className="wrm-admin-app">
      <h1 className="wp-heading-inline">Webhook Router &amp; Mapper</h1>
      <TabPanel tabs={tabs} initialTabName={initialTab}>
        {(tab) => {
          if (tab.name === 'routes')   return <RoutesPage />;
          if (tab.name === 'captures') return <CapturesPage />;
          if (tab.name === 'jobs')     return <JobsPage />;
          if (tab.name === 'logs')     return <LogsPage />;
          return null;
        }}
      </TabPanel>
    </div>
  );
}
