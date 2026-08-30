import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';

const WEEKDAY_KEYS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
const MONTH_NAMES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const PERIODS = ['3 días', '1 semana', '2 semanas', '3 semanas', '1 mes'];

function buildGrid(year, month) {
  const firstDay = new Date(year, month, 1);
  const startOffset = (firstDay.getDay() + 6) % 7; // Monday = 0
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();
  const cells = [];
  for (let i = startOffset - 1; i >= 0; i--) {
    cells.push({ day: daysInPrevMonth - i, faded: true, date: new Date(year, month - 1, daysInPrevMonth - i) });
  }
  for (let d = 1; d <= daysInMonth; d++) {
    cells.push({ day: d, faded: false, date: new Date(year, month, d) });
  }
  while (cells.length % 7 !== 0) {
    const idx = cells.length - startOffset - daysInMonth + 1;
    cells.push({ day: idx, faded: true, date: new Date(year, month + 1, idx) });
  }
  return cells;
}

function sameDay(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export default function Plan({ onToast, onGoToList }) {
  const today = new Date();
  const [cursor, setCursor] = useState(new Date(today.getFullYear(), today.getMonth(), 1));
  const [selected, setSelected] = useState(today);
  const [planByDay, setPlanByDay] = useState({});
  const [loading, setLoading] = useState(true);
  const [period, setPeriod] = useState('1 semana');
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    api.plan.all().then(setPlanByDay).finally(() => setLoading(false));
  }, []);

  async function generarLista() {
    if (generating) return;
    setGenerating(true);
    try {
      const r = await api.shoppingList.generate(period);
      onToast?.(`✓ Lista generada · ${period}: ${r.generated} por reponer`);
      onGoToList?.();
    } catch (err) {
      onToast?.('No se pudo generar la lista');
    } finally {
      setGenerating(false);
    }
  }

  const grid = useMemo(() => buildGrid(cursor.getFullYear(), cursor.getMonth()), [cursor]);
  const selectedWeekday = WEEKDAY_KEYS[selected.getDay()];
  const rows = planByDay[selectedWeekday] || [];

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  return (
    <div className="screen active">
      <div className="top-row"><div className="top-title">Plan mensual</div><div className="avatar">G</div></div>

      <div className="month-nav">
        <button onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}>‹</button>
        <h3>{MONTH_NAMES[cursor.getMonth()]} {cursor.getFullYear()}</h3>
        <button onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}>›</button>
      </div>

      <div className="cal-grid">
        {['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((d, i) => <div className="cal-dow" key={i}>{d}</div>)}
        {grid.map((cell, i) => {
          const isSelected = sameDay(cell.date, selected);
          const hasPlan = Boolean(planByDay[WEEKDAY_KEYS[cell.date.getDay()]]?.length);
          return (
            <div
              key={i}
              className={'cal-day' + (cell.faded ? ' faded' : '') + (hasPlan && !cell.faded ? ' hp' : '') + (isSelected ? ' selected' : '')}
              onClick={() => setSelected(cell.date)}
            >
              {cell.day}
              {hasPlan && !cell.faded && <div className="cal-dots"><span /><span /><span /></div>}
            </div>
          );
        })}
      </div>

      <div className="day-panel">
        <div className="day-lbl">{selected.toLocaleDateString('es-EC', { weekday: 'long', day: 'numeric', month: 'long' })}</div>
        {rows.map((r) => (
          <div className="plan-row" key={r.id}>
            <div className="plan-kind">{r.meal_type}</div>
            <div>
              <div className="plan-rname">{r.title}</div>
              {r.detail && <div className="plan-detail">{r.detail}</div>}
            </div>
          </div>
        ))}
        {rows.length === 0 && <div className="plan-detail">Sin plan para este día todavía.</div>}
      </div>

      <div style={{ fontFamily: "'IBM Plex Mono',monospace", fontSize: 8.5, color: 'var(--faint)', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 8 }}>Generar lista para:</div>
      <div className="period-btns">
        {PERIODS.map((p) => (
          <button key={p} className={'pb' + (period === p ? ' active' : '')} onClick={() => setPeriod(p)}>{p}</button>
        ))}
      </div>
      <button className="cta-btn" onClick={generarLista} disabled={generating} style={generating ? { opacity: 0.6 } : undefined}>
        {generating ? 'Generando…' : `Generar lista · ${period} →`}
      </button>
    </div>
  );
}
