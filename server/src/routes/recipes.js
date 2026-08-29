import { Router } from 'express';
import { query, one } from '../db.js';

const router = Router();

async function fullRecipe(row) {
  const ings = await query('SELECT text, missing FROM recipe_ingredients WHERE recipe_id = $1 ORDER BY sort_order', [row.id]);
  const stepsRows = await query('SELECT text FROM recipe_steps WHERE recipe_id = $1 ORDER BY step_number', [row.id]);
  return {
    ...row,
    tags: JSON.parse(row.tags),
    ingredients: ings.map((i) => ({ text: i.text, missing: Boolean(i.missing) })),
    steps: stepsRows.map((s) => s.text),
  };
}

router.get('/', async (req, res) => {
  const rows = await query('SELECT * FROM recipes ORDER BY id');
  res.json(await Promise.all(rows.map(fullRecipe)));
});

router.get('/:slug', async (req, res) => {
  const row = await one('SELECT * FROM recipes WHERE slug = $1 OR id::text = $1', [req.params.slug]);
  if (!row) return res.status(404).json({ error: 'no encontrado' });
  res.json(await fullRecipe(row));
});

router.post('/', async (req, res) => {
  const { slug, title, description = '', tags = [], time_label = '', gradient = '', ingredients = [], steps = [] } = req.body;
  if (!slug || !title) return res.status(400).json({ error: 'slug y title son requeridos' });

  const recipe = await one(
    `INSERT INTO recipes (slug, title, description, tags, time_label, gradient, source) VALUES ($1,$2,$3,$4,$5,$6,'custom') RETURNING *`,
    [slug, title, description, JSON.stringify(tags), time_label, gradient]
  );
  for (let i = 0; i < ingredients.length; i++) {
    const ing = ingredients[i];
    await query('INSERT INTO recipe_ingredients (recipe_id, text, missing, sort_order) VALUES ($1,$2,$3,$4)', [
      recipe.id, typeof ing === 'string' ? ing : ing.text, Boolean(ing.missing), i,
    ]);
  }
  for (let i = 0; i < steps.length; i++) {
    await query('INSERT INTO recipe_steps (recipe_id, step_number, text) VALUES ($1,$2,$3)', [recipe.id, i + 1, steps[i]]);
  }

  res.status(201).json(await fullRecipe(recipe));
});

router.delete('/:id', async (req, res) => {
  await query('DELETE FROM recipes WHERE id = $1', [req.params.id]);
  res.status(204).end();
});

export default router;
