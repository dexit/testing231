import { TabPanel } from '@wordpress/components';
import DashboardPage from './pages/DashboardPage';
import RoutesPage from './pages/RoutesPage';
import CapturesPage from './pages/CapturesPage';
import JobsPage from './pages/JobsPage';
import LogsPage from './pages/LogsPage';
import SchedulesPage from './pages/SchedulesPage';
import FunctionsPage from './pages/FunctionsPage';
import MessagesPage from './pages/MessagesPage';

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
    <div className="wrm-admin-app">
      <h1 className="wp-heading-inline">Webhook Router &amp; Mapper</h1>
      <TabPanel tabs={tabs} initialTabName={initialTab}>
        {(tab) => {
          if (tab.name === 'dashboard') return <DashboardPage />;
          if (tab.name === 'routes')    return <RoutesPage />;
          if (tab.name === 'captures')  return <CapturesPage />;
          if (tab.name === 'jobs')      return <JobsPage />;
          if (tab.name === 'schedules') return <SchedulesPage />;
          if (tab.name === 'messages')  return <MessagesPage />;
          if (tab.name === 'functions') return <FunctionsPage />;
          if (tab.name === 'logs')      return <LogsPage />;
          return null;
        }}
      </TabPanel>
    </div>
  );
}
