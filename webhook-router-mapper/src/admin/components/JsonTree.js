import { useState } from '@wordpress/element';

function JsonNode({ value, depth = 0 }) {
  const [open, setOpen] = useState(depth < 2);
  if (value === null) return <span style={{color:'#999'}}>null</span>;
  if (typeof value === 'boolean') return <span style={{color:'#0077aa'}}>{String(value)}</span>;
  if (typeof value === 'number')  return <span style={{color:'#0077aa'}}>{value}</span>;
  if (typeof value === 'string')  return <span style={{color:'#669900'}}>"{value}"</span>;
  if (Array.isArray(value)) {
    if (!value.length) return <span>[]</span>;
    return (
      <span>
        <button className="wrm-tree-toggle" onClick={() => setOpen(o => !o)}>{open ? '▾' : '▸'}</button>
        {'['}
        {open && <div style={{marginLeft: 16}}>{value.map((v,i) => <div key={i}><JsonNode value={v} depth={depth+1} /></div>)}</div>}
        {']'}
      </span>
    );
  }
  // object
  const keys = Object.keys(value);
  if (!keys.length) return <span>{'{}'}</span>;
  return (
    <span>
      <button className="wrm-tree-toggle" onClick={() => setOpen(o => !o)}>{open ? '▾' : '▸'}</button>
      {'{'}
      {open && (
        <div style={{marginLeft: 16}}>
          {keys.map(k => (
            <div key={k}>
              <span style={{color:'#c00', fontWeight:600}}>{k}</span>
              <span style={{color:'#888'}}>: </span>
              <JsonNode value={value[k]} depth={depth+1} />
            </div>
          ))}
        </div>
      )}
      {'}'}
    </span>
  );
}

export default function JsonTree({ data }) {
  const parsed = typeof data === 'string' ? (() => { try { return JSON.parse(data); } catch(e) { return data; } })() : data;
  return <div className="wrm-json-tree"><JsonNode value={parsed} /></div>;
}
