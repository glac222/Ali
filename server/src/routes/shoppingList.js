import { Router } from 'express';
import { query, one } from '../db.js';

const router = Router();

router.get('/', async (req, res) => {
  const period = req.query.period || '1 semana';
  const rows = await query('SELECT * FROM shopping_list_items WHERE period = $1 ORDER BY sort_order', [period]);
  const totalRow = await one('SELECT amount FROM shopping_list_totals WHERE period = $1', [period]);
  res.json({
    period,
    total: totalRow ? totalRow.amount : null,
    items: rows.map((r) => ({ ...r, prices: JSON.parse(r.prices) })),
  });
});

router.get('/totals', async (req, res) => {
  const rows = await query('SELECT * FROM shopping_list_totals');
  res.json(Object.fromEntries(rows.map((r) => [r.period, r.amount])));
});

router.post('/', async (req, res) => {
  const { period = '1 semana', group_label = '', name, qty = 1, prices = [] } = req.body;
  if (!name) return res.status(400).json({ error: 'name es requerido' });
  const maxOrder = await one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items WHERE period = $1', [period]);
  const row = await one(
    `INSERT INTO shopping_list_items (period, group_label, name, qty, prices, sort_order) VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
    [period, group_label, name, qty, JSON.stringify(prices), maxOrder.m + 1]
  );
  res.status(201).json({ ...row, prices: JSON.parse(row.prices) });
});

router.put('/:id', async (req, res) => {
  const existing = await one('SELECT * FROM shopping_list_items WHERE id = $1', [req.params.id]);
  if (!existing) return res.status(404).json({ error: 'no encontrado' });
  const merged = { ...existing, ...req.body };
  const prices = typeof merged.prices === 'string' ? merged.prices : JSON.stringify(merged.prices);
  const row = await one(
    `UPDATE shopping_list_items SET group_label=$1, name=$2, qty=$3, checked=$4, prices=$5 WHERE id=$6 RETURNING *`,
    [merged.group_label, merged.name, merged.qty, Boolean(merged.checked), prices, req.params.id]
  );
  res.json({ ...row, prices: JSON.parse(row.prices) });
});

router.delete('/:id', async (req, res) => {
  await query('DELETE FROM shopping_list_items WHERE id = $1', [req.params.id]);
  res.status(204).end();
});

export default router;
