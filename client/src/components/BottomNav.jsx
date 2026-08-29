const TABS = [
  { key: 'inicio', label: 'Inicio', icon: <path d="M3 12l9-9 9 9M5 10v10h14V10" /> },
  { key: 'despensa', label: 'Despensa', icon: <><rect x="4" y="3" width="16" height="18" rx="1" /><path d="M4 9h16M4 15h16" /></> },
  { key: 'lista', label: 'Lista', icon: <><circle cx="9" cy="21" r="1" /><circle cx="20" cy="21" r="1" /><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6" /></> },
  { key: 'recetas', label: 'Recetas', icon: <><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></> },
  { key: 'plan', label: 'Plan', icon: <><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></> },
];

export default function BottomNav({ active, onChange }) {
  return (
    <div className="bottom-nav">
      {TABS.map((t) => (
        <button
          key={t.key}
          className={'nav-btn' + (active === t.key ? ' active' : '')}
          onClick={() => onChange(t.key)}
        >
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">{t.icon}</svg>
          <span className="nlbl">{t.label}</span>
        </button>
      ))}
    </div>
  );
}
