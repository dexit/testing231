import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, TextControl, Notice, Spinner, Modal } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';

const PER_PAGE = 25;
const STATUSES = ['queued', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed', 'complaint'];

const CARD_COLORS = {
  queued: '#e8f0fe', sent: '#e0f0ff', delivered: '#d7f0e0', opened: '#fff4cc',
  clicked: '#e7ddff', bounced: '#fde8e8', failed: '#fde8e8', complaint: '#ffd9d9',
};

export default function MessagesPage() {
  const [messages, setMessages] = useState([]);
  const [stats, setStats] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filterChannel, setFilterChannel] = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const [events, setEvents] = useState(null);
  const [eventsFor, setEventsFor] = useState(null);
  const [eventsLoading, setEventsLoading] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    params.set('per_page', PER_PAGE);
    params.set('offset', (page - 1) * PER_PAGE);
    if (filterChannel) params.set('channel', filterChannel);
    if (filterStatus) params.set('status', filterStatus);
    if (search) params.set('search', search);
    apiFetch({ path: `/wrm/v1/messages?${params.toString()}` })
      .then(data => { setMessages(Array.isArray(data) ? data : []); setLoading(false); })
      .catch(e => {
        const msg = e?.message || '';
        setError(e?.code === 'rest_forbidden' || /forbidden|not allowed/i.test(msg)
          ? 'Access denied — administrator privileges required.'
          : msg || 'Failed to load messages');
        setLoading(false);
      });
  }, [page, filterChannel, filterStatus, search]);

  const loadStats = useCallback(() => {
    apiFetch({ path: '/wrm/v1/messages/stats' }).then(d => setStats(d || {})).catch(() => {});
  }, []);

  useEffect(() => { load(); loadStats(); }, [load, loadStats]);

  const openEvents = (msg) => {
    setEventsFor(msg);
    setEventsLoading(true);
    setEvents([]);
    apiFetch({ path: `/wrm/v1/messages/${msg.id}/events` })
      .then(d => { setEvents(Array.isArray(d) ? d : []); setEventsLoading(false); })
      .catch(() => { setEvents([]); setEventsLoading(false); });
  };

  const channelOptions = [
    { label: 'All channels', value: '' },
    { label: 'Email', value: 'email' },
    { label: 'SMS', value: 'sms' },
    { label: 'WhatsApp', value: 'whatsapp' },
  ];
  const statusOptions = [{ label: 'All statuses', value: '' }, ...STATUSES.map(s => ({ label: s, value: s }))];

  return (
    <div className="wrm-page wrm-messages-page">
      {/* Stat cards */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 20, flexWrap: 'wrap' }}>
        {STATUSES.map(s => (
          <div key={s}
            onClick={() => { setFilterStatus(filterStatus === s ? '' : s); setPage(1); }}
            style={{
              padding: '8px 16px', borderRadius: 6, cursor: 'pointer', minWidth: 76, textAlign: 'center',
              background: CARD_COLORS[s], border: filterStatus === s ? '2px solid #1e1e1e' : '2px solid transparent',
            }}>
            <div style={{ fontSize: 20, fontWeight: 700 }}>{stats[s] ?? 0}</div>
            <div style={{ fontSize: 11, textTransform: 'uppercase' }}>{s}</div>
          </div>
        ))}
      </div>

      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-end', marginBottom: 16, flexWrap: 'wrap' }}>
        <SelectControl label="Channel" value={filterChannel} options={channelOptions} onChange={v => { setFilterChannel(v); setPage(1); }} />
        <SelectControl label="Status" value={filterStatus} options={statusOptions} onChange={v => { setFilterStatus(v); setPage(1); }} />
        <TextControl label="Search" value={search} onChange={v => { setSearch(v); setPage(1); }} placeholder="recipient or subject" />
        <Button variant="secondary" onClick={() => { load(); loadStats(); }}>Refresh</Button>
      </div>

      {loading ? <Spinner /> : error ? (
        <Notice status="error" isDismissible={false}>{error}</Notice>
      ) : (
        <>
          <table className="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th style={{ width: 50 }}>ID</th>
                <th>Channel</th>
                <th>Provider</th>
                <th>Recipient</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Opens</th>
                <th>Clicks</th>
                <th>Sent</th>
                <th>Events</th>
              </tr>
            </thead>
            <tbody>
              {messages.length === 0 && (
                <tr>
                  <td colSpan={10} style={{ textAlign: 'center', color: '#888', padding: '20px 0' }}>
                    {search
                      ? <>No messages matching <strong>"{search}"</strong>.</>
                      : filterStatus
                        ? <>No <strong>{filterStatus}</strong> messages found.</>
                        : 'No messages tracked yet — send emails or SMS via a mapped route to see them here.'}
                  </td>
                </tr>
              )}
              {messages.map(m => (
                <tr key={m.id}>
                  <td><code>{m.id}</code></td>
                  <td>{m.channel}</td>
                  <td>{m.provider || '—'}</td>
                  <td style={{ fontSize: 12 }}>{m.recipient || '—'}</td>
                  <td title={m.subject || ''} style={{ fontSize: 12, maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.subject || '—'}</td>
                  <td><StatusBadge status={m.status} /></td>
                  <td>{m.open_count ?? 0}</td>
                  <td>{m.click_count ?? 0}</td>
                  <td style={{ fontSize: 12 }}>{m.sent_at || '—'}</td>
                  <td><Button variant="link" isSmall onClick={() => openEvents(m)}>View</Button></td>
                </tr>
              ))}
            </tbody>
          </table>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 12 }}>
            <Button variant="secondary" isSmall disabled={page <= 1} onClick={() => setPage(p => p - 1)}>&larr; Prev</Button>
            <span style={{ fontSize: 13 }}>Page {page}</span>
            <Button variant="secondary" isSmall disabled={messages.length < PER_PAGE} onClick={() => setPage(p => p + 1)}>Next &rarr;</Button>
          </div>
        </>
      )}

      {eventsFor && (
        <Modal title={`Message #${eventsFor.id} — event timeline`} onRequestClose={() => setEventsFor(null)} style={{ maxWidth: 640 }}>
          {eventsLoading ? <Spinner /> : (
            <table className="wp-list-table widefat striped">
              <thead><tr><th>Event</th><th>When (UTC)</th><th>IP</th><th>URL</th></tr></thead>
              <tbody>
                {(!events || events.length === 0) && <tr><td colSpan={4} style={{ color: '#888', textAlign: 'center' }}>No events recorded.</td></tr>}
                {events && events.map(ev => (
                  <tr key={ev.id}>
                    <td><StatusBadge status={ev.event} /></td>
                    <td style={{ fontSize: 12 }}>{ev.created_at}</td>
                    <td style={{ fontSize: 12 }}>{ev.ip || '—'}</td>
                    <td style={{ fontSize: 11, maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ev.url || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Modal>
      )}
    </div>
  );
}
