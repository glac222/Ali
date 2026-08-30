import { useEffect, useState } from 'react';
import { api } from '../api.js';

const PERIODS = ['3 días', '1 semana', '2 semanas', '3 semanas', '1 mes'];
const SORTS = ['Urgencia', 'Precio', 'Tienda'];

function bestPrice(it) {
  const list = it.prices || [];
  return list.find((p) => p.best) || list[0] || null;
}
function priceNum(it) {
  const b = bestPrice(it);
  const n = b ? parseFloat(String(b.price).replace(/[^\d.]/g, '')) : NaN;
  return Number.isFinite(n) ? n : Infinity;
}

export default function Lista() {
  const [period, setPeriod] = useState('1 semana');
  const [sort, setSort] = useState('Urgencia');
  const [data, setData] = useState({ items: [], total: null });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    api.shoppingList.get(period).then(setData).finally(() => setLoading(false));
  }, [period]);

  async function toggleCheck(item) {
    const updated = await api.shoppingList.update(item.id, { checked: !item.checked });
    setData((d) => ({ ...d, items: d.items.map((i) => (i.id === item.id ? updated : i)) }));
  }

  async function step(item, delta) {
    const qty = Math.max(0, item.qty + delta);
    const updated = await api.shoppingList.update(item.id, { qty });
    setData((d) => ({ ...d, items: d.items.map((i) => (i.id === item.id ? updated : i)) }));
  }

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  const sortedItems = [...data.items].sort((a, b) => {
    if (sort === 'Precio') return priceNum(a) - priceNum(b) || a.sort_order - b.sort_order;
    if (sort === 'Tienda') {
      const sa = bestPrice(a)?.store || '~';
      const sb = bestPrice(b)?.store || '~';
      return sa.localeCompare(sb) || a.sort_order - b.sort_order;
    }
    // Urgencia: el grupo "Urgentes" primero, luego el orden original.
    const ua = a.group_label === 'Urgentes' ? 0 : 1;
    const ub = b.group_label === 'Urgentes' ? 0 : 1;
    return ua - ub || a.sort_order - b.sort_order;
  });

  const groups = {};
  for (const it of sortedItems) {
    groups[it.group_label] = groups[it.group_label] || [];
    groups[it.group_label].push(it);
  }

  return (
    <div className="screen active">
      <div className="top-row"><div className="top-title">Lista de compras</div><div className="avatar">{data.items.length}</div></div>

      <div className="period-btns">
        {PERIODS.map((p) => (
          <button key={p} className={'pb' + (period === p ? ' active' : '')} onClick={() => setPeriod(p)}>{p}</button>
        ))}
      </div>

      <div className="list-summary">
        <div>
          <div className="lbl-s">Estimado · {period}</div>
          <div className="amt-big">{data.total || '—'}</div>
        </div>
        <select className="period-select" value={sort} onChange={(e) => setSort(e.target.value)}>
          {SORTS.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {Object.keys(groups).length === 0 && <div className="loading-msg">Sin artículos para este período todavía</div>}

      {Object.entries(groups).map(([label, rows]) => (
        <div key={label}>
          <div style={{ fontFamily: "'IBM Plex Mono',monospace", fontSize: 8.5, color: 'var(--faint)', textTransform: 'uppercase', letterSpacing: '.06em', margin: '10px 0 6px' }}>{label}</div>
          {rows.map((it) => (
            <div className={'shop-item' + (it.checked ? ' checked' : '')} key={it.id}>
              <div className="si-row">
                <div className="checkbox" onClick={() => toggleCheck(it)}>
                  {it.checked && <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="3"><path d="M20 6L9 17l-5-5" /></svg>}
                </div>
                <div className="si-name">{it.name}</div>
                <div className="stepper">
                  <button onClick={() => step(it, -1)}>−</button>
                  <span className="n">{it.qty}</span>
                  <button onClick={() => step(it, 1)}>+</button>
                </div>
              </div>
              <div className="price-tags">
                {it.prices.map((p, i) => (
                  <span key={i} className={'ptag' + (p.best ? ' best' : '')}>{p.store} {p.price}</span>
                ))}
              </div>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}
