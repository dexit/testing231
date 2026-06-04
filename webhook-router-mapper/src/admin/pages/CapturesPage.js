import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, Notice, Spinner, Modal, ToggleControl } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';
import JsonTree from '../components/JsonTree';

const PER_PAGE = 20;

export default function CapturesPage() {
  const [captures, setCaptures] = useState([]);
  const [routes, setRoutes] = useState([]);
  const [mappings, setMappings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  // Filters
  const [filterRoute, setFilterRoute] = useState('');
  const [filterProvider, setFilterProvider] = useState('');
  const [filterMapped, setFilterMapped] = useState('');

  // Pagination
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);

  // Inspect modal
  const [inspecting, setInspecting] = useState(null);
  const [paths, setPaths] = useState([]);
  const [pathsLoading, setPathsLoading] = useState(false);
  const [applyMappingId, setApplyMappingId] = useState('');
  const [applyResult, setApplyResult] = useState(null);
  const [applying, setApplying] = useState(false);

  const loadCaptures = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    params.set('per_page', PER_PAGE);
    params.set('page', page);
    if (filterRoute) params.set('route_slug', filterRoute);
    if (filterProvider) params.set('provider', filterProvider);
    if (filterMapped === 'mapped') params.set('mapped', '1');
    if (filterMapped === 'unmapped') params.set('mapped', '0');

    apiFetch({ path: `/wrm/v1/captures?${params.toString()}` })
      .then(data => {
        const list = Array.isArray(data) ? data : (data.items || []);
        const tot = data.total || list.length;
        setCaptures(list);
        setTotal(tot);
        setLoading(false);
      })
      .catch(e => { setError(e.message || 'Failed to load captures'); setLoading(false); });
  }, [page, filterRoute, filterProvider, filterMapped]);

  useEffect(() => {
    loadCaptures();
    apiFetch({ path: '/wrm/v1/routes' })
      .then(data => setRoutes(Array.isArray(data) ? data : []))
      .catch(() => setRoutes([]));
    apiFetch({ path: '/wrm/v1/mappings' })
      .then(data => setMappings(Array.isArray(data) ? data : []))
      .catch(() => setMappings([]));
  }, [loadCaptures]);

  const showNotice = (msg, type = 'success') => {
    setNotice({ msg, type });
    setTimeout(() => setNotice(null), 4000);
  };

  const handleInspect = (capture) => {
    setInspecting(capture);
    setApplyResult(null);
    setApplyMappingId('');
    setPathsLoading(true);
    setPaths([]);
    apiFetch({ path: `/wrm/v1/captures/${capture.id}/paths` })
      .then(data => { setPaths(Array.isArray(data) ? data : []); setPathsLoading(false); })
      .catch(() => { setPaths([]); setPathsLoading(false); });
  };

  const handleApplyMapping = () => {
    if (!inspecting || !applyMappingId) return;
    setApplying(true);
    setApplyResult(null);
    apiFetch({ path: `/wrm/v1/captures/${inspecting.id}/apply`, method: 'POST', data: { mapping_id: applyMappingId } })
      .then(result => { setApplyResult({ ok: true, data: result }); setApplying(false); loadCaptures(); })
      .catch(e => { setApplyResult({ ok: false, msg: e.message || 'Apply failed' }); setApplying(false); });
  };

  const copyPath = (p) => {
    navigator.clipboard?.writeText(`{{${p}}}`).then(() => showNotice(`Copied {{${p}}} to clipboard`));
  };

  const routeOptions = [
    { label: 'All Routes', value: '' },
    ...routes.map(r => ({ label: r.slug, value: r.slug })),
  ];

  const mappingOptions = [
    { label: '— Select mapping —', value: '' },
    ...mappings.map(m => ({ label: m.title || String(m.id), value: String(m.id) })),
  ];

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="wrm-page wrm-captures-page">
      {notice && (
        <Notice status={notice.type} onRemove={() => setNotice(null)} isDismissible>
          {notice.msg}
        </Notice>
      )}

      {/* Filter bar */}
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-end', marginBottom: 16, flexWrap: 'wrap' }}>
        <SelectControl
          label="Route"
          value={filterRoute}
          options={routeOptions}
          onChange={v => { setFilterRoute(v); setPage(1); }}
          style={{ minWidth: 160 }}
        />
        <SelectControl
          label="Mapped"
          value={filterMapped}
          options={[
            { label: 'All', value: '' },
            { label: 'Mapped', value: 'mapped' },
            { label: 'Unmapped', value: 'unmapped' },
          ]}
          onChange={v => { setFilterMapped(v); setPage(1); }}
        />
        <Button variant="secondary" onClick={() => { setPage(1); loadCaptures(); }}>
          Refresh
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
                <th>Route</th>
                <th>Provider</th>
                <th>Method</th>
                <th>IP</th>
                <th>Mapped</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {captures.length === 0 && (
                <tr><td colSpan={8} style={{ textAlign: 'center', color: '#888' }}>No captures found.</td></tr>
              )}
              {captures.map(cap => (
                <tr key={cap.id}>
                  <td><code>{cap.id}</code></td>
                  <td><code>{cap.route_slug || cap.route || '—'}</code></td>
                  <td>{cap.provider || '—'}</td>
                  <td>{cap.method || '—'}</td>
                  <td>{cap.ip || cap.remote_ip || '—'}</td>
                  <td>
                    {cap.mapped || cap.mapping_id
                      ? <StatusBadge status="done" />
                      : <StatusBadge status="dead" />
                    }
                  </td>
                  <td style={{ fontSize: 12 }}>{cap.created_at || cap.captured_at || '—'}</td>
                  <td>
                    <Button variant="secondary" isSmall onClick={() => handleInspect(cap)}>
                      Inspect
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Pagination */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 12 }}>
            <Button
              variant="secondary"
              isSmall
              disabled={page <= 1}
              onClick={() => setPage(p => Math.max(1, p - 1))}
            >
              &larr; Prev
            </Button>
            <span style={{ fontSize: 13 }}>Page {page} of {totalPages} ({total} total)</span>
            <Button
              variant="secondary"
              isSmall
              disabled={page >= totalPages}
              onClick={() => setPage(p => p + 1)}
            >
              Next &rarr;
            </Button>
          </div>
        </>
      )}

      {/* Inspect Modal */}
      {inspecting && (
        <Modal
          title={`Capture #${inspecting.id} — ${inspecting.route_slug || inspecting.route || ''}`}
          onRequestClose={() => { setInspecting(null); setApplyResult(null); }}
          style={{ maxWidth: 900, width: '95vw' }}
        >
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24, minHeight: 300 }}>
            {/* Left: JSON payload */}
            <div>
              <h3 style={{ marginTop: 0, fontSize: 14 }}>Payload</h3>
              <div style={{
                background: '#f8f9fa', border: '1px solid #e2e4e7',
                borderRadius: 4, padding: 12, overflowY: 'auto', maxHeight: 400,
                fontFamily: 'monospace', fontSize: 12,
              }}>
                <JsonTree data={inspecting.payload || inspecting.body || {}} />
              </div>

              {/* Available Paths */}
              <h4 style={{ fontSize: 13, marginBottom: 6, marginTop: 16 }}>Available Paths</h4>
              {pathsLoading ? <Spinner /> : (
                <div style={{ maxHeight: 200, overflowY: 'auto' }}>
                  {paths.length === 0
                    ? <p style={{ color: '#888', fontSize: 12 }}>No paths available.</p>
                    : paths.map(p => (
                      <div key={p} style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
                        <code style={{ fontSize: 11, flex: 1 }}>{p}</code>
                        <Button variant="link" isSmall onClick={() => copyPath(p)}>Copy</Button>
                      </div>
                    ))
                  }
                </div>
              )}
            </div>

            {/* Right: Apply Mapping */}
            <div>
              <h3 style={{ marginTop: 0, fontSize: 14 }}>Apply Mapping</h3>
              <SelectControl
                label="Select Mapping"
                value={applyMappingId}
                options={mappingOptions}
                onChange={setApplyMappingId}
              />
              <Button
                variant="primary"
                onClick={handleApplyMapping}
                disabled={!applyMappingId || applying}
              >
                {applying ? <Spinner /> : 'Apply Mapping'}
              </Button>

              {applyResult && (
                <div style={{ marginTop: 12 }}>
                  {applyResult.ok ? (
                    <Notice status="success" isDismissible={false}>
                      Mapping applied successfully.
                      {applyResult.data && (
                        <div style={{ marginTop: 8, maxHeight: 200, overflowY: 'auto', fontFamily: 'monospace', fontSize: 11 }}>
                          <JsonTree data={applyResult.data} />
                        </div>
                      )}
                    </Notice>
                  ) : (
                    <Notice status="error" isDismissible={false}>{applyResult.msg}</Notice>
                  )}
                </div>
              )}
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
