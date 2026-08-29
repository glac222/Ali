import pg from 'pg';

const { Pool } = pg;

if (!process.env.DATABASE_URL) {
  console.warn('DATABASE_URL no está configurado — usando conexión local por defecto (postgres://postgres:postgres@localhost:5432/mi_cocina)');
}

export const pool = new Pool({
  connectionString: process.env.DATABASE_URL || 'postgres://postgres:postgres@localhost:5432/mi_cocina',
  ssl: process.env.DATABASE_URL?.includes('localhost') ? false : { rejectUnauthorized: false },
});

export async function query(text, params) {
  const res = await pool.query(text, params);
  return res.rows;
}

export async function one(text, params) {
  const rows = await query(text, params);
  return rows[0] || null;
}

export async function initSchema() {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS pantry_items (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      quantity TEXT NOT NULL DEFAULT '',
      category TEXT NOT NULL DEFAULT 'granos',
      expires_label TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'ok',
      notes TEXT NOT NULL DEFAULT '',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS recipes (
      id SERIAL PRIMARY KEY,
      slug TEXT UNIQUE NOT NULL,
      title TEXT NOT NULL,
      description TEXT NOT NULL DEFAULT '',
      tags TEXT NOT NULL DEFAULT '[]',
      time_label TEXT NOT NULL DEFAULT '',
      gradient TEXT NOT NULL DEFAULT '',
      source TEXT NOT NULL DEFAULT 'mio',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS recipe_ingredients (
      id SERIAL PRIMARY KEY,
      recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
      text TEXT NOT NULL,
      missing BOOLEAN NOT NULL DEFAULT FALSE,
      sort_order INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS recipe_steps (
      id SERIAL PRIMARY KEY,
      recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
      step_number INTEGER NOT NULL,
      text TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS meal_plan (
      id SERIAL PRIMARY KEY,
      weekday TEXT NOT NULL,
      meal_type TEXT NOT NULL,
      title TEXT NOT NULL,
      detail TEXT NOT NULL DEFAULT '',
      optional BOOLEAN NOT NULL DEFAULT FALSE,
      sort_order INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS shopping_list_items (
      id SERIAL PRIMARY KEY,
      period TEXT NOT NULL DEFAULT '1 semana',
      group_label TEXT NOT NULL DEFAULT '',
      name TEXT NOT NULL,
      qty INTEGER NOT NULL DEFAULT 1,
      checked BOOLEAN NOT NULL DEFAULT FALSE,
      prices TEXT NOT NULL DEFAULT '[]',
      sort_order INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS shopping_list_totals (
      period TEXT PRIMARY KEY,
      amount TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS discoveries (
      id SERIAL PRIMARY KEY,
      title TEXT NOT NULL,
      source TEXT NOT NULL DEFAULT '',
      link TEXT NOT NULL DEFAULT '',
      meta TEXT NOT NULL DEFAULT '',
      rating INTEGER NOT NULL DEFAULT 0,
      gradient TEXT NOT NULL DEFAULT ''
    );

    CREATE TABLE IF NOT EXISTS nutrition_log (
      log_date DATE PRIMARY KEY,
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
      id SERIAL PRIMARY KEY,
      entry TEXT NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS chat_messages (
      id SERIAL PRIMARY KEY,
      role TEXT NOT NULL,
      content TEXT NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
  `);
}
