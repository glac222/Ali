import { Router } from 'express';
import db from '../db.js';
import { DEFAULT_LIST_PERIOD } from '../services/systemPrompt.js';

const router = Router();

function addToShoppingListIfMissing(name) {
  const existing = db.prepare(
    `SELECT id FROM shopping_list_items WHERE period = ? AND checked = 0 AND LOWER(name) = LOWER(?)`
  ).get(DEFAULT_LIST_PERIOD, name);
  if (existing) return;
  const maxOrder = db.prepare('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items WHERE period = ?').get(DEFAULT_LIST_PERIOD).m;
  db.prepare(
    `INSERT INTO shopping_list_items (period, group_label, name, qty, prices, sort_order) VALUES (?,?,?,1,'[]',?)`
  ).run(DEFAULT_LIST_PERIOD, 'Despensa', name, maxOrder + 1);
}

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

  if (merged.status === 'agotado' && existing.status !== 'agotado') {
    addToShoppingListIfMissing(merged.name);
  }

  res.json(db.prepare('SELECT * FROM pantry_items WHERE id = ?').get(req.params.id));
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM pantry_items WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
