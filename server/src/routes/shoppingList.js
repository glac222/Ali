import { Router } from 'express';
import db from '../db.js';

const router = Router();

function deaccent(s) {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function basenameKey(name) {
  return deaccent(String(name).split(' · ')[0] || name);
}

let priceCatalogCache = null;
function realPriceCatalog() {
  if (priceCatalogCache) return priceCatalogCache;
  const rows = db.prepare(
    `SELECT products.name AS product_name, product_prices.price, product_prices.unit, providers.name AS provider_name
     FROM product_prices
     JOIN products ON products.id = product_prices.product_id
     JOIN providers ON providers.id = product_prices.provider_id`
  ).all();
  priceCatalogCache = rows.map((r) => ({ key: deaccent(r.product_name), price: r.price, unit: r.unit, provider: r.provider_name }));
  return priceCatalogCache;
}

// Precios REALES cargados (recibo o carga manual) para un ítem sin precio propio.
// No inventa nada: sin coincidencia real devuelve [] (el ítem queda "sin precio").
function realPricesForItem(name) {
  const key = basenameKey(name);
  if (!key) return [];
  const matches = realPriceCatalog().filter((p) => key.includes(p.key) || p.key.includes(key));
  if (!matches.length) return [];
  let cheapIdx = 0;
  matches.forEach((m, i) => { if (m.price < matches[cheapIdx].price) cheapIdx = i; });
  return matches.map((m, i) => ({ store: m.provider, price: `$${m.price.toFixed(2)}`, ...(i === cheapIdx ? { best: true } : {}) }));
}

router.get('/', (req, res) => {
  const period = req.query.period || '1 semana';
  const rows = db.prepare('SELECT * FROM shopping_list_items WHERE period = ? ORDER BY sort_order').all(period);
  const totalRow = db.prepare('SELECT amount FROM shopping_list_totals WHERE period = ?').get(period);
  priceCatalogCache = null;
  res.json({
    period,
    total: totalRow ? totalRow.amount : null,
    items: rows.map((r) => {
      const prices = JSON.parse(r.prices);
      return { ...r, prices: prices.length ? prices : realPricesForItem(r.name), checked: Boolean(r.checked) };
    }),
  });
});

router.get('/totals', (req, res) => {
  const rows = db.prepare('SELECT * FROM shopping_list_totals').all();
  res.json(Object.fromEntries(rows.map((r) => [r.period, r.amount])));
});

router.post('/', (req, res) => {
  const { period = '1 semana', group_label = '', name, qty = 1, prices = [] } = req.body;
  if (!name) return res.status(400).json({ error: 'name es requerido' });
  const maxOrder = db.prepare('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items WHERE period = ?').get(period).m;
  const info = db.prepare(
    `INSERT INTO shopping_list_items (period, group_label, name, qty, prices, sort_order) VALUES (?,?,?,?,?,?)`
  ).run(period, group_label, name, qty, JSON.stringify(prices), maxOrder + 1);
  const row = db.prepare('SELECT * FROM shopping_list_items WHERE id = ?').get(info.lastInsertRowid);
  res.status(201).json({ ...row, prices: JSON.parse(row.prices), checked: Boolean(row.checked) });
});

router.put('/:id', (req, res) => {
  const existing = db.prepare('SELECT * FROM shopping_list_items WHERE id = ?').get(req.params.id);
  if (!existing) return res.status(404).json({ error: 'no encontrado' });
  const merged = { ...existing, ...req.body };
  const prices = typeof merged.prices === 'string' ? merged.prices : JSON.stringify(merged.prices);
  db.prepare(
    `UPDATE shopping_list_items SET group_label=?, name=?, qty=?, checked=?, prices=? WHERE id=?`
  ).run(merged.group_label, merged.name, merged.qty, merged.checked ? 1 : 0, prices, req.params.id);
  const row = db.prepare('SELECT * FROM shopping_list_items WHERE id = ?').get(req.params.id);
  res.json({ ...row, prices: JSON.parse(row.prices), checked: Boolean(row.checked) });
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM shopping_list_items WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
