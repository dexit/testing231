import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, TextControl, Notice, Spinner, Modal } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';
import JsonTree from '../components/JsonTree';

const LOG_LEVELS = ['debug', 'info', 'warning', 'error', 'exception'];

const LEVEL_STYLES = {
  debug:     { color: '#888', bg: '#f0f0f0' },
  info:      { color: '#2271b1', bg: '#e8f0fe' },
  warning:   { color: '#996800', bg: '#fdeec1' },
  error:     { color: '#d63638', bg: '#fde8e8' },
  exception: { color: '#7a0000', bg: '#fde8e8' },
};

const PER_PAGE = 30;

export default function LogsPage() {
  const [logs, setLogs] = useState([]);
  const [contexts, setContexts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  // Filters
  const [filterLevel, setFilterLevel] = useState('');
  const [filterContext, setFilterContext] = useState('');
  const [search, setSearch] = useState('');

  // Pagination
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);

  // Expanded data
  const [expandedId, setExpandedId] = useState(null);

  // Purge confirm
  const [showPurgeConfirm, setShowPurgeConfirm] = useState(false);
  const [purging, setPurging] = useState(false);

  const loadLogs = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    params.set('per_page', PER_PAGE);
    params.set('page', page);
    if (filterLevel) params.set('level', filterLevel);
    if (filterContext) params.set('context', filterContext);
    if (search) params.set('search', search);

    apiFetch({ path: `/wrm/v1/logs?${params.toString()}` })
      .then(data => {
        const list = Array.isArray(data) ? data : (data.items || []);
        const tot = data.total !== undefined ? data.total : list.length;
        setLogs(list);
        setTotal(tot);

        // Extract unique contexts
        const allContexts = list.map(l => l.context).filter(Boolean);
        setContexts(prev => {
          const all = [...new Set([...prev, ...allContexts])];
          return all;
        });

        setLoading(false);
      })
      .catch(e => { setError(e.message || 'Failed to load logs'); setLoading(false); });
  }, [page, filterLevel, filterContext, search]);

  useEffect(() => { loadLogs(); }, [loadLogs]);

  const showNotice = (msg, type = 'success') => {
    setNotice({ msg, type });
    setTimeout(() => setNotice(null), 4000);
  };

  const handlePurge = () => {
    setPurging(true);
    apiFetch({ path: '/wrm/v1/logs', method: 'DELETE' })
      .then(() => {
        setPurging(false);
        setShowPurgeConfirm(false);
        showNotice('All logs purged.');
        setPage(1);
        loadLogs();
      })
      .catch(e => {
        setPurging(false);
        setShowPurgeConfirm(false);
        showNotice(e.message || 'Purge failed', 'error');
      });
  };

  const contextOptions = [
    { label: 'All Contexts', value: '' },
    ...contexts.map(c => ({ label: c, value: c })),
  ];

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="wrm-page wrm-logs-page">
      {notice && (
        <Notice status={notice.type} onRemove={() => setNotice(null)} isDismissible>
          {notice.msg}
        </Notice>
      )}

      {/* Level filter tabs */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
        <button
          onClick={() => { setFilterLevel(''); setPage(1); }}
          style={{
            padding: '4px 12px', borderRadius: 20, border: '2px solid #ccc',
            background: filterLevel === '' ? '#333' : '#f0f0f0',
            color: filterLevel === '' ? '#fff' : '#333',
            cursor: 'pointer', fontWeight: 600, fontSize: 12,
          }}
        >
          All
        </button>
        {LOG_LEVELS.map(level => {
          const s = LEVEL_STYLES[level] || {};
          const active = filterLevel === level;
          return (
            <button
              key={level}
              onClick={() => { setFilterLevel(level); setPage(1); }}
              style={{
                padding: '4px 12px', borderRadius: 20, fontWeight: 700, fontSize: 12,
                border: `2px solid ${s.color || '#ccc'}`,
                background: active ? s.color : s.bg,
                color: active ? '#fff' : s.color,
                cursor: 'pointer', textTransform: 'uppercase',
              }}
            >
              {level}
            </button>
          );
        })}
      </div>

      {/* Context filter + controls */}
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-end', marginBottom: 12, flexWrap: 'wrap' }}>
        <SelectControl
          label="Context"
          value={filterContext}
          options={contextOptions}
          onChange={v => { setFilterContext(v); setPage(1); }}
        />
        <TextControl
          label="Search"
          value={search}
          onChange={v => { setSearch(v); setPage(1); }}
          placeholder="message text"
        />
        <Button variant="secondary" onClick={loadLogs}>Refresh</Button>
        <Button variant="secondary" isDestructive onClick={() => setShowPurgeConfirm(true)}>
          Purge All Logs
        </Button>
      </div>

      {loading ? (
        <Spinner />
      ) : error ? (
        <Notice status="error" isDismissible={false}>{error}</Notice>
      ) : (
        <>
          <table className="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th style={{ width: 60 }}>ID</th>
                <th style={{ width: 90 }}>Level</th>
                <th style={{ width: 120 }}>Context</th>
                <th style={{ width: 80 }}>Ref ID</th>
                <th>Message</th>
                <th style={{ width: 70 }}>Data</th>
                <th style={{ width: 140 }}>Time</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 && (
                <tr><td colSpan={7} style={{ textAlign: 'center', color: '#888' }}>No logs found.</td></tr>
              )}
              {logs.map(log => (
                <>
                  <tr key={log.id}>
                    <td><code>{log.id}</code></td>
                    <td>
                      <StatusBadge status={log.level || 'debug'} />
                    </td>
                    <td style={{ fontSize: 12 }}>{log.context || '—'}</td>
                    <td style={{ fontSize: 12 }}>{log.ref_id || '—'}</td>
                    <td style={{ wordBreak: 'break-word', fontSize: 13 }}>{log.message || '—'}</td>
                    <td>
                      {(log.data || log.context_data) ? (
                        <Button
                          variant="link"
                          isSmall
                          onClick={() => setExpandedId(expandedId === log.id ? null : log.id)}
                        >
                          {expandedId === log.id ? 'Hide' : 'Show'}
                        </Button>
                      ) : '—'}
                    </td>
                    <td style={{ fontSize: 11 }}>{log.created_at || log.time || '—'}</td>
                  </tr>
                  {expandedId === log.id && (
                    <tr key={`${log.id}-data`}>
                      <td colSpan={7} style={{ background: '#f8f9fa', padding: 12 }}>
                        <div style={{ fontFamily: 'monospace', fontSize: 12 }}>
                          <JsonTree data={log.data || log.context_data || {}} />
                        </div>
                      </td>
                    </tr>
                  )}
                </>
              ))}
            </tbody>
          </table>

          {/* Pagination */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 12 }}>
            <Button variant="secondary" isSmall disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
              &larr; Prev
            </Button>
            <span style={{ fontSize: 13 }}>Page {page} of {totalPages} ({total} total)</span>
            <Button variant="secondary" isSmall disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>
              Next &rarr;
            </Button>
          </div>
        </>
      )}

      {/* Purge confirm modal */}
      {showPurgeConfirm && (
        <Modal
          title="Purge All Logs"
          onRequestClose={() => setShowPurgeConfirm(false)}
        >
          <p>Are you sure you want to delete <strong>all logs</strong>? This cannot be undone.</p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <Button variant="secondary" onClick={() => setShowPurgeConfirm(false)}>Cancel</Button>
            <Button variant="primary" isDestructive onClick={handlePurge} disabled={purging}>
              {purging ? <Spinner /> : 'Purge All'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}
