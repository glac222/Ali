# Mi Cocina — Gus

App de cocina y nutrición personal para Gus en Guayaquil. Backend en Node/Express + PostgreSQL, frontend en React (Vite), y un chat integrado con la API de DeepSeek (con modo simulado automático si no hay API key configurada).

## Estructura

```
server/   API REST + PostgreSQL (pg) + proxy a DeepSeek
client/   Frontend React (Vite), diseño del mockup original
```

## Desarrollo local

### 1. Backend

Necesitas un Postgres accesible (local o remoto).

```bash
cd server
npm install
cp .env.example .env      # agrega DATABASE_URL y, opcional, DEEPSEEK_API_KEY
npm run seed               # crea las tablas y llena la base con los datos de Gus
npm run dev                # http://localhost:4000
```

Sin `DEEPSEEK_API_KEY`, el chat responde en **modo simulado**. Con la key configurada, las respuestas vienen de DeepSeek real, usando un system prompt que se arma dinámicamente con la despensa, el plan semanal y la memoria de comidas guardada en la base.

### 2. Frontend

```bash
cd client
npm install
npm run dev                 # http://localhost:5173 (proxea /api hacia :4000)
```

## Variables de entorno

**server/.env**
```
PORT=4000
DATABASE_URL=postgres://usuario:password@host:5432/basededatos
DEEPSEEK_API_KEY=                          # vacío = modo simulado
FRONTEND_ORIGIN=https://ali.calimundo.com  # restringe CORS en producción
```

**client/.env** (solo en build de producción, cuando el backend vive en otro dominio)
```
VITE_API_URL=https://tu-backend.onrender.com
```

---

## Despliegue elegido: Neon + Render + Hostinger (subdominio `ali.calimundo.com`)

Tu plan de Hostinger es **Premium Web Hosting** (compartido) — no corre un backend Node persistente. La app queda dividida así:

- **Base de datos**: Postgres gratis y persistente en [Neon](https://neon.tech).
- **Backend** (API + chat): Node/Express en [Render](https://render.com), free tier.
- **Frontend**: build estático (`client/dist`) subido al subdominio `ali.calimundo.com` en Hostinger vía el Administrador de Archivos.

### Paso 1 — Base de datos en Neon

1. Crea una cuenta gratis en [neon.tech](https://neon.tech) (no pide tarjeta).
2. Crea un proyecto nuevo (cualquier nombre, ej. `mi-cocina`).
3. En el dashboard del proyecto, copia el **Connection string** (empieza con `postgres://...`, incluye `?sslmode=require`).

### Paso 2 — Backend en Render

1. Crea una cuenta gratis en [render.com](https://render.com) y conecta tu repo de GitHub (`glac222/Ali`).
2. **New → Blueprint**, selecciona este repo — Render detecta el `render.yaml` de la raíz automáticamente y prepara el servicio `mi-cocina-api` con `rootDir: server`.
   - Si prefieres crearlo a mano: **New → Web Service**, root directory `server`, build command `npm install`, start command `npm start`.
3. En **Environment**, agrega:
   - `DATABASE_URL` → el connection string de Neon del paso 1.
   - `DEEPSEEK_API_KEY` → tu key de DeepSeek (déjala vacía si quieres modo simulado por ahora).
   - `FRONTEND_ORIGIN` ya viene definida como `https://ali.calimundo.com` en el `render.yaml`.
4. Despliega. Cuando termine, corre el seed **una sola vez** desde la shell de Render (Dashboard → tu servicio → Shell):
   ```bash
   npm run seed
   ```
5. Copia la URL pública que te da Render (algo como `https://mi-cocina-api.onrender.com`) y verifica que responde:
   ```bash
   curl https://mi-cocina-api.onrender.com/api/health
   ```

**Nota:** el free tier de Render "duerme" el servicio tras ~15 min sin tráfico y tarda unos segundos en despertar en la siguiente petición — normal, no es un error. Los datos en Neon nunca se pierden aunque el servicio de Render se reinicie o redespliegue.

### Paso 3 — Compilar el frontend apuntando al backend real

En tu máquina (o en esta misma sesión, avísame la URL de Render y lo hago yo):

```bash
cd client
echo "VITE_API_URL=https://mi-cocina-api.onrender.com" > .env
npm install
npm run build       # genera client/dist
```

### Paso 4 — Subdominio en Hostinger

1. En hPanel: **Dominios → calimundo.com → Subdominios** → crea `ali` (queda `ali.calimundo.com`), apuntando a una carpeta nueva, ej. `public_html/ali`.
2. Espera unos minutos a que el subdominio propague (Hostinger suele activarlo casi al instante).

### Paso 5 — Subir el build

1. Comprime el contenido de `client/dist` (no la carpeta en sí, sus archivos) en un `.zip`.
2. hPanel → **Archivos → Administrador de archivos** → entra a `public_html/ali`.
3. Sube el `.zip` y usa "Extraer" ahí mismo.
4. Verifica que `index.html` y la carpeta `assets/` queden directamente dentro de `public_html/ali` (no dentro de una subcarpeta extra).
5. Abre `https://ali.calimundo.com` — deberías ver la app completa hablando con el backend de Render.

Cada vez que cambies el código del frontend: repite el Paso 3 (rebuild) y el Paso 5 (resubir el zip). Para el backend, un `git push` a Render (o el redeploy manual desde su dashboard) basta — los datos en Neon no se tocan.

---

## Alternativas de despliegue (si cambias de plan de Hostinger)

<details>
<summary>Hostinger VPS — todo en un solo servidor</summary>

1. Conéctate por SSH y clona el repo.
2. Instala Node.js 20+, luego:
   ```bash
   cd server && npm install --omit=dev
   cd ../client && npm install && npm run build
   ```
3. Configura `server/.env` con `DATABASE_URL` (puede ser el mismo Neon, o Postgres local en el VPS) y `DEEPSEEK_API_KEY`.
4. `npm run seed` una vez, luego corre con PM2: `pm2 start src/index.js --name mi-cocina && pm2 save && pm2 startup`.
5. Nginx como proxy inverso hacia el puerto interno, más `certbot --nginx` para SSL gratis.

Con esto un solo proceso Node sirve frontend + API (Express ya detecta `client/dist` automáticamente).
</details>

<details>
<summary>Hosting Web con "Node.js App" en hPanel (planes Business/Cloud)</summary>

1. hPanel → tu dominio → Avanzado → **Configurar app Node.js**.
2. Directorio de la app: `server/`. Archivo de arranque: `src/index.js`.
3. Variables de entorno: `DATABASE_URL`, `DEEPSEEK_API_KEY`.
4. Corre `npm install && npm run seed` en `server/`, y `npm install && npm run build` en `client/` desde la terminal que dé hPanel.
5. Reinicia la app — Express sirve `client/dist` automáticamente, sin necesitar Render.
</details>

## Notas sobre los datos

- Todo el estado (despensa, recetas, plan, lista de compras, calificaciones, memoria del chat) vive en Postgres — persiste sin importar cuántas veces se reinicie o redespliegue el backend.
- `npm run seed` solo puebla la base si está vacía; usa `npm run seed -- --force` para reiniciar todos los datos a los valores de ejemplo de Gus.
