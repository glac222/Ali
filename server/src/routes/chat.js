import { Router } from 'express';
import db from '../db.js';
import { buildSystemPrompt, DEFAULT_LIST_PERIOD } from '../services/systemPrompt.js';
import { getChatReply, isConfigured } from '../services/deepseek.js';

const router = Router();

function upsertByName(table, name) {
  const existing = db.prepare(`SELECT * FROM ${table} WHERE name = ?`).get(name);
  if (existing) return existing;
  const info = db.prepare(`INSERT INTO ${table} (name) VALUES (?)`).run(name);
  return db.prepare(`SELECT * FROM ${table} WHERE id = ?`).get(info.lastInsertRowid);
}

function saveRealPrices(entries) {
  const saved = [];
  const upsertPrice = db.prepare(
    `INSERT INTO product_prices (product_id, provider_id, price, unit, updated_at)
     VALUES (?,?,?,?,datetime('now'))
     ON CONFLICT(product_id, provider_id) DO UPDATE SET price = excluded.price, unit = excluded.unit, updated_at = datetime('now')`
  );
  for (const [productName, provider, priceStr] of entries) {
    if (!productName || !provider || !priceStr) continue;
    const price = parseFloat(priceStr.replace(/[^\d.,]/g, '').replace(',', '.'));
    if (!(price > 0)) continue;
    const product = upsertByName('products', productName);
    const providerRow = upsertByName('providers', provider);
    upsertPrice.run(product.id, providerRow.id, price, '');
    saved.push(`${productName} — ${provider} ${priceStr}`);
  }
  return saved;
}

function addItemsToShoppingList(names) {
  const added = [];
  const findExisting = db.prepare(
    `SELECT id FROM shopping_list_items WHERE period = ? AND checked = 0 AND LOWER(name) = LOWER(?)`
  );
  const maxOrderStmt = db.prepare('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items WHERE period = ?');
  const insert = db.prepare(
    `INSERT INTO shopping_list_items (period, group_label, name, qty, prices, sort_order) VALUES (?,?,?,1,'[]',?)`
  );
  for (const name of names) {
    if (!name) continue;
    if (findExisting.get(DEFAULT_LIST_PERIOD, name)) continue;
    const sortOrder = maxOrderStmt.get(DEFAULT_LIST_PERIOD).m + 1;
    insert.run(DEFAULT_LIST_PERIOD, 'Del chat', name, sortOrder);
    added.push(name);
  }
  return added;
}

router.get('/status', (req, res) => {
  res.json({ configured: isConfigured() });
});

router.get('/history', (req, res) => {
  res.json(db.prepare('SELECT * FROM chat_messages ORDER BY id ASC LIMIT 200').all());
});

router.post('/', async (req, res) => {
  const { message, history = [] } = req.body;
  if (!message || !message.trim()) return res.status(400).json({ error: 'message es requerido' });

  db.prepare('INSERT INTO chat_messages (role, content) VALUES (?,?)').run('user', message);

  try {
    const systemPrompt = buildSystemPrompt();
    const fullHistory = [...history, { role: 'user', content: message }];
    const { reply: rawReply, simulated } = await getChatReply(systemPrompt, fullHistory);

    const memMatches = [...rawReply.matchAll(/\[MEMORIA:([^\]]+)\]/g)];
    const insertMem = db.prepare('INSERT INTO meal_memory (entry) VALUES (?)');
    const savedMemories = memMatches.map((m) => {
      const entry = m[1].trim();
      insertMem.run(entry);
      return entry;
    });
    const listMatches = [...rawReply.matchAll(/\[LISTA:([^\]]+)\]/g)];
    const addedToList = addItemsToShoppingList(listMatches.map((m) => m[1].trim()));
    const priceMatches = [...rawReply.matchAll(/\[PRECIO:([^\]]+)\]/g)];
    const savedPrices = saveRealPrices(priceMatches.map((m) => m[1].split('|').map((s) => s.trim())));
    const reply = rawReply
      .replace(/\[MEMORIA:[^\]]+\]/g, '')
      .replace(/\[LISTA:[^\]]+\]/g, '')
      .replace(/\[PRECIO:[^\]]+\]/g, '')
      .trim();

    db.prepare('INSERT INTO chat_messages (role, content) VALUES (?,?)').run('assistant', reply);

    res.json({ reply, simulated, savedMemories, addedToList, savedPrices });
  } catch (err) {
    console.error('Error en chat:', err);
    res.status(502).json({ error: 'Error de conexión con DeepSeek. Verifica la API key en el servidor.' });
  }
});

export default router;
