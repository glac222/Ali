import { Router } from 'express';
import db from '../db.js';
import { buildSystemPrompt, DEFAULT_LIST_PERIOD } from '../services/systemPrompt.js';
import { getChatReply, isConfigured } from '../services/deepseek.js';

const router = Router();

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
    const reply = rawReply.replace(/\[MEMORIA:[^\]]+\]/g, '').replace(/\[LISTA:[^\]]+\]/g, '').trim();

    db.prepare('INSERT INTO chat_messages (role, content) VALUES (?,?)').run('assistant', reply);

    res.json({ reply, simulated, savedMemories, addedToList });
  } catch (err) {
    console.error('Error en chat:', err);
    res.status(502).json({ error: 'Error de conexión con DeepSeek. Verifica la API key en el servidor.' });
  }
});

export default router;
