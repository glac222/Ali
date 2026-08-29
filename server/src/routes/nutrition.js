import { Router } from 'express';
import { one } from '../db.js';

const router = Router();

function today() {
  return new Date().toISOString().slice(0, 10);
}

router.get('/today', async (req, res) => {
  let row = await one('SELECT * FROM nutrition_log WHERE log_date = $1', [today()]);
  if (!row) {
    row = await one(
      `INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
       VALUES ($1, 0, 2800, 0, 140, 0, 300, 0, 80) RETURNING *`,
      [today()]
    );
  }
  res.json(row);
});

router.put('/today', async (req, res) => {
  const existing = (await one('SELECT * FROM nutrition_log WHERE log_date = $1', [today()])) || {
    calories: 0, calories_target: 2800, protein: 0, protein_target: 140, carbs: 0, carbs_target: 300, fat: 0, fat_target: 80,
  };
  const merged = { ...existing, ...req.body };
  const row = await one(
    `INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)
     ON CONFLICT (log_date) DO UPDATE SET calories=excluded.calories, calories_target=excluded.calories_target,
       protein=excluded.protein, protein_target=excluded.protein_target, carbs=excluded.carbs, carbs_target=excluded.carbs_target,
       fat=excluded.fat, fat_target=excluded.fat_target
     RETURNING *`,
    [today(), merged.calories, merged.calories_target, merged.protein, merged.protein_target, merged.carbs, merged.carbs_target, merged.fat, merged.fat_target]
  );
  res.json(row);
});

export default router;
