import 'dotenv/config';
import express from 'express';
import cors from 'cors';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import './db.js';

import pantryRouter from './routes/pantry.js';
import recipesRouter from './routes/recipes.js';
import planRouter from './routes/plan.js';
import shoppingListRouter from './routes/shoppingList.js';
import discoveriesRouter from './routes/discoveries.js';
import nutritionRouter from './routes/nutrition.js';
import memoryRouter from './routes/memory.js';
import chatRouter from './routes/chat.js';
import pricesRouter from './routes/prices.js';

const app = express();
app.use(cors());
app.use(express.json());

app.get('/api/health', (req, res) => res.json({ ok: true }));
app.use('/api/pantry', pantryRouter);
app.use('/api/recipes', recipesRouter);
app.use('/api/plan', planRouter);
app.use('/api/shopping-list', shoppingListRouter);
app.use('/api/discoveries', discoveriesRouter);
app.use('/api/nutrition', nutritionRouter);
app.use('/api/memory', memoryRouter);
app.use('/api/chat', chatRouter);
app.use('/api/prices', pricesRouter);

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const clientDist = path.join(__dirname, '..', '..', 'client', 'dist');
if (fs.existsSync(clientDist)) {
  app.use(express.static(clientDist));
  app.get(/^(?!\/api).*/, (req, res) => res.sendFile(path.join(clientDist, 'index.html')));
}

const PORT = process.env.PORT || 4000;
app.listen(PORT, () => {
  console.log(`Mi Cocina API escuchando en http://localhost:${PORT}`);
});
