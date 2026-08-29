import { Router } from 'express';
import db from '../db.js';

const router = Router();

router.get('/today', (req, res) => {
  const today = new Date().toISOString().slice(0, 10);
  let row = db.prepare('SELECT * FROM nutrition_log WHERE log_date = ?').get(today);
  if (!row) {
    db.prepare(
      `INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
       VALUES (?, 0, 2800, 0, 140, 0, 300, 0, 80)`
    ).run(today);
    row = db.prepare('SELECT * FROM nutrition_log WHERE log_date = ?').get(today);
  }
  res.json(row);
});

router.put('/today', (req, res) => {
  const today = new Date().toISOString().slice(0, 10);
  const existing = db.prepare('SELECT * FROM nutrition_log WHERE log_date = ?').get(today) || {
    calories: 0, calories_target: 2800, protein: 0, protein_target: 140, carbs: 0, carbs_target: 300, fat: 0, fat_target: 80,
  };
  const merged = { ...existing, ...req.body };
  db.prepare(
    `INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
     VALUES (?,?,?,?,?,?,?,?,?)
     ON CONFLICT(log_date) DO UPDATE SET calories=excluded.calories, calories_target=excluded.calories_target,
       protein=excluded.protein, protein_target=excluded.protein_target, carbs=excluded.carbs, carbs_target=excluded.carbs_target,
       fat=excluded.fat, fat_target=excluded.fat_target`
  ).run(today, merged.calories, merged.calories_target, merged.protein, merged.protein_target, merged.carbs, merged.carbs_target, merged.fat, merged.fat_target);
  res.json(db.prepare('SELECT * FROM nutrition_log WHERE log_date = ?').get(today));
});

export default router;
