import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';

export default function Precios() {
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  useEffect(() => {
    api.prices.products().then(setProducts).finally(() => setLoading(false));
  }, []);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return products;
    return products.filter((p) => p.name.toLowerCase().includes(q) || (p.brand || '').toLowerCase().includes(q));
  }, [products, search]);

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  return (
    <div className="screen active">
      <div className="top-row">
        <div className="top-title">Precios</div>
        <div className="avatar">{products.length}</div>
      </div>

      <div className="search-bar">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#9AA49D" strokeWidth="2"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
        <input placeholder="Buscar producto o marca..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      {filtered.length === 0 && <div className="loading-msg">Sin productos con precio todavía</div>}

      {filtered.map((p) => (
        <div className="pr" key={p.id} style={{ flexDirection: 'column', alignItems: 'stretch', gap: 4 }}>
          <div className="pi">
            <div className="pn">{p.name}</div>
            <div className="pm">{p.brand || 'Sin marca'}</div>
          </div>
          <div className="price-tags">
            {p.prices.length === 0 && <span className="ptag">Sin precios cargados</span>}
            {p.prices.map((pr, i) => (
              <span key={pr.id} className={'ptag' + (i === 0 ? ' best' : '')}>
                {pr.provider_name} ${Number(pr.price).toFixed(2)}{pr.unit ? `/${pr.unit}` : ''}
              </span>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
