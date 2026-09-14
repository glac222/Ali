import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';

const CATS = [
  { key: 'all', label: 'Todos' },
  { key: 'carnes', label: 'Carnes' },
  { key: 'lacteos', label: 'Lácteos' },
  { key: 'granos', label: 'Granos' },
  { key: 'vegetales', label: 'Vegetales' },
  { key: 'latas', label: 'Latas' },
];
const CAT_GROUP_LABEL = { carnes: 'Carnes y proteínas', lacteos: 'Lácteos y pan', granos: 'Granos', vegetales: 'Vegetales', latas: 'Latas y despensa' };

const EMPTY_FORM = { name: '', quantity: '', category: 'carnes', expires_label: '' };

// "Sin existencias" = agotado. En este proyecto status='re' ya es "agotado"
// (el backend lo excluye de la despensa en routes.php: WHERE status <> 're').
// Respaldo: qty_value en 0 o texto de cantidad "0"/"0 g"/"agotado".
function isOutOfStock(it) {
  if (it.status === 're') return true;
  const v = it.qty_value;
  if (v !== null && v !== undefined && v !== '' && !Number.isNaN(Number(v))) {
    return Number(v) <= 0;
  }
  const q = String(it.quantity || '').trim().toLowerCase();
  // "0", "0 g", "0.00 kg" -> agotado; pero NO "0,5 kg" ni "0.5 l".
  return q === 'agotado' || /^0([.,]0+)?(\s|$)/.test(q);
}

