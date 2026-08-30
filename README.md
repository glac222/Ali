# Mi Cocina — Gus

App de cocina y nutrición personal para Gus en Guayaquil. Backend en Node/Express + SQLite, frontend en React (Vite), y un chat integrado con la API de DeepSeek (con modo simulado automático si no hay API key configurada).

## Estructura

```
server/   API REST + SQLite (better-sqlite3) + proxy a DeepSeek
client/   Frontend React (Vite), diseño del mockup original
```

## Desarrollo local

### 1. Backend

```bash
cd server
npm install
cp .env.example .env      # opcional: agrega tu DEEPSEEK_API_KEY
npm run seed               # crea y llena la base de datos con los datos de Gus
npm run dev                # http://localhost:4000
```

Sin `DEEPSEEK_API_KEY`, el chat responde en **modo simulado** (igual que el mockup original). Con la key configurada, las respuestas vienen de DeepSeek real, usando un system prompt que se arma dinámicamente con la despensa, el plan semanal y la memoria de comidas guardada en la base de datos.

### 2. Frontend

```bash
cd client
npm install
npm run dev                 # http://localhost:5173 (proxea /api hacia :4000)
```

Abre `http://localhost:5173`.

## Variables de entorno

**server/.env**
```
PORT=4000
DEEPSEEK_API_KEY=          # tu key de DeepSeek — si se deja vacío, modo simulado
```

**client/.env** (solo necesario si frontend y backend NO estarán en el mismo dominio)
```
VITE_API_URL=https://api.tu-backend.com
```

## Build de producción

```bash
cd client && npm run build   # genera client/dist
```

El servidor Express (`server/src/index.js`) detecta automáticamente `client/dist` y sirve el frontend compilado además de la API — con esto **un solo proceso Node atiende todo el dominio** (frontend + `/api/*`).

---

## Desplegar en Hostinger

El plan **Premium Web Hosting** (hosting compartido, sin SSH ni Node.js) no puede
correr el backend Express/SQLite de `server/`. Para eso está **`php/`**: un port
1:1 del backend a **PHP puro + MariaDB**, el mismo stack que ya funciona para Gus.

- Frontend: React compilado (`client/`), servido como estático.
- Backend: `php/` (PHP 8, PDO, cURL — sin Composer, sin frameworks).
- Se despliega en el subdominio `ali.calimundo.com`.

👉 **Guía paso a paso: [`php/DEPLOY.md`](php/DEPLOY.md)**

Resumen:

1. Crear subdominio `ali` con document root `public_html/ali/public`.
2. Crear base MariaDB en hPanel.
3. `cd client && npm run build`.
4. Subir `php/public/*` + `client/dist/*` al docroot; `php/src/` y `php/db/` un
   nivel arriba.
5. Crear `php/.env` en el servidor (credenciales de la base + `DEEPSEEK_API_KEY`
   + `ADMIN_TOKEN`).
6. Visitar `…/api/admin/migrate?token=…` y `…/api/admin/seed?token=…` una vez.

El `server/` en Node sigue sirviendo para desarrollo local y para un eventual
despliegue en VPS o en "Node.js App" de planes Business/Cloud.

## Notas sobre los datos

- En el port PHP todo el estado vive en **MariaDB**. Backup desde hPanel →
  phpMyAdmin → Exportar, o con los backups automáticos de Hostinger.
- En el `server/` Node, el estado vive en `server/data/mi-cocina.db` (SQLite);
  `npm run seed` solo puebla si está vacía, `npm run seed -- --force` reinicia.
- El endpoint `/api/admin/seed` (PHP) es el equivalente: solo puebla si la base
  está vacía; `?force=1` reinicia a los datos de ejemplo de Gus.
