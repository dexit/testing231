import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useState, useEffect, useRef } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';

const CHAIN_ICONS = {
  cpt: '📄',
  webhook: '🔗',
  dispatcher: '📡',
  email: '✉',
  sms: '💬',
  function: '🔧',
  hook: '🪝',
  mapping: '🔀',
};

const REFRESH_SEC = 30;

function formatApiError(e) {
  const msg = e?.message || '';
  if (e?.code === 'rest_forbidden' || /forbidden|not allowed|403/i.test(msg)) {
    return 'Access denied — you need administrator (manage_options) capability to view this.';
  }
  return msg || 'Failed to load dashboard';
}

export default function DashboardPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [countdown, setCountdown] = useState(REFRESH_SEC);
  const intervalRef = useRef(null);
  const countRef = useRef(null);

  const load = () => {
    setLoading(prev => prev || true);
    apiFetch({ path: '/wrm/v1/dashboard' })
      .then(d => { setData(d); setLoading(false); setError(null); setCountdown(REFRESH_SEC); })
      .catch(e => { setError(formatApiError(e)); setLoading(false); });
  };

  useEffect(() => {
    load();
    intervalRef.current = setInterval(load, REFRESH_SEC * 1000);
    countRef.current = setInterval(() => setCountdown(c => Math.max(0, c - 1)), 1000);
    return () => { clearInterval(intervalRef.current); clearInterval(countRef.current); };
  }, []);

  if (loading && !data) return <Spinner />;
  if (error && !data) {
    return (
      <div style={{ padding: '16px 0' }}>
        <Notice status="error" isDismissible={false}>{error}</Notice>
        <Button variant="secondary" onClick={load} style={{ marginTop: 12 }}>Retry</Button>
      </div>
    );
  }
  if (!data) return <Notice status="warning" isDismissible={false}>No data returned from server.</Notice>;

  const { stats = {}, pipelines = [], recent_failures = [], recent_errors = [] } = data;
  const jobs = stats.jobs || {};

  const statPills = [
    { label: 'Active Routes',   value: stats.active_routes ?? 0,   bg: '#d7f0e0', color: '#0a5227' },
    { label: 'Captures Today',  value: stats.captures_today ?? 0,   bg: '#e8f0fe', color: '#1a3a7a' },
    { label: 'Jobs Queued',     value: jobs.queued ?? 0,            bg: (jobs.queued ?? 0) > 0 ? '#fff0d9' : '#f0f0f0', color: (jobs.queued ?? 0) > 0 ? '#7a4400' : '#555' },
    { label: 'Failed Jobs',     value: jobs.failed ?? 0,            bg: (jobs.failed ?? 0) > 0 ? '#fde8e8' : '#f0f0f0', color: (jobs.failed ?? 0) > 0 ? '#7a0000' : '#555' },
    { label: 'Messages Today',  value: stats.messages_today ?? 0,   bg: '#f4e8ff', color: '#450070' },
    { label: 'Errors Today',    value: stats.errors_today ?? 0,     bg: (stats.errors_today ?? 0) > 0 ? '#fde8e8' : '#f0f0f0', color: (stats.errors_today ?? 0) > 0 ? '#7a0000' : '#555' },
  ];

  return (
    <div className="wrm-page wrm-dashboard-page">
      {error && <Notice status="warning" isDismissible={false} style={{ marginBottom: 12 }}>{error} (showing cached data)</Notice>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8, marginBottom: 8 }}>
        {loading && <Spinner />}
        <span style={{ fontSize: 12, color: '#888' }}>Auto-refresh in {countdown}s</span>
        <Button variant="link" isSmall onClick={load}>Refresh now</Button>
      </div>
      <div style={{ display: 'flex', gap: 10, marginBottom: 24, flexWrap: 'wrap' }}>
        {statPills.map(({ label, value, bg, color }) => (
          <div key={label} style={{ padding: '10px 18px', borderRadius: 8, background: bg, color, fontWeight: 700, fontSize: 13, minWidth: 100, textAlign: 'center' }}>
            <div style={{ fontSize: 26, lineHeight: 1.2 }}>{value}</div>
            <div style={{ fontSize: 11, textTransform: 'uppercase', marginTop: 3 }}>{label}</div>
          </div>
        ))}
      </div>

      <h2 style={{ fontSize: 15, marginBottom: 10 }}>Active Pipelines</h2>
      {pipelines.length === 0 ? (
        <p style={{ color: '#888' }}>No active pipelines.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 24 }}>
          {pipelines.map((p, i) => {
            const route = p.route || {};
            const routeLabel = [route.slug, route.methods, route.provider].filter(Boolean).join(' / ');
            return (
              <div
                key={i}
                style={{
                  display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap',
                  padding: '10px 16px', border: '1px solid #e2e4e7', borderRadius: 6,
                  background: '#fff', fontSize: 13,
                }}
              >
                <span style={{ fontFamily: 'monospace', fontWeight: 700, color: '#1d2327' }}>{routeLabel}</span>

                <span style={{ color: '#888' }}>──→</span>

                {p.mapping_title ? (
                  <span style={{ color: '#2271b1', fontWeight: 600 }}>{p.mapping_title}</span>
                ) : (
                  <em style={{ color: '#888' }}>(no mapping — capture only)</em>
                )}

                {p.mapping_title && Array.isArray(p.chain_types) && p.chain_types.length > 0 && (
                  <>
                    <span style={{ color: '#888' }}>──→</span>
                    <span style={{ display: 'flex', gap: 4 }}>
                      {p.chain_types.map((type, ti) => (
                        <span
                          key={ti}
                          title={type}
                          style={{
                            padding: '2px 8px', borderRadius: 12, background: '#f0f0f0',
                            color: '#333', fontSize: 12, fontWeight: 600,
                          }}
                        >
                          {CHAIN_ICONS[type] || '⚙'} {type}
                        </span>
                      ))}
                    </span>
                  </>
                )}

                <span style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 10 }}>
                  <StatusBadge status={route.status || 'active'} />
                  <span style={{ fontSize: 12, color: '#555' }}>{p.captures_today ?? 0} captures today</span>
                </span>
              </div>
            );
          })}
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
        <div>
          <h2 style={{ fontSize: 15, marginBottom: 10 }}>Recent Failures</h2>
          {recent_failures.length === 0 ? (
            <p style={{ color: '#888' }}>No recent job failures.</p>
          ) : (
            <table className="wp-list-table widefat striped" style={{ fontSize: 12 }}>
              <thead>
                <tr>
                  <th style={{ width: 50 }}>Job</th>
                  <th>Route</th>
                  <th>Error</th>
                  <th>Queued</th>
                </tr>
              </thead>
              <tbody>
                {recent_failures.map(f => (
                  <tr key={f.id}>
                    <td><code>{f.id}</code></td>
                    <td><code>{f.route_slug || '—'}</code></td>
                    <td style={{ color: '#d63638', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={f.error_message}>
                      {f.error_message || '—'}
                    </td>
                    <td style={{ whiteSpace: 'nowrap' }}>{f.queued_at || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div>
          <h2 style={{ fontSize: 15, marginBottom: 10 }}>Recent Errors</h2>
          {recent_errors.length === 0 ? (
            <p style={{ color: '#888' }}>No recent error logs.</p>
          ) : (
            <table className="wp-list-table widefat striped" style={{ fontSize: 12 }}>
              <thead>
                <tr>
                  <th style={{ width: 50 }}>ID</th>
                  <th>Context</th>
                  <th>Message</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
                {recent_errors.map(e => (
                  <tr key={e.id}>
                    <td><code>{e.id}</code></td>
                    <td style={{ fontSize: 11 }}>{e.context || '—'}</td>
                    <td style={{ color: '#d63638', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={e.message}>
                      {e.message || '—'}
                    </td>
                    <td style={{ whiteSpace: 'nowrap' }}>{e.created_at || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
