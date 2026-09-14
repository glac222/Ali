import { useEffect, useState } from 'react';
import { api } from '../api.js';

const PERIODS = ['3 días', '1 semana', '2 semanas', '3 semanas', '1 mes'];
const SORTS = ['Urgencia', 'Precio', 'Tienda'];

function bestPrice(it) {
  const list = it.prices || [];
  return list.find((p) => p.best) || list[0] || null;
}

// Descompone un precio de texto. Mismo criterio que el backend
// (shop_price_parse en php/src/shopping.php):
//   "$1.20"           -> { amount: 1.2, pack: null }   precio total: se multiplica por qty
//   "$1.00/pack 8 un" -> { amount: 1,   pack: 8 }      precio de lote: se pagan ceil(qty/8) paquetes
//   "$3.80/lb" · "$0.30/un" -> null                    tarifa a granel sin conteo: no multiplicable
function parsePrice(raw) {
  const s = String(raw || '');
  const m = s.match(/(\d+(?:[.,]\d+)?)/);
  if (!m) return null;
  const amount = parseFloat(m[1].replace(',', '.'));
  if (!(amount > 0)) return null;
  const after = s.slice(m.index + m[0].length).match(/(\d+(?:[.,]\d+)?)/);
  if (after) {
    const pack = parseFloat(after[1].replace(',', '.'));
    if (pack >= 1) return { amount, pack };
  }
  if (s.includes('/')) return null; // "/lb", "/un": tarifa, no se puede multiplicar
  return { amount, pack: null };
}

// Tamaño de lote del ítem (1 si su precio no es "por paquete").
function packSize(it) {
  const p = parsePrice(bestPrice(it)?.price);
  return p && p.pack > 1 ? p.pack : 1;
}

// Lo que muestra el stepper: paquetes si hay precio de lote, si no unidades.
function stepCount(it) {
  const n = packSize(it);
  return n > 1 ? Math.max(1, Math.ceil(Math.max(1, it.qty) / n)) : Math.max(1, it.qty);
}

function priceNum(it) {
  const p = parsePrice(bestPrice(it)?.price);
  return p ? p.amount : Infinity;
}

// El precio de un ítem en una tienda puntual (no necesariamente el "best").
function priceAt(it, store) {
  return (it.prices || []).find((p) => p.store === store) || null;
}

// Costo de una entrada de precio × cantidad (o × paquetes si es precio de lote).
// null = precio no multiplicable -> cuenta como "sin precio".
function costFor(priceEntry, qty) {
  const p = parsePrice(priceEntry?.price);
  if (!p) return null;
  const q = Math.max(1, qty);
  return p.pack > 1 ? p.amount * Math.max(1, Math.ceil(q / p.pack)) : p.amount * q;
}

function lineCost(it) {
  return costFor(bestPrice(it), it.qty);
}

