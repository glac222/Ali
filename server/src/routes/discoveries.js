import { Router } from 'express';
import { query, one } from '../db.js';

const router = Router();

router.get('/', async (req, res) => {
  res.json(await query('SELECT * FROM discoveries ORDER BY id'));
});

router.post('/', async (req, res) => {
  const { title, source = '', link = '', meta = '', gradient = '' } = req.body;
  if (!title) return res.status(400).json({ error: 'title es requerido' });
  const row = await one(
    `INSERT INTO discoveries (title, source, link, meta, gradient) VALUES ($1,$2,$3,$4,$5) RETURNING *`,
    [title, source, link, meta, gradient]
  );
  res.status(201).json(row);
});

router.put('/:id/rating', async (req, res) => {
  const { rating } = req.body;
  if (typeof rating !== 'number' || rating < 0 || rating > 5) return res.status(400).json({ error: 'rating debe ser 0-5' });
  const row = await one('UPDATE discoveries SET rating = $1 WHERE id = $2 RETURNING *', [rating, req.params.id]);
  res.json(row);
});

router.delete('/:id', async (req, res) => {
  await query('DELETE FROM discoveries WHERE id = $1', [req.params.id]);
  res.status(204).end();
});

export default router;
