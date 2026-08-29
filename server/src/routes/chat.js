import { Router } from 'express';
import { query } from '../db.js';
import { buildSystemPrompt } from '../services/systemPrompt.js';
import { getChatReply, isConfigured } from '../services/deepseek.js';

const router = Router();

router.get('/status', (req, res) => {
  res.json({ configured: isConfigured() });
});

router.get('/history', async (req, res) => {
  res.json(await query('SELECT * FROM chat_messages ORDER BY id ASC LIMIT 200'));
});

router.post('/', async (req, res) => {
  const { message, history = [] } = req.body;
  if (!message || !message.trim()) return res.status(400).json({ error: 'message es requerido' });

  await query('INSERT INTO chat_messages (role, content) VALUES ($1,$2)', ['user', message]);

  try {
    const systemPrompt = await buildSystemPrompt();
    const fullHistory = [...history, { role: 'user', content: message }];
    const { reply: rawReply, simulated } = await getChatReply(systemPrompt, fullHistory);

    const memMatches = [...rawReply.matchAll(/\[MEMORIA:([^\]]+)\]/g)];
    const savedMemories = [];
    for (const m of memMatches) {
      const entry = m[1].trim();
      await query('INSERT INTO meal_memory (entry) VALUES ($1)', [entry]);
      savedMemories.push(entry);
    }
    const reply = rawReply.replace(/\[MEMORIA:[^\]]+\]/g, '').trim();

    await query('INSERT INTO chat_messages (role, content) VALUES ($1,$2)', ['assistant', reply]);

    res.json({ reply, simulated, savedMemories });
  } catch (err) {
    console.error('Error en chat:', err);
    res.status(502).json({ error: 'Error de conexión con DeepSeek. Verifica la API key en el servidor.' });
  }
});

export default router;
