import { Router } from 'express';
import db from '../db.js';

const router = Router();

router.get('/', (req, res) => {
  res.json(db.prepare('SELECT * FROM discoveries ORDER BY id').all());
});

router.post('/', (req, res) => {
  const { title, source = '', link = '', meta = '', gradient = '' } = req.body;
  if (!title) return res.status(400).json({ error: 'title es requerido' });
  const info = db.prepare(
    `INSERT INTO discoveries (title, source, link, meta, gradient) VALUES (?,?,?,?,?)`
  ).run(title, source, link, meta, gradient);
  res.status(201).json(db.prepare('SELECT * FROM discoveries WHERE id = ?').get(info.lastInsertRowid));
});

router.put('/:id/rating', (req, res) => {
  const { rating } = req.body;
  if (typeof rating !== 'number' || rating < 0 || rating > 5) return res.status(400).json({ error: 'rating debe ser 0-5' });
  db.prepare('UPDATE discoveries SET rating = ? WHERE id = ?').run(rating, req.params.id);
  res.json(db.prepare('SELECT * FROM discoveries WHERE id = ?').get(req.params.id));
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM discoveries WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
