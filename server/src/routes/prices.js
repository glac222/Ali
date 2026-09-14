import { Router } from 'express';
import db from '../db.js';

const router = Router();

function upsertByName(table, name) {
  const existing = db.prepare(`SELECT * FROM ${table} WHERE name = ?`).get(name);
  if (existing) return existing;
  const info = db.prepare(`INSERT INTO ${table} (name) VALUES (?)`).run(name);
  return db.prepare(`SELECT * FROM ${table} WHERE id = ?`).get(info.lastInsertRowid);
}

router.get('/providers', (req, res) => {
  res.json(db.prepare('SELECT * FROM providers ORDER BY name').all());
});

router.get('/products', (req, res) => {
  const products = db.prepare('SELECT * FROM products ORDER BY name').all();
  const prices = db.prepare(
    `SELECT product_prices.*, providers.name AS provider_name FROM product_prices
     JOIN providers ON providers.id = product_prices.provider_id
     ORDER BY product_prices.price ASC`
  ).all();
  res.json(products.map((p) => ({ ...p, prices: prices.filter((pr) => pr.product_id === p.id) })));
});

router.post('/products/:name/prices', (req, res) => {
  const { provider, price, unit = '' } = req.body;
  if (!provider || price == null) return res.status(400).json({ error: 'provider y price son requeridos' });

  const product = upsertByName('products', req.params.name);
  const providerRow = upsertByName('providers', provider);

  db.prepare(
    `INSERT INTO product_prices (product_id, provider_id, price, unit, updated_at)
     VALUES (?,?,?,?,datetime('now'))
     ON CONFLICT(product_id, provider_id) DO UPDATE SET price = excluded.price, unit = excluded.unit, updated_at = datetime('now')`
  ).run(product.id, providerRow.id, price, unit);

  const rows = db.prepare(
    `SELECT product_prices.*, providers.name AS provider_name FROM product_prices
     JOIN providers ON providers.id = product_prices.provider_id
     WHERE product_id = ? ORDER BY price ASC`
  ).all(product.id);
  res.status(201).json({ ...product, prices: rows });
});

export default router;
