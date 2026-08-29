import { Router } from 'express';
import db from '../db.js';

const router = Router();

router.get('/', (req, res) => {
  res.json(db.prepare('SELECT * FROM meal_memory ORDER BY id DESC LIMIT 50').all());
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM meal_memory WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
