import { Router } from 'express';
import db from '../db.js';

const router = Router();

router.get('/', (req, res) => {
  const period = req.query.period || '1 semana';
  const rows = db.prepare('SELECT * FROM shopping_list_items WHERE period = ? ORDER BY sort_order').all(period);
  const totalRow = db.prepare('SELECT amount FROM shopping_list_totals WHERE period = ?').get(period);
  res.json({
    period,
    total: totalRow ? totalRow.amount : null,
    items: rows.map((r) => ({ ...r, prices: JSON.parse(r.prices), checked: Boolean(r.checked) })),
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
