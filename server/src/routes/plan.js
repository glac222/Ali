import { Router } from 'express';
import db from '../db.js';

const router = Router();

router.get('/', (req, res) => {
  const rows = db.prepare('SELECT * FROM meal_plan ORDER BY sort_order').all();
  const byDay = {};
  for (const r of rows) {
    byDay[r.weekday] = byDay[r.weekday] || [];
    byDay[r.weekday].push(r);
  }
  res.json(byDay);
});

router.get('/:weekday', (req, res) => {
  const rows = db.prepare('SELECT * FROM meal_plan WHERE weekday = ? ORDER BY sort_order').all(req.params.weekday);
  res.json(rows);
});

router.post('/', (req, res) => {
  const { weekday, meal_type, title, detail = '', optional = false } = req.body;
  if (!weekday || !meal_type || !title) return res.status(400).json({ error: 'weekday, meal_type y title son requeridos' });
  const maxOrder = db.prepare('SELECT COALESCE(MAX(sort_order), -1) AS m FROM meal_plan').get().m;
  const info = db.prepare(
    `INSERT INTO meal_plan (weekday, meal_type, title, detail, optional, sort_order) VALUES (?,?,?,?,?,?)`
  ).run(weekday, meal_type, title, detail, optional ? 1 : 0, maxOrder + 1);
  res.status(201).json(db.prepare('SELECT * FROM meal_plan WHERE id = ?').get(info.lastInsertRowid));
});

router.put('/:id', (req, res) => {
  const existing = db.prepare('SELECT * FROM meal_plan WHERE id = ?').get(req.params.id);
  if (!existing) return res.status(404).json({ error: 'no encontrado' });
  const merged = { ...existing, ...req.body };
  db.prepare(
    `UPDATE meal_plan SET weekday=?, meal_type=?, title=?, detail=?, optional=? WHERE id=?`
  ).run(merged.weekday, merged.meal_type, merged.title, merged.detail, merged.optional ? 1 : 0, req.params.id);
  res.json(db.prepare('SELECT * FROM meal_plan WHERE id = ?').get(req.params.id));
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM meal_plan WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