export default function Lista() {
  const [period, setPeriod] = useState('1 semana');
  const [sort, setSort] = useState('Urgencia');
  const [data, setData] = useState({ items: [], total: null });
  const [loading, setLoading] = useState(true);
  const [buyMode, setBuyMode] = useState(false);
  const [buyStore, setBuyStore] = useState(null);
  const [cart, setCart] = useState(() => new Set());
  const [buying, setBuying] = useState(false);

  useEffect(() => {
    setLoading(true);
    api.shoppingList.get(period).then(setData).finally(() => setLoading(false));
  }, [period]);

  async function toggleCheck(item) {
    const updated = await api.shoppingList.update(item.id, { checked: !item.checked });
    setData((d) => ({ ...d, items: d.items.map((i) => (i.id === item.id ? updated : i)) }));
  }

  async function step(item, delta) {
    // Con precio de lote cada +/− es un paquete (qty se sigue guardando en unidades).
    const n = packSize(item);
    let qty;
    if (n > 1) {
      const count = Math.ceil(Math.max(1, item.qty) / n);
      const nextCount = count + delta;
      if (nextCount < 1) return; // ya está en el mínimo de un paquete: no redondear hacia arriba
      qty = nextCount * n;
    } else {
      qty = Math.max(1, item.qty + delta);
    }
    if (qty === item.qty) return;
    // Optimista: el total (que se calcula desde los items) se mueve al instante.
    setData((d) => ({ ...d, items: d.items.map((i) => (i.id === item.id ? { ...i, qty } : i)) }));
    try {
      const updated = await api.shoppingList.update(item.id, { qty });
      setData((d) => ({ ...d, items: d.items.map((i) => (i.id === item.id ? updated : i)) }));
    } catch {
      setData((d) => ({ ...d, items: d.items.map((i) => (i.id === item.id ? item : i)) }));
    }
  }

  function toggleBuyMode() {
    setBuyMode((v) => !v);
    setBuyStore(null);
    setCart(new Set());
  }

  function toggleCart(id) {
    setCart((c) => {
      const next = new Set(c);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  async function markBought() {
    if (cart.size === 0 || buying) return;
    setBuying(true);
    const ids = [...cart];
    try {
      const updates = await Promise.all(ids.map((id) => api.shoppingList.update(id, { checked: true })));
      setData((d) => ({
        ...d,
        items: d.items.map((i) => updates.find((u) => u.id === i.id) || i),
      }));
      setCart(new Set());
    } finally {
      setBuying(false);
    }
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

  // Total en vivo: suma del costo de cada línea (precio × cantidad, o × paquetes
  // si es precio de lote). Se recalcula en cada render, así los +/− lo mueven ya.
  const costs = data.items.map(lineCost);
  const sumTotal = costs.reduce((s, c) => s + (c || 0), 0);
  const sinPrecio = costs.filter((c) => c == null).length;
  const estimado = costs.some((c) => c != null) ? `$${sumTotal.toFixed(2)}` : '—';

  // Modo comprar: solo lo pendiente, agrupado por tienda disponible.
  const pending = data.items.filter((it) => !it.checked);
  const stores = [...new Set(pending.flatMap((it) => (it.prices || []).map((p) => p.store)))].sort();
  const storeItems = buyStore ? pending.filter((it) => priceAt(it, buyStore)) : [];
  const cartCost = storeItems
    .filter((it) => cart.has(it.id))
    .reduce((s, it) => s + (costFor(priceAt(it, buyStore), it.qty) || 0), 0);

  return (
    <div className="screen active">
      <div className="top-row"><div className="top-title">Lista de compras</div><div className="avatar">{data.items.length}</div></div>

      <div className="period-btns">
        {PERIODS.map((p) => (
          <button key={p} className={'pb' + (period === p ? ' active' : '')} onClick={() => setPeriod(p)}>{p}</button>
        ))}
      </div>

      <div className="period-btns">
        <button className={'pb' + (buyMode ? ' active' : '')} onClick={toggleBuyMode}>
          🛒 {buyMode ? 'Salir de modo comprar' : 'Modo comprar'}
        </button>
      </div>

      {buyMode ? (
        <>
          <div className="period-btns">
            {stores.map((s) => (
              <button key={s} className={'pb' + (buyStore === s ? ' active' : '')} onClick={() => { setBuyStore(s); setCart(new Set()); }}>{s}</button>
            ))}
          </div>

          {!buyStore && (
            <div className="loading-msg">
              {stores.length === 0 ? 'Ningún pendiente tiene precio por tienda todavía' : 'Elige una tienda'}
            </div>
          )}

          {buyStore && (
            <>
              <div className="list-summary">
                <div>
                  <div className="lbl-s">Carrito · {buyStore}</div>
                  <div className="amt-big">${cartCost.toFixed(2)}</div>
                </div>
                <button className="pb" disabled={cart.size === 0 || buying} style={{ opacity: cart.size === 0 || buying ? 0.5 : 1 }} onClick={markBought}>
                  Marcar comprados ({cart.size})
                </button>
              </div>

              {storeItems.length === 0 && <div className="loading-msg">Nada pendiente en {buyStore}</div>}

              {storeItems.map((it) => {
                const entry = priceAt(it, buyStore);
                const cost = costFor(entry, it.qty);
                return (
                  <div className={'shop-item' + (cart.has(it.id) ? ' checked' : '')} key={it.id}>
                    <div className="si-row">
                      <div className="checkbox" onClick={() => toggleCart(it.id)}>
                        {cart.has(it.id) && <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="3"><path d="M20 6L9 17l-5-5" /></svg>}
                      </div>
                      <div className="si-name">{it.name}</div>
                      <div className="stepper"><span className="n">{stepCount(it)}</span></div>
                    </div>
                    <div className="price-tags">
                      <span className="ptag best">{entry.store} {entry.price}</span>
                      {cost != null && <span className="ptag sub">= ${cost.toFixed(2)}</span>}
                    </div>
                  </div>
                );
              })}
            </>
          )}
        </>
      ) : (
        <>
          <div className="list-summary">
            <div>
              <div className="lbl-s">Estimado · {period}</div>
              <div className="amt-big">{estimado}</div>
              {sinPrecio > 0 && <div className="lbl-s" style={{ color: 'var(--faint)' }}>{sinPrecio} sin precio</div>}
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
                      <span className="n">{stepCount(it)}</span>
                      <button onClick={() => step(it, 1)}>+</button>
                    </div>
                  </div>
                  <div className="price-tags">
                    {it.prices.map((p, i) => (
                      <span key={i} className={'ptag' + (p.best ? ' best' : '')}>{p.store} {p.price}</span>
                    ))}
                    {lineCost(it) != null && <span className="ptag sub">= ${lineCost(it).toFixed(2)}</span>}
                  </div>
                </div>
              ))}
            </div>
          ))}
        </>
      )}
    </div>
  );
}
