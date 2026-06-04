const colors = {
  active: '#0a5227', queued: '#1a3a7a', running: '#7a4e00',
  done: '#0a5227', failed: '#7a0000', dead: '#555',
  paused: '#450070', 'paused-job': '#450070',
  debug: '#888', info: '#2271b1', warning: '#996800',
  error: '#d63638', exception: '#7a0000',
};
const bgs = {
  active: '#d7f0e0', queued: '#e8f0fe', running: '#fff4cc',
  done: '#d7f0e0', failed: '#fde8e8', dead: '#f0f0f0',
  paused: '#f4e8ff', debug: '#f0f0f0', info: '#e8f0fe',
  warning: '#fdeec1', error: '#fde8e8', exception: '#fde8e8',
};
export default function StatusBadge({ status }) {
  const style = {
    display: 'inline-block', padding: '2px 8px', borderRadius: 20,
    fontSize: 11, fontWeight: 700, textTransform: 'uppercase',
    color: colors[status] || '#555', background: bgs[status] || '#f0f0f0',
  };
  return <span style={style}>{status}</span>;
}
