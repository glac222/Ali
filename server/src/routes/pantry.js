import { Router } from 'express';
import db from '../db.js';

const router = Router();

router.get('/', (req, res) => {
  const rows = db.prepare('SELECT * FROM pantry_items ORDER BY category, id').all();
  res.json(rows);
});

router.post('/', (req, res) => {
  const { name, quantity = '', category = 'granos', expires_label = '', status = 'ok', notes = '' } = req.body;
  if (!name) return res.status(400).json({ error: 'name es requerido' });
  const info = db.prepare(
    `INSERT INTO pantry_items (name, quantity, category, expires_label, status, notes) VALUES (?,?,?,?,?,?)`
  ).run(name, quantity, category, expires_label, status, notes);
  const row = db.prepare('SELECT * FROM pantry_items WHERE id = ?').get(info.lastInsertRowid);
  res.status(201).json(row);
});

router.put('/:id', (req, res) => {
  const existing = db.prepare('SELECT * FROM pantry_items WHERE id = ?').get(req.params.id);
  if (!existing) return res.status(404).json({ error: 'no encontrado' });
  const merged = { ...existing, ...req.body };
  db.prepare(
    `UPDATE pantry_items SET name=?, quantity=?, category=?, expires_label=?, status=?, notes=?, updated_at=datetime('now') WHERE id=?`
  ).run(merged.name, merged.quantity, merged.category, merged.expires_label, merged.status, merged.notes, req.params.id);
  res.json(db.prepare('SELECT * FROM pantry_items WHERE id = ?').get(req.params.id));
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM pantry_items WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
