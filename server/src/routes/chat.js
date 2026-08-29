import { Router } from 'express';
import db from '../db.js';
import { buildSystemPrompt } from '../services/systemPrompt.js';
import { getChatReply, isConfigured } from '../services/deepseek.js';

const router = Router();

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
    const reply = rawReply.replace(/\[MEMORIA:[^\]]+\]/g, '').trim();

    db.prepare('INSERT INTO chat_messages (role, content) VALUES (?,?)').run('assistant', reply);

    res.json({ reply, simulated, savedMemories });
  } catch (err) {
    console.error('Error en chat:', err);
    res.status(502).json({ error: 'Error de conexión con DeepSeek. Verifica la API key en el servidor.' });
  }
});

export default router;
