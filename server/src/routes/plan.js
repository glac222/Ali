import { Router } from 'express';
import { query, one } from '../db.js';

const router = Router();

router.get('/', async (req, res) => {
  const rows = await query('SELECT * FROM meal_plan ORDER BY sort_order');
  const byDay = {};
  for (const r of rows) {
    byDay[r.weekday] = byDay[r.weekday] || [];
    byDay[r.weekday].push(r);
  }
  res.json(byDay);
});

router.get('/:weekday', async (req, res) => {
  const rows = await query('SELECT * FROM meal_plan WHERE weekday = $1 ORDER BY sort_order', [req.params.weekday]);
  res.json(rows);
});

router.post('/', async (req, res) => {
  const { weekday, meal_type, title, detail = '', optional = false } = req.body;
  if (!weekday || !meal_type || !title) return res.status(400).json({ error: 'weekday, meal_type y title son requeridos' });
  const maxOrder = await one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM meal_plan');
  const row = await one(
    `INSERT INTO meal_plan (weekday, meal_type, title, detail, optional, sort_order) VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
    [weekday, meal_type, title, detail, Boolean(optional), maxOrder.m + 1]
  );
  res.status(201).json(row);
});

router.put('/:id', async (req, res) => {
  const existing = await one('SELECT * FROM meal_plan WHERE id = $1', [req.params.id]);
  if (!existing) return res.status(404).json({ error: 'no encontrado' });
  const merged = { ...existing, ...req.body };
  const row = await one(
    `UPDATE meal_plan SET weekday=$1, meal_type=$2, title=$3, detail=$4, optional=$5 WHERE id=$6 RETURNING *`,
    [merged.weekday, merged.meal_type, merged.title, merged.detail, Boolean(merged.optional), req.params.id]
  );
  res.json(row);
});

router.delete('/:id', async (req, res) => {
  await query('DELETE FROM meal_plan WHERE id = $1', [req.params.id]);
  res.status(204).end();
});

export default router;
