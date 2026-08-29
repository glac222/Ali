import { Router } from 'express';
import { query, one } from '../db.js';

const router = Router();

router.get('/', async (req, res) => {
  const rows = await query('SELECT * FROM pantry_items ORDER BY category, id');
  res.json(rows);
});

router.post('/', async (req, res) => {
  const { name, quantity = '', category = 'granos', expires_label = '', status = 'ok', notes = '' } = req.body;
  if (!name) return res.status(400).json({ error: 'name es requerido' });
  const row = await one(
    `INSERT INTO pantry_items (name, quantity, category, expires_label, status, notes) VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
    [name, quantity, category, expires_label, status, notes]
  );
  res.status(201).json(row);
});

router.put('/:id', async (req, res) => {
  const existing = await one('SELECT * FROM pantry_items WHERE id = $1', [req.params.id]);
  if (!existing) return res.status(404).json({ error: 'no encontrado' });
  const merged = { ...existing, ...req.body };
  const row = await one(
    `UPDATE pantry_items SET name=$1, quantity=$2, category=$3, expires_label=$4, status=$5, notes=$6, updated_at=NOW() WHERE id=$7 RETURNING *`,
    [merged.name, merged.quantity, merged.category, merged.expires_label, merged.status, merged.notes, req.params.id]
  );
  res.json(row);
});

router.delete('/:id', async (req, res) => {
  await query('DELETE FROM pantry_items WHERE id = $1', [req.params.id]);
  res.status(204).end();
});

export default router;
