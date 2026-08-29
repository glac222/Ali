import Database from 'better-sqlite3';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const dataDir = path.join(__dirname, '..', 'data');
fs.mkdirSync(dataDir, { recursive: true });

const db = new Database(path.join(dataDir, 'mi-cocina.db'));
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

db.exec(`
CREATE TABLE IF NOT EXISTS pantry_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  quantity TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'granos',
  expires_label TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'ok',
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS recipes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT UNIQUE NOT NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  tags TEXT NOT NULL DEFAULT '[]',
  time_label TEXT NOT NULL DEFAULT '',
  gradient TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'mio',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS recipe_ingredients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  text TEXT NOT NULL,
  missing INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS recipe_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  step_number INTEGER NOT NULL,
  text TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS meal_plan (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  weekday TEXT NOT NULL,
  meal_type TEXT NOT NULL,
  title TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  optional INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS shopping_list_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  period TEXT NOT NULL DEFAULT '1 semana',
  group_label TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  qty INTEGER NOT NULL DEFAULT 1,
  checked INTEGER NOT NULL DEFAULT 0,
  prices TEXT NOT NULL DEFAULT '[]',
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS shopping_list_totals (
  period TEXT PRIMARY KEY,
  amount TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS discoveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT '',
  link TEXT NOT NULL DEFAULT '',
  meta TEXT NOT NULL DEFAULT '',
  rating INTEGER NOT NULL DEFAULT 0,
  gradient TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS nutrition_log (
  log_date TEXT PRIMARY KEY,
  calories INTEGER NOT NULL DEFAULT 0,
  calories_target INTEGER NOT NULL DEFAULT 2800,
  protein INTEGER NOT NULL DEFAULT 0,
  protein_target INTEGER NOT NULL DEFAULT 140,
  carbs INTEGER NOT NULL DEFAULT 0,
  carbs_target INTEGER NOT NULL DEFAULT 300,
  fat INTEGER NOT NULL DEFAULT 0,
  fat_target INTEGER NOT NULL DEFAULT 80
);

CREATE TABLE IF NOT EXISTS meal_memory (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS chat_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  role TEXT NOT NULL,
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`);

export default db;
