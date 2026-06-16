import apiFetch from '@wordpress/api-fetch';
import { Button, TextControl, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';

export default function FunctionsPage() {
  const [callbacks, setCallbacks] = useState([]);
  const [hooks, setHooks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [newCallback, setNewCallback] = useState('');
  const [newHook, setNewHook] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    apiFetch({ path: '/wrm/v1/functions' })
      .then(data => {
        setCallbacks(Array.isArray(data.callbacks) ? data.callbacks : []);
        setHooks(Array.isArray(data.hooks) ? data.hooks : []);
        setLoading(false);
      })
      .catch(e => { setError(e.message || 'Failed to load allowlists'); setLoading(false); });
  }, []);

  useEffect(() => { load(); }, [load]);

  const showNotice = (msg, type = 'success') => {
    setNotice({ msg, type });
    setTimeout(() => setNotice(null), 5000);
  };

  const addCallback = () => {
    const name = newCallback.trim();
    if (!name) return;
    setBusy(true);
    apiFetch({ path: '/wrm/v1/functions/callbacks', method: 'POST', data: { name } })
      .then(data => {
        setCallbacks(Array.isArray(data.callbacks) ? data.callbacks : callbacks);
        setHooks(Array.isArray(data.hooks) ? data.hooks : hooks);
        setNewCallback(''); setBusy(false);
        showNotice(`Function "${name}" allow-listed.`);
      })
      .catch(e => { showNotice(e.message || 'Could not add function', 'error'); setBusy(false); });
  };

  const removeCallback = (name) => {
    apiFetch({ path: `/wrm/v1/functions/callbacks?name=${encodeURIComponent(name)}`, method: 'DELETE' })
      .then(() => { showNotice(`Function "${name}" removed.`); load(); })
      .catch(e => showNotice(e.message || 'Remove failed', 'error'));
  };

  const addHook = () => {
    const name = newHook.trim();
    if (!name) return;
    setBusy(true);
    apiFetch({ path: '/wrm/v1/functions/hooks', method: 'POST', data: { name } })
      .then(data => {
        setCallbacks(Array.isArray(data.callbacks) ? data.callbacks : callbacks);
        setHooks(Array.isArray(data.hooks) ? data.hooks : hooks);
        setNewHook(''); setBusy(false);
        showNotice(`Hook "${name}" allow-listed.`);
      })
      .catch(e => { showNotice(e.message || 'Could not add hook', 'error'); setBusy(false); });
  };

  const removeHook = (name) => {
    apiFetch({ path: `/wrm/v1/functions/hooks?name=${encodeURIComponent(name)}`, method: 'DELETE' })
      .then(() => { showNotice(`Hook "${name}" removed.`); load(); })
      .catch(e => showNotice(e.message || 'Remove failed', 'error'));
  };

  return (
    <div className="wrm-page wrm-functions-page">
      {notice && <Notice status={notice.type} onRemove={() => setNotice(null)} isDismissible>{notice.msg}</Notice>}

      <Notice status="warning" isDismissible={false}>
        <strong>Security:</strong> Allow-listing a PHP function or action hook lets mappings execute it as
        <code> fn($post_id, $payload, $context)</code>. Only add callbacks you trust. Entries marked
        <em> code</em> were registered in PHP via <code>WRM_Mapper::register_callback()</code> and cannot be removed here.
      </Notice>

      {loading ? <Spinner /> : error ? (
        <Notice status="error" isDismissible={false}>{error}</Notice>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24, marginTop: 16 }}>

          {/* Callbacks */}
          <div style={{ padding: 16, border: '1px solid #e2e4e7', borderRadius: 6, background: '#fff' }}>
            <h2 style={{ marginTop: 0, fontSize: 15 }}>Allowed PHP Functions</h2>
            <p style={{ fontSize: 12, color: '#666' }}>Usable in mapping chains of type <code>function</code>.</p>
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 12 }}>
              <div style={{ flex: 1 }}>
                <TextControl label="Function name" value={newCallback} onChange={setNewCallback} placeholder="my_custom_handler" />
              </div>
              <Button variant="primary" onClick={addCallback} disabled={busy || !newCallback.trim()}>Add</Button>
            </div>
            <table className="wp-list-table widefat striped">
              <thead><tr><th>Function</th><th style={{ width: 80 }}>Source</th><th style={{ width: 70 }}>Exists</th><th style={{ width: 70 }}></th></tr></thead>
              <tbody>
                {callbacks.length === 0 && <tr><td colSpan={4} style={{ color: '#888', textAlign: 'center' }}>None allow-listed.</td></tr>}
                {callbacks.map(c => (
                  <tr key={c.name}>
                    <td><code>{c.name}</code></td>
                    <td><span style={{ fontSize: 11, padding: '2px 6px', borderRadius: 3, background: c.source === 'code' ? '#eef' : '#efe' }}>{c.source}</span></td>
                    <td>{c.exists ? '✓' : <span style={{ color: '#d63638' }} title="Function not defined on this site">✗</span>}</td>
                    <td>{c.source === 'ui'
                      ? <Button variant="link" isSmall isDestructive onClick={() => removeCallback(c.name)}>Remove</Button>
                      : <span style={{ color: '#999', fontSize: 11 }}>locked</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Hooks */}
          <div style={{ padding: 16, border: '1px solid #e2e4e7', borderRadius: 6, background: '#fff' }}>
            <h2 style={{ marginTop: 0, fontSize: 15 }}>Allowed Action Hooks</h2>
            <p style={{ fontSize: 12, color: '#666' }}>Usable in mapping chains of type <code>action</code> (<code>do_action</code>).</p>
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 12 }}>
              <div style={{ flex: 1 }}>
                <TextControl label="Hook name" value={newHook} onChange={setNewHook} placeholder="my_custom_action" />
              </div>
              <Button variant="primary" onClick={addHook} disabled={busy || !newHook.trim()}>Add</Button>
            </div>
            <table className="wp-list-table widefat striped">
              <thead><tr><th>Hook</th><th style={{ width: 80 }}>Source</th><th style={{ width: 70 }}></th></tr></thead>
              <tbody>
                {hooks.length === 0 && <tr><td colSpan={3} style={{ color: '#888', textAlign: 'center' }}>None allow-listed.</td></tr>}
                {hooks.map(h => (
                  <tr key={h.name}>
                    <td><code>{h.name}</code></td>
                    <td><span style={{ fontSize: 11, padding: '2px 6px', borderRadius: 3, background: h.source === 'code' ? '#eef' : '#efe' }}>{h.source}</span></td>
                    <td>{h.source === 'ui'
                      ? <Button variant="link" isSmall isDestructive onClick={() => removeHook(h.name)}>Remove</Button>
                      : <span style={{ color: '#999', fontSize: 11 }}>locked</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
