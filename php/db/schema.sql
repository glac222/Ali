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
