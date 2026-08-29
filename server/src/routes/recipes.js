import { Router } from 'express';
import db from '../db.js';

const router = Router();

function fullRecipe(row) {
  const ings = db.prepare('SELECT text, missing FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort_order').all(row.id);
  const steps = db.prepare('SELECT text FROM recipe_steps WHERE recipe_id = ? ORDER BY step_number').all(row.id).map((s) => s.text);
  return { ...row, tags: JSON.parse(row.tags), ingredients: ings.map((i) => ({ text: i.text, missing: Boolean(i.missing) })), steps };
}

router.get('/', (req, res) => {
  const rows = db.prepare('SELECT * FROM recipes ORDER BY id').all();
  res.json(rows.map(fullRecipe));
});

router.get('/:slug', (req, res) => {
  const row = db.prepare('SELECT * FROM recipes WHERE slug = ? OR id = ?').get(req.params.slug, req.params.slug);
  if (!row) return res.status(404).json({ error: 'no encontrado' });
  res.json(fullRecipe(row));
});

router.post('/', (req, res) => {
  const { slug, title, description = '', tags = [], time_label = '', gradient = '', ingredients = [], steps = [] } = req.body;
  if (!slug || !title) return res.status(400).json({ error: 'slug y title son requeridos' });

  const tx = db.transaction(() => {
    const info = db.prepare(
      `INSERT INTO recipes (slug, title, description, tags, time_label, gradient, source) VALUES (?,?,?,?,?,?, 'custom')`
    ).run(slug, title, description, JSON.stringify(tags), time_label, gradient);
    const id = info.lastInsertRowid;
    const insIng = db.prepare('INSERT INTO recipe_ingredients (recipe_id, text, missing, sort_order) VALUES (?,?,?,?)');
    ingredients.forEach((ing, i) => insIng.run(id, typeof ing === 'string' ? ing : ing.text, ing.missing ? 1 : 0, i));
    const insStep = db.prepare('INSERT INTO recipe_steps (recipe_id, step_number, text) VALUES (?,?,?)');
    steps.forEach((s, i) => insStep.run(id, i + 1, s));
    return id;
  });

  const id = tx();
  res.status(201).json(fullRecipe(db.prepare('SELECT * FROM recipes WHERE id = ?').get(id)));
});

router.delete('/:id', (req, res) => {
  db.prepare('DELETE FROM recipes WHERE id = ?').run(req.params.id);
  res.status(204).end();
});

export default router;
