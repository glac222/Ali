import { Router } from 'express';
import { query } from '../db.js';

const router = Router();

router.get('/', async (req, res) => {
  res.json(await query('SELECT * FROM meal_memory ORDER BY id DESC LIMIT 50'));
});

router.delete('/:id', async (req, res) => {
  await query('DELETE FROM meal_memory WHERE id = $1', [req.params.id]);
  res.status(204).end();
});

export default router;
