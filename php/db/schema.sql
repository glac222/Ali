-- Esquema de Mi Cocina para MariaDB 10.5+ (Hostinger compartido).
-- Todo es CREATE TABLE IF NOT EXISTS: correrlo dos veces no rompe nada.

CREATE TABLE IF NOT EXISTS pantry_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  quantity      VARCHAR(120) NOT NULL DEFAULT '',
  category      VARCHAR(60)  NOT NULL DEFAULT 'granos',
  expires_label VARCHAR(160) NOT NULL DEFAULT '',
  status        VARCHAR(20)  NOT NULL DEFAULT 'ok',
  notes         VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(120) NOT NULL UNIQUE,
  title       VARCHAR(255) NOT NULL,
  description TEXT         NOT NULL,
  tags        TEXT         NOT NULL,
  time_label  VARCHAR(60)  NOT NULL DEFAULT '',
  gradient    VARCHAR(255) NOT NULL DEFAULT '',
  source      VARCHAR(30)  NOT NULL DEFAULT 'mio',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  recipe_id  INT  NOT NULL,
  `text`     TEXT NOT NULL,
  missing    TINYINT NOT NULL DEFAULT 0,
  sort_order INT  NOT NULL DEFAULT 0,
  CONSTRAINT fk_ri_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_steps (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  recipe_id   INT NOT NULL,
  step_number INT NOT NULL,
  `text`      TEXT NOT NULL,
  CONSTRAINT fk_rs_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_plan (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  weekday    VARCHAR(20) NOT NULL,
  meal_type  VARCHAR(40) NOT NULL,
  title      TEXT NOT NULL,
  detail     TEXT NOT NULL,
  optional   TINYINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shopping_list_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  period      VARCHAR(40)  NOT NULL DEFAULT '1 semana',
  group_label VARCHAR(120) NOT NULL DEFAULT '',
  name        VARCHAR(255) NOT NULL,
  qty         INT NOT NULL DEFAULT 1,
  checked     TINYINT NOT NULL DEFAULT 0,
  prices      TEXT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shopping_list_totals (
  period VARCHAR(40) NOT NULL PRIMARY KEY,
  amount VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discoveries (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  title    VARCHAR(255) NOT NULL,
  source   VARCHAR(80)  NOT NULL DEFAULT '',
  link     VARCHAR(500) NOT NULL DEFAULT '',
  meta     VARCHAR(255) NOT NULL DEFAULT '',
  rating   INT NOT NULL DEFAULT 0,
  gradient VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ocasiones para compartir: tarjetas de ideas por situación (noche de pelis,
-- desayuno con alguien, visita en casa...). Generaliza la tarjeta fija que vivía
-- en el frontend. Cada ocasión tiene varias ideas (en casa o para salir).
CREATE TABLE IF NOT EXISTS occasions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(120) NOT NULL UNIQUE,
  emoji      VARCHAR(16)  NOT NULL DEFAULT '',
  title      VARCHAR(160) NOT NULL,
  subtitle   VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS occasion_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  occasion_id INT NOT NULL,
  label       VARCHAR(200) NOT NULL,
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  place       VARCHAR(12)  NOT NULL DEFAULT 'casa',   -- casa | fuera
  price       VARCHAR(120) NOT NULL DEFAULT '',
  sort_order  INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_oi_occasion FOREIGN KEY (occasion_id) REFERENCES occasions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nutrition_log (
  log_date        DATE NOT NULL PRIMARY KEY,
  calories        INT NOT NULL DEFAULT 0,
  calories_target INT NOT NULL DEFAULT 2800,
  protein         INT NOT NULL DEFAULT 0,
  protein_target  INT NOT NULL DEFAULT 140,
  carbs           INT NOT NULL DEFAULT 0,
  carbs_target    INT NOT NULL DEFAULT 300,
  fat             INT NOT NULL DEFAULT 0,
  fat_target      INT NOT NULL DEFAULT 80
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_memory (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  entry      TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  role       VARCHAR(20) NOT NULL,
  content    TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- ARNÉS DE ALI (el asistente): cantidades numéricas, comidas registradas,
-- perfil/preferencias del hogar y bitácora de acciones de la IA.
-- ===========================================================================

-- Cantidad numérica opcional en la despensa. El texto `quantity` sigue siendo
-- lo que se muestra; estas columnas permiten restar con precisión.
-- MariaDB 10.5 soporta ADD COLUMN IF NOT EXISTS (correrlo dos veces no rompe).
ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS qty_value DECIMAL(10,2) NULL;
ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS qty_unit  VARCHAR(24) NOT NULL DEFAULT '';
ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS min_qty   DECIMAL(10,2) NULL;

-- Origen de cada ítem de la lista de compras:
--   manual = lo puso una persona | scan = vino del escáner | auto = lo generó
--   el botón "Generar lista" desde el plan/despensa | ia = lo agregó Ali.
-- "Generar lista" solo borra y recrea los 'auto'; nunca toca lo manual.
ALTER TABLE shopping_list_items ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'manual';

-- Bitácora de lugares: distingue lo que hay "por probar" (visited = 0) de los
-- sitios donde Gus YA fue (visited = 1), y guarda una nota a futuro de qué pedir.
ALTER TABLE discoveries ADD COLUMN IF NOT EXISTS visited   TINYINT NOT NULL DEFAULT 0;
ALTER TABLE discoveries ADD COLUMN IF NOT EXISTS dish_note VARCHAR(500) NOT NULL DEFAULT '';

-- Comidas registradas (lo que Gus efectivamente comió o tiene planificado).
-- Distinto de meal_plan, que es la plantilla semanal.
CREATE TABLE IF NOT EXISTS meals (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  log_date   DATE        NOT NULL,
  meal_type  VARCHAR(40) NOT NULL DEFAULT 'almuerzo',
  name       TEXT        NOT NULL,
  servings   INT         NOT NULL DEFAULT 1,
  place      VARCHAR(20) NOT NULL DEFAULT 'casa',      -- casa | fuera | comprado
  status     VARCHAR(20) NOT NULL DEFAULT 'consumida', -- planificada | consumida
  notes      VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredientes usados en una comida registrada (para trazar el descuento).
CREATE TABLE IF NOT EXISTS meal_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  meal_id        INT NOT NULL,
  pantry_item_id INT NULL,
  name           VARCHAR(255) NOT NULL,
  qty_text       VARCHAR(120) NOT NULL DEFAULT '',
  qty_value      DECIMAL(10,2) NULL,
  qty_unit       VARCHAR(24)  NOT NULL DEFAULT '',
  deducted       TINYINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_mi_meal FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfil del hogar y preferencias que hacen mejores las sugerencias.
CREATE TABLE IF NOT EXISTS assistant_prefs (
  pref_key   VARCHAR(80) NOT NULL PRIMARY KEY,
  pref_value TEXT        NOT NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de todo lo que Ali cambió por su cuenta (auditoría + seguimiento).
CREATE TABLE IF NOT EXISTS assistant_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tool       VARCHAR(60)  NOT NULL,
  summary    VARCHAR(255) NOT NULL DEFAULT '',
  payload    TEXT         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de productos/proveedores/precios para el "Modo comprar" y para
-- cargar precios rápido al ver una marca en el súper.
CREATE TABLE IF NOT EXISTS providers (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  name     VARCHAR(255) NOT NULL UNIQUE,
  category VARCHAR(60) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_prices (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  provider_id INT NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  unit        VARCHAR(60) NOT NULL DEFAULT '',
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_product_provider (product_id, provider_id),
  CONSTRAINT fk_pp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
