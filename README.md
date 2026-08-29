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

La estrategia depende de tu tipo de plan. Verifica en **hPanel → tu sitio → Avanzado** si existe la opción **"Configurar app Node.js"**, o si tienes un plan **VPS** (con acceso SSH/root).

### Opción A — Hostinger VPS (recomendada, más control)

1. Conéctate por SSH y clona el repo en el servidor.
2. Instala Node.js (v20+) si no está: `curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs`.
3. Instala dependencias y compila el frontend:
   ```bash
   cd server && npm install --omit=dev && npm run seed
   cd ../client && npm install && npm run build
   ```
4. Configura `server/.env` con tu `DEEPSEEK_API_KEY` y el `PORT` que quieras usar internamente (ej. 4000).
5. Corre el servidor con un gestor de procesos para que sobreviva reinicios:
   ```bash
   npm install -g pm2
   cd ../server && pm2 start src/index.js --name mi-cocina
   pm2 save && pm2 startup
   ```
6. Configura Nginx como proxy inverso hacia el puerto interno (Hostinger VPS suele traer Nginx o puedes instalarlo):
   ```nginx
   server {
     listen 80;
     server_name tu-dominio.com;
     location / {
       proxy_pass http://localhost:4000;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection 'upgrade';
       proxy_set_header Host $host;
       proxy_cache_bypass $http_upgrade;
     }
   }
   ```
7. Activa SSL gratis con Certbot: `sudo certbot --nginx -d tu-dominio.com`.

Con esto, `https://tu-dominio.com` sirve la app completa (frontend + API + chat) desde un solo servidor.

### Opción B — Hosting Web con "Node.js App" (hPanel)

1. En hPanel: **Sitios web → tu dominio → Avanzado → Configurar app Node.js**.
2. Sube el proyecto completo (o conéctalo por Git si esa opción está disponible) y define el **directorio de la app** como `server/`.
3. Define el **archivo de arranque** como `src/index.js`.
4. En "Variables de entorno" de esa herramienta, agrega `DEEPSEEK_API_KEY` (y `PORT` si hPanel lo requiere — normalmente Passenger asigna el puerto automáticamente vía `process.env.PORT`, que el código ya respeta).
5. Antes de iniciar la app, corre desde la terminal de hPanel (o vía SSH si el plan lo permite):
   ```bash
   cd server && npm install && npm run seed
   cd ../client && npm install && npm run build
   ```
6. Reinicia la app Node desde hPanel. El propio Express servirá `client/dist` automáticamente.

**Nota:** `better-sqlite3` es un módulo nativo — `npm install` debe ejecutarse en el propio servidor de Hostinger (no subir `node_modules` desde tu máquina), para que compile correctamente para esa arquitectura.

### Opción C — Hosting compartido sin soporte Node.js

Si tu plan es básico (solo PHP/estático), Hostinger no puede correr el backend directamente. En ese caso:

1. Sube solo `client/dist` (después de `npm run build`) a `public_html/` vía el Administrador de Archivos o FTP — eso sirve el frontend como sitio estático.
2. Despliega `server/` en un servicio gratuito/económico que sí soporte Node.js persistente (ej. Railway, Render, Fly.io).
3. Define `VITE_API_URL` con la URL de ese backend antes de compilar el frontend (`client/.env`), y vuelve a correr `npm run build`.
4. Asegúrate de que el backend permita CORS desde tu dominio de Hostinger (ya está habilitado globalmente vía `cors()` en `server/src/index.js`).

## Notas sobre los datos

- Todo el estado (despensa, recetas, plan, lista de compras, calificaciones, memoria del chat) vive en `server/data/mi-cocina.db` (SQLite). Haz backup de ese archivo periódicamente si lo despliegas en producción.
- `npm run seed` solo puebla la base si está vacía; usa `npm run seed -- --force` para reiniciar todos los datos a los valores de ejemplo de Gus.