export default function Despensa({ onToast }) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeCat, setActiveCat] = useState('all');
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [scanOpen, setScanOpen] = useState(false);
  const [scanResult, setScanResult] = useState(false);
  const [showOut, setShowOut] = useState(false);

  useEffect(() => {
    api.pantry.list().then(setItems).finally(() => setLoading(false));
  }, []);

  const filtered = useMemo(() => {
    return items.filter((it) => {
      const matchCat = activeCat === 'all' || it.category === activeCat;
      const matchSearch = !search || it.name.toLowerCase().includes(search.toLowerCase());
      return matchCat && matchSearch;
    });
  }, [items, activeCat, search]);

  const available = useMemo(() => filtered.filter((it) => !isOutOfStock(it)), [filtered]);
  const outOfStock = useMemo(() => filtered.filter(isOutOfStock), [filtered]);

  const grouped = useMemo(() => {
    const g = {};
    for (const it of available) {
      g[it.category] = g[it.category] || [];
      g[it.category].push(it);
    }
    const order = ['carnes', 'lacteos', 'granos', 'vegetales', 'latas'];
    return Object.fromEntries(order.filter((c) => g[c]).map((c) => [c, g[c]]));
  }, [available]);

  const urgentCount = items.filter((i) => i.status === 'am' || i.status === 're').length;

  async function addItem() {
    if (!form.name.trim()) return;
    const created = await api.pantry.create(form);
    setItems((cur) => [...cur, created]);
    setForm(EMPTY_FORM);
    setShowModal(false);
    onToast?.('✓ Producto añadido');
  }

  function openScan() {
    setScanOpen(true);
    setScanResult(false);
    setTimeout(() => setScanResult(true), 1400);
  }

  async function addScannedToPantry() {
    const created = await api.pantry.create({ name: 'Atún Van Camps en agua 142g', quantity: '1 lata', category: 'latas', expires_label: '2 años' });
    setItems((cur) => [...cur, created]);
    setScanOpen(false);
    onToast?.('✓ Añadido a despensa');
  }

  async function addScannedToList() {
    try {
      await api.shoppingList.create({
        name: 'Atún Van Camps en agua 142g',
        group_label: 'Latas y despensa',
        qty: 1,
        period: '1 semana',
        source: 'scan',
      });
      setScanOpen(false);
      onToast?.('✓ Añadido a la lista');
    } catch (err) {
      onToast?.('No se pudo añadir a la lista');
    }
  }

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  return (
    <div className="screen active">
      <div className="top-row">
        <div className="top-title">Despensa</div>
        <div style={{ display: 'flex', gap: 7, alignItems: 'center' }}>
          {urgentCount > 0 && <div className="badge-count">{urgentCount}</div>}
          <div className="avatar">{items.length}</div>
        </div>
      </div>

      <div className="search-bar">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#9AA49D" strokeWidth="2"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
        <input placeholder="Buscar..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      <div className="chips">
        {CATS.map((c) => (
          <div key={c.key} className={'chip' + (activeCat === c.key ? ' active' : '')} onClick={() => setActiveCat(c.key)}>{c.label}</div>
        ))}
      </div>

      {Object.entries(grouped).map(([cat, rows]) => (
        <div key={cat}>
          <div className="cat-label">{CAT_GROUP_LABEL[cat] || cat}</div>
          {rows.map((it) => (
            <div className="pr" key={it.id}>
              <div className={'pd' + (it.status === 'am' ? ' am' : it.status === 're' ? ' re' : '')} />
              <div className="pi">
                <div className="pn">{it.name}</div>
                <div className="pm">{it.expires_label}{it.notes ? ` · ${it.notes}` : ''}</div>
              </div>
              <div className="pq">{it.quantity}</div>
            </div>
          ))}
        </div>
      ))}

      {available.length === 0 && outOfStock.length === 0 && (
        <div className="loading-msg">Sin resultados</div>
      )}
      {available.length === 0 && outOfStock.length > 0 && (
        <div className="loading-msg">Todo lo de esta vista está agotado</div>
      )}

      {outOfStock.length > 0 && (
        <div className="stock-out">
          <button className="stock-out-head" onClick={() => setShowOut((v) => !v)}>
            <div className="pd re" />
            <span>Sin existencias</span>
            <span className="stock-out-count">{outOfStock.length}</span>
            <span className={'stock-out-chev' + (showOut ? ' open' : '')}>›</span>
          </button>
          {showOut && (
            <div className="stock-out-body">
              {outOfStock.map((it) => (
                <div className="pr out" key={it.id}>
                  <div className="pd re" />
                  <div className="pi">
                    <div className="pn">{it.name}</div>
                    <div className="pm">{CAT_GROUP_LABEL[it.category] || it.category}{it.expires_label ? ` · ${it.expires_label}` : ''}{it.notes ? ` · ${it.notes}` : ''}</div>
                  </div>
                  <div className="pq">{it.quantity || 'agotado'}</div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      <button className="fab" onClick={() => setShowModal(true)}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><path d="M12 5v14M5 12h14" /></svg>
      </button>

      <div style={{ position: 'absolute', right: 71, bottom: 146, zIndex: 40 }}>
        <button className="fab" style={{ position: 'static', width: 40, height: 40, background: 'var(--ink)' }} onClick={openScan} title="Escanear código">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><rect x="3" y="6" width="18" height="12" rx="1" /><path d="M7 6v12M11 6v12M17 6v12" /></svg>
        </button>
      </div>

      <div className={'modal' + (showModal ? ' active' : '')} onClick={(e) => e.target.classList.contains('modal') && setShowModal(false)}>
        <div className="modal-box">
          <h4>Añadir producto</h4>
          <div className="field">
            <label>Nombre</label>
            <input type="text" placeholder="ej. Pollo en presas" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </div>
          <div className="field-row">
            <div className="field">
              <label>Cantidad</label>
              <input type="text" placeholder="ej. 1 kg" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
            </div>
            <div className="field">
              <label>Categoría</label>
              <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                <option value="carnes">Carnes</option>
                <option value="lacteos">Lácteos</option>
                <option value="granos">Granos</option>
                <option value="vegetales">Vegetales</option>
                <option value="latas">Latas</option>
              </select>
            </div>
          </div>
          <div className="field">
            <label>Vence en</label>
            <input type="text" placeholder="ej. 4 días / fresco ~5 días" value={form.expires_label} onChange={(e) => setForm({ ...form, expires_label: e.target.value })} />
          </div>
          <div className="modal-actions">
            <button className="btn-s" onClick={() => setShowModal(false)}>Cancelar</button>
            <button className="btn-p" onClick={addItem}>Añadir</button>
          </div>
        </div>
      </div>

      <div className={'scan-overlay' + (scanOpen ? ' active' : '')}>
        <button className="scan-close-btn" onClick={() => setScanOpen(false)}>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><path d="M18 6L6 18M6 6l12 12" /></svg>
        </button>
        <div className="scan-frame" />
        <p>Apunta al código de barras</p>
        <div className={'scan-result' + (scanResult ? ' show' : '')}>
          <div className="sn">Atún Van Camps en agua 142g</div>
          <div className="sb">7861456300034 · Van Camps</div>
          <div className="scan-acts">
            <button className="ss" onClick={addScannedToList}>+ Lista</button>
            <button className="sp" onClick={addScannedToPantry}>+ Despensa</button>
          </div>
        </div>
      </div>
    </div>
  );
}
