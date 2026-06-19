import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, TextControl, Notice, Spinner, CheckboxControl } from '@wordpress/components';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';

const ALL_STATUSES = ['queued', 'running', 'done', 'failed', 'dead', 'paused'];

const STATUS_COLORS = {
  queued: { bg: '#e8f0fe', color: '#1a3a7a' },
  running: { bg: '#fff4cc', color: '#7a4e00' },
  done: { bg: '#d7f0e0', color: '#0a5227' },
  failed: { bg: '#fde8e8', color: '#7a0000' },
  dead: { bg: '#f0f0f0', color: '#555' },
  paused: { bg: '#f4e8ff', color: '#450070' },
};

export default function JobsPage() {
  const [jobs, setJobs] = useState([]);
  const [routes, setRoutes] = useState([]);
  const [stats, setStats] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  // Filters
  const [filterStatus, setFilterStatus] = useState('');
  const [filterRoute, setFilterRoute] = useState('');
  const [search, setSearch] = useState('');

  // Expanded errors
  const [expandedErrors, setExpandedErrors] = useState({});

  // Auto-refresh
  const [autoRefresh, setAutoRefresh] = useState(false);
  const intervalRef = useRef(null);

  // Selection for bulk retry
  const [selected, setSelected] = useState([]);

  // Pagination
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const PER_PAGE = 25;

  const loadJobs = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    params.set('per_page', PER_PAGE);
    params.set('page', page);
    if (filterStatus) params.set('status', filterStatus);
    if (filterRoute) params.set('route_slug', filterRoute);
    if (search) params.set('search', search);

    apiFetch({ path: `/wrm/v1/jobs?${params.toString()}` })
      .then(data => {
        const list = Array.isArray(data) ? data : (data.items || []);
        const tot = data.total !== undefined ? data.total : list.length;
        setJobs(list);
        setTotal(tot);
        setLoading(false);
      })
      .catch(e => {
        const msg = e?.message || '';
        setError(e?.code === 'rest_forbidden' || /forbidden|not allowed/i.test(msg)
          ? 'Access denied — administrator privileges required.'
          : msg || 'Failed to load jobs');
        setLoading(false);
      });
  }, [page, filterStatus, filterRoute, search]);

  const loadStats = useCallback(() => {
    apiFetch({ path: '/wrm/v1/jobs/stats' })
      .then(data => setStats(data || {}))
      .catch(() => {});
  }, []);

  useEffect(() => {
    loadJobs();
    loadStats();
    apiFetch({ path: '/wrm/v1/routes' })
      .then(data => setRoutes(Array.isArray(data) ? data : []))
      .catch(() => setRoutes([]));
  }, [loadJobs, loadStats]);

  // Auto-refresh
  useEffect(() => {
    if (autoRefresh) {
      intervalRef.current = setInterval(() => loadJobs(), 5000);
    } else {
      clearInterval(intervalRef.current);
    }
    return () => clearInterval(intervalRef.current);
  }, [autoRefresh, loadJobs]);

  const showNotice = (msg, type = 'success') => {
    setNotice({ msg, type });
    setTimeout(() => setNotice(null), 4000);
  };

  const handleRetry = (jobId) => {
    apiFetch({ path: `/wrm/v1/jobs/${jobId}/retry`, method: 'POST' })
      .then(() => { showNotice(`Job #${jobId} queued for retry.`); loadJobs(); loadStats(); })
      .catch(e => showNotice(e.message || 'Retry failed', 'error'));
  };

  const handleBulkRetry = () => {
    Promise.all(selected.map(id => apiFetch({ path: `/wrm/v1/jobs/${id}/retry`, method: 'POST' })))
      .then(() => { showNotice(`${selected.length} job(s) queued for retry.`); setSelected([]); loadJobs(); loadStats(); })
      .catch(e => showNotice(e.message || 'Bulk retry failed', 'error'));
  };

  const toggleSelect = (id) => {
    setSelected(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  };

  const toggleSelectAll = () => {
    const retryable = jobs.filter(j => j.status === 'failed' || j.status === 'dead').map(j => j.id);
    if (selected.length === retryable.length) {
      setSelected([]);
    } else {
      setSelected(retryable);
    }
  };

  // Global counts from /jobs/stats endpoint (accurate across all pages)
  const counts = ALL_STATUSES.reduce((acc, s) => {
    acc[s] = stats[s] ?? 0;
    return acc;
  }, {});

  const routeOptions = [
    { label: 'All Routes', value: '' },
    ...routes.map(r => ({ label: r.slug, value: r.slug })),
  ];

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
  const retryableSelected = selected.filter(id => {
    const j = jobs.find(j => j.id === id);
    return j && (j.status === 'failed' || j.status === 'dead');
  });

  return (
    <div className="wrm-page wrm-jobs-page">
      {notice && (
        <Notice status={notice.type} onRemove={() => setNotice(null)} isDismissible>
          {notice.msg}
        </Notice>
      )}

      {/* Status summary cards */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap' }}>
        {ALL_STATUSES.map(s => (
          <div
            key={s}
            onClick={() => { setFilterStatus(filterStatus === s ? '' : s); setPage(1); }}
            style={{
              padding: '10px 18px', borderRadius: 6, cursor: 'pointer',
              background: filterStatus === s ? STATUS_COLORS[s].color : STATUS_COLORS[s].bg,
              color: filterStatus === s ? '#fff' : STATUS_COLORS[s].color,
              border: `2px solid ${STATUS_COLORS[s].color}`,
              fontWeight: 700, fontSize: 13, minWidth: 80, textAlign: 'center',
              transition: 'all 0.15s',
            }}
          >
            <div style={{ fontSize: 22, lineHeight: 1 }}>{counts[s]}</div>
            <div style={{ fontSize: 11, textTransform: 'uppercase', marginTop: 2 }}>{s}</div>
          </div>
        ))}
      </div>

      {/* Filter bar */}
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-end', marginBottom: 12, flexWrap: 'wrap' }}>
        <SelectControl
          label="Route"
          value={filterRoute}
          options={routeOptions}
          onChange={v => { setFilterRoute(v); setPage(1); }}
        />
        <TextControl
          label="Search"
          value={search}
          placeholder="error message or route"
          onChange={v => { setSearch(v); setPage(1); }}
        />
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <CheckboxControl
            label="Auto-refresh (5s)"
            checked={autoRefresh}
            onChange={setAutoRefresh}
          />
        </div>
        <Button variant="secondary" onClick={() => loadJobs()}>Refresh</Button>
        {retryableSelected.length > 0 && (
          <Button variant="primary" onClick={handleBulkRetry}>
            Retry Selected ({retryableSelected.length})
          </Button>
        )}
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
                <th style={{ width: 36 }}>
                  <CheckboxControl
                    checked={selected.length > 0 && selected.length === jobs.filter(j => j.status === 'failed' || j.status === 'dead').length}
                    onChange={toggleSelectAll}
                    label=""
                  />
                </th>
                <th style={{ width: 60 }}>ID</th>
                <th>Route</th>
                <th>Status</th>
                <th>Attempt</th>
                <th>Duration</th>
                <th>Queued at</th>
                <th>Finished at</th>
                <th>Error</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {jobs.length === 0 && (
                <tr>
                  <td colSpan={10} style={{ textAlign: 'center', color: '#888', padding: '20px 0' }}>
                    {search
                      ? <>No jobs matching <strong>"{search}"</strong>.</>
                      : filterStatus
                        ? <>No <strong>{filterStatus}</strong> jobs found.</>
                        : 'No jobs yet — they appear here once routes start processing.'}
                  </td>
                </tr>
              )}
              {jobs.map(job => (
                <tr key={job.id}>
                  <td>
                    {(job.status === 'failed' || job.status === 'dead') && (
                      <CheckboxControl
                        checked={selected.includes(job.id)}
                        onChange={() => toggleSelect(job.id)}
                        label=""
                      />
                    )}
                  </td>
                  <td><code>{job.id}</code></td>
                  <td><code>{job.route_slug || job.route || '—'}</code></td>
                  <td><StatusBadge status={job.status || 'queued'} /></td>
                  <td>{job.attempt !== undefined ? `${job.attempt}/${job.max_attempts || '?'}` : '—'}</td>
                  <td>{job.duration_ms !== undefined ? `${job.duration_ms}ms` : '—'}</td>
                  <td style={{ fontSize: 12 }}>{job.queued_at || job.created_at || '—'}</td>
                  <td style={{ fontSize: 12 }}>{job.finished_at || '—'}</td>
                  <td style={{ fontSize: 11, color: '#d63638', maxWidth: 200 }}>
                    {job.error_message ? (
                      <span
                        onClick={() => setExpandedErrors(prev => ({ ...prev, [job.id]: !prev[job.id] }))}
                        style={{ cursor: 'pointer' }}
                        title={expandedErrors[job.id] ? undefined : job.error_message}
                      >
                        {expandedErrors[job.id]
                          ? job.error_message
                          : job.error_message.substring(0, 80) + (job.error_message.length > 80 ? '…' : '')}
                      </span>
                    ) : '—'}
                  </td>
                  <td>
                    {(job.status === 'failed' || job.status === 'dead') && (
                      <Button variant="secondary" isSmall onClick={() => handleRetry(job.id)}>
                        Retry
                      </Button>
                    )}
                  </td>
                </tr>
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
    </div>
  );
}
