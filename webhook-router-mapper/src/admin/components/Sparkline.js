export default function Sparkline({ data = [], width = 80, height = 28, color = '#2271b1', fillColor = '#e8f4ff' }) {
  if (!data || data.length < 2) {
    return <svg width={width} height={height} style={{ display: 'block' }} />;
  }
  const max = Math.max(...data, 1);
  const pts = data.map((v, i) => {
    const x = (i / (data.length - 1)) * width;
    const y = height - (v / max) * (height - 2) - 1;
    return `${x},${y}`;
  });
  const linePath = `M${pts.join(' L')}`;
  const areaPath = `M0,${height} L${pts.join(' L')} L${width},${height} Z`;
  return (
    <svg width={width} height={height} style={{ display: 'block', overflow: 'visible' }}>
      <path d={areaPath} fill={fillColor} opacity={0.5} />
      <path d={linePath} fill="none" stroke={color} strokeWidth={1.5} strokeLinejoin="round" />
    </svg>
  );
}
