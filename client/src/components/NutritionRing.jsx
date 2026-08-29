const CIRC = 144.5;

export default function NutritionRing({ value, target, label, unit, color }) {
  const pct = target > 0 ? Math.min(value / target, 1) : 0;
  const offset = CIRC * (1 - pct);
  return (
    <div className="rc">
      <div className="ring">
        <svg width="56" height="56">
          <circle cx="28" cy="28" r="23" stroke="#E3E0D5" strokeWidth="5" fill="none" />
          <circle cx="28" cy="28" r="23" stroke={color} strokeWidth="5" fill="none" strokeDasharray={CIRC} strokeDashoffset={offset} strokeLinecap="round" />
        </svg>
        <span className="rv">{value}{unit}</span>
      </div>
      <div className="rl">{label}</div>
      <div className="rs">/ {target}{unit}</div>
    </div>
  );
}
