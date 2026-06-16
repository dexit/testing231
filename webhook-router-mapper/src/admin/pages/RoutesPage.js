import apiFetch from '@wordpress/api-fetch';
import { Button, TextControl, SelectControl, Notice, Spinner, Modal } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';

const METHODS = [
  { label: 'POST', value: 'POST' },
  { label: 'GET', value: 'GET' },
  { label: 'PUT', value: 'PUT' },
  { label: 'PATCH', value: 'PATCH' },
  { label: 'DELETE', value: 'DELETE' },
];

const PROVIDERS = [
  { label: 'Custom', value: 'custom' },
  { label: 'Twilio', value: 'twilio' },
  { label: 'HubSpot', value: 'hubspot' },
  { label: 'WhatsApp', value: 'whatsapp' },
];

const RUN_MODES = [
  { label: 'Async', value: 'async' },
  { label: 'Sync', value: 'sync' },
];

const emptyForm = {
  slug: '', label: '', methods: 'POST', provider: 'custom',
  auth_token: '', rate_limit: '', rate_window: '', run_mode: 'async', mapping_id: '',
};

export default function RoutesPage() {
  const [routes, setRoutes] = useState([]);
  const [mappings, setMappings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const loadRoutes = useCallback(() => {
    setLoading(true);
    apiFetch({ path: '/wrm/v1/routes' })
      .then(data => { setRoutes(Array.isArray(data) ? data : []); setLoading(false); })
      .catch(e => { setError(e.message || 'Failed to load routes'); setLoading(false); });
  }, []);

  useEffect(() => {
    loadRoutes();
    apiFetch({ path: '/wrm/v1/mappings' })
      .then(data => setMappings(Array.isArray(data) ? data : []))
      .catch(() => setMappings([]));
  }, [loadRoutes]);

  const showNotice = (msg, type = 'success') => {
    setNotice({ msg, type });
    setTimeout(() => setNotice(null), 4000);
  };

  const handleSave = () => {
    setSaving(true);
    apiFetch({ path: '/wrm/v1/routes', method: 'POST', data: form })
      .then(() => {
        setSaving(false);
        setShowForm(false);
        setForm(emptyForm);
        showNotice('Route created successfully.');
        loadRoutes();
      })
      .catch(e => { setSaving(false); showNotice(e.message || 'Save failed', 'error'); });
  };

  const handleToggleStatus = (route) => {
    const newStatus = route.status === 'active' ? 'paused' : 'active';
    apiFetch({ path: `/wrm/v1/routes/${route.slug}`, method: 'PUT', data: { status: newStatus } })
      .then(() => { showNotice(`Route ${newStatus}.`); loadRoutes(); })
      .catch(e => showNotice(e.message || 'Update failed', 'error'));
  };

  const handleDelete = () => {
    if (!deleteTarget) return;
    apiFetch({ path: `/wrm/v1/routes/${deleteTarget.slug}`, method: 'DELETE' })
      .then(() => { setDeleteTarget(null); showNotice('Route deleted.'); loadRoutes(); })
      .catch(e => { setDeleteTarget(null); showNotice(e.message || 'Delete failed', 'error'); });
  };

  const copyWebhookUrl = (route) => {
    const url = `${window.wrmAdminData?.siteUrl || ''}/webhook/${route.slug}`;
    navigator.clipboard?.writeText(url).then(() => showNotice('Copied to clipboard!'));
  };

  const total = routes.length;
  const active = routes.filter(r => r.status === 'active').length;
  const paused = routes.filter(r => r.status === 'paused').length;

  const mappingOptions = [
    { label: '— None —', value: '' },
    ...mappings.map(m => ({ label: m.title || m.id, value: String(m.id) })),
  ];

  return (
    <div className="wrm-page wrm-routes-page">
      {notice && (
        <Notice status={notice.type} onRemove={() => setNotice(null)} isDismissible>
          {notice.msg}
        </Notice>
      )}

      <div className="wrm-summary-pills" style={{ display: 'flex', gap: 12, marginBottom: 16 }}>
        {[
          { label: 'Total', count: total, bg: '#f0f0f0', color: '#333' },
          { label: 'Active', count: active, bg: '#d7f0e0', color: '#0a5227' },
          { label: 'Paused', count: paused, bg: '#f4e8ff', color: '#450070' },
        ].map(({ label, count, bg, color }) => (
          <span key={label} style={{ padding: '4px 14px', borderRadius: 20, background: bg, color, fontWeight: 700, fontSize: 13 }}>
            {label}: {count}
          </span>
        ))}
      </div>

      <div style={{ marginBottom: 12 }}>
        <Button variant="primary" onClick={() => setShowForm(s => !s)}>
          {showForm ? '— Cancel' : '+ Add New Route'}
        </Button>
      </div>

      {showForm && (
        <div className="wrm-form-panel" style={{ background: '#fff', border: '1px solid #ddd', padding: 20, marginBottom: 20, borderRadius: 4 }}>
          <h3 style={{ marginTop: 0 }}>New Route</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 20px' }}>
            <TextControl label="Slug" value={form.slug} onChange={v => setForm(f => ({ ...f, slug: v }))} />
            <TextControl label="Label" value={form.label} onChange={v => setForm(f => ({ ...f, label: v }))} />
            <SelectControl label="Method" value={form.methods} options={METHODS} onChange={v => setForm(f => ({ ...f, methods: v }))} />
            <SelectControl label="Provider" value={form.provider} options={PROVIDERS} onChange={v => setForm(f => ({ ...f, provider: v }))} />
            <TextControl label="Auth Token" value={form.auth_token} onChange={v => setForm(f => ({ ...f, auth_token: v }))} type="password" />
            <TextControl label="Rate Limit" value={form.rate_limit} onChange={v => setForm(f => ({ ...f, rate_limit: v }))} type="number" />
            <TextControl label="Rate Window (s)" value={form.rate_window} onChange={v => setForm(f => ({ ...f, rate_window: v }))} type="number" />
            <SelectControl label="Run Mode" value={form.run_mode} options={RUN_MODES} onChange={v => setForm(f => ({ ...f, run_mode: v }))} />
            <SelectControl label="Mapping" value={form.mapping_id} options={mappingOptions} onChange={v => setForm(f => ({ ...f, mapping_id: v }))} />
          </div>
          <Button variant="primary" onClick={handleSave} disabled={saving} style={{ marginTop: 8 }}>
            {saving ? <Spinner /> : 'Save Route'}
          </Button>
        </div>
      )}

      {loading ? (
        <Spinner />
      ) : error ? (
        <Notice status="error" isDismissible={false}>{error}</Notice>
      ) : (
        <table className="wp-list-table widefat fixed striped" style={{ marginTop: 8 }}>
          <thead>
            <tr>
              <th>Slug</th>
              <th>Label</th>
              <th>Provider</th>
              <th>Method</th>
              <th>Mode</th>
              <th>Rate Limit</th>
              <th>Mapping</th>
              <th>Status</th>
              <th>Webhook URL</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {routes.length === 0 && (
              <tr><td colSpan={10} style={{ textAlign: 'center', color: '#888' }}>No routes found.</td></tr>
            )}
            {routes.map(route => (
              <tr key={route.slug}>
                <td><code>{route.slug}</code></td>
                <td>{route.label || '—'}</td>
                <td>{route.provider || '—'}</td>
                <td>{route.methods || route.method || '—'}</td>
                <td>{route.run_mode || '—'}</td>
                <td>{route.rate_limit ? `${route.rate_limit}/${route.rate_window || 60}s` : '—'}</td>
                <td>{route.mapping_id ? `#${route.mapping_id}` : '—'}</td>
                <td><StatusBadge status={route.status || 'active'} /></td>
                <td>
                  <Button
                    variant="link"
                    onClick={() => copyWebhookUrl(route)}
                    style={{ fontSize: 11 }}
                  >
                    Copy URL
                  </Button>
                </td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  <Button
                    variant="secondary"
                    isSmall
                    onClick={() => handleToggleStatus(route)}
                    style={{ marginRight: 6 }}
                  >
                    {route.status === 'active' ? 'Pause' : 'Resume'}
                  </Button>
                  <Button
                    variant="link"
                    isDestructive
                    isSmall
                    onClick={() => setDeleteTarget(route)}
                  >
                    Delete
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {deleteTarget && (
        <Modal
          title="Delete Route"
          onRequestClose={() => setDeleteTarget(null)}
        >
          <p>Delete route <strong>{deleteTarget.slug}</strong>? This cannot be undone.</p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <Button variant="secondary" onClick={() => setDeleteTarget(null)}>Cancel</Button>
            <Button variant="primary" isDestructive onClick={handleDelete}>Delete</Button>
          </div>
        </Modal>
      )}
    </div>
  );
}
