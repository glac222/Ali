import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';

const PERIODS = ['3 días', '1 semana', '2 semanas', '3 semanas', '1 mes'];

function parsePrice(str) {
  const m = String(str).match(/[\d.]+/);
  return m ? parseFloat(m[0]) : 0;
}

function priceForStore(item, store) {
  if (!item.prices.length) return null;
  if (store === 'Todas') return item.prices.find((p) => p.best) || item.prices[0];
  return item.prices.find((p) => p.store === store) || null;
}

export default function Lista() {
  const [period, setPeriod] = useState('1 semana');
  const [data, setData] = useState({ items: [], total: null });
  const [loading, setLoading] = useState(true);
  const [shopMode, setShopMode] = useState(false);
  const [store, setStore] = useState('Todas');
  const [cart, setCart] = useState(new Set());

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

  const stores = useMemo(() => {
    const set = new Set();
    data.items.forEach((it) => it.prices.forEach((p) => set.add(p.store)));
    return ['Todas', ...Array.from(set).sort()];
  }, [data.items]);

  const shopItems = useMemo(
    () => data.items.filter((it) => !it.checked && (store === 'Todas' || it.prices.some((p) => p.store === store))),
    [data.items, store]
  );

  const cartTotal = shopItems
    .filter((it) => cart.has(it.id))
    .reduce((sum, it) => sum + parsePrice(priceForStore(it, store)?.price) * it.qty, 0);

  function toggleCart(id) {
    setCart((c) => {
      const next = new Set(c);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  async function markPurchased() {
    const ids = Array.from(cart);
    await Promise.all(ids.map((id) => api.shoppingList.update(id, { checked: true })));
    setData((d) => ({ ...d, items: d.items.map((i) => (ids.includes(i.id) ? { ...i, checked: true } : i)) }));
    setCart(new Set());
  }

  function toggleShopMode() {
    setShopMode((v) => !v);
    setCart(new Set());
  }

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  const groups = {};
  for (const it of data.items) {
    groups[it.group_label] = groups[it.group_label] || [];
    groups[it.group_label].push(it);
  }

  return (
    <div className="screen active">
      <div className="top-row">
        <div className="top-title">Lista de compras</div>
        <div style={{ display: 'flex', gap: 7, alignItems: 'center' }}>
          <button className={'pb' + (shopMode ? ' active' : '')} onClick={toggleShopMode}>🛒 Modo comprar</button>
          <div className="avatar">{data.items.length}</div>
        </div>
      </div>

      {!shopMode && (
        <div className="period-btns">
          {PERIODS.map((p) => (
            <button key={p} className={'pb' + (period === p ? ' active' : '')} onClick={() => setPeriod(p)}>{p}</button>
          ))}
        </div>
      )}

      <div className="list-summary">
        <div>
          <div className="lbl-s">{shopMode ? `En el carrito · ${store}` : `Estimado · ${period}`}</div>
          <div className="amt-big">{shopMode ? `$${cartTotal.toFixed(2)}` : (data.total || '—')}</div>
        </div>
        {!shopMode && (
          <select className="period-select" defaultValue="Tienda">
            <option>Tienda</option>
            <option>Precio</option>
            <option>Urgencia</option>
          </select>
        )}
      </div>

      {shopMode ? (
        <>
          <div className="chips">
            {stores.map((s) => (
              <div key={s} className={'chip' + (store === s ? ' active' : '')} onClick={() => setStore(s)}>{s}</div>
            ))}
          </div>

          {shopItems.length === 0 && <div className="loading-msg">Nada pendiente para {store === 'Todas' ? 'comprar' : store}</div>}

          {shopItems.map((it) => {
            const p = priceForStore(it, store);
            return (
              <div className={'shop-item' + (cart.has(it.id) ? ' checked' : '')} key={it.id}>
                <div className="si-row">
                  <div className="checkbox" onClick={() => toggleCart(it.id)}>
                    {cart.has(it.id) && <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="3"><path d="M20 6L9 17l-5-5" /></svg>}
                  </div>
                  <div className="si-name">{it.name} <span style={{ color: 'var(--faint)' }}>×{it.qty}</span></div>
                  <div className="pq">{p ? p.price : '—'}</div>
                </div>
              </div>
            );
          })}

          {cart.size > 0 && (
            <div className="list-summary" style={{ marginTop: 10 }}>
              <div>
                <div className="lbl-s">{cart.size} producto{cart.size > 1 ? 's' : ''} seleccionado{cart.size > 1 ? 's' : ''}</div>
                <div className="amt-big">${cartTotal.toFixed(2)}</div>
              </div>
              <button className="btn-p" onClick={markPurchased}>Marcar comprados</button>
            </div>
          )}
        </>
      ) : (
        <>
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
          <a className="h-link">📋 Historial de compras y precios →</a>
        </>
      )}
    </div>
  );
}
