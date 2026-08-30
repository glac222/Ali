# Desplegar Mi Cocina en Hostinger — `ali.calimundo.com`

Backend PHP puro + MariaDB (mismo stack que Gus). Sin SSH, sin Composer, sin Node
en el servidor. El frontend React se compila **en tu máquina** y se sube como
archivos estáticos.

```
php/
  public/          <- document root del subdominio (front controller + React compilado)
    index.php
    .htaccess      <- reglas de reescritura + seguridad
  src/             <- código PHP (fuera del docroot)
    .htaccess      <- Require all denied (respaldo)
  db/              <- schema.sql + datos de ejemplo (fuera del docroot)
    .htaccess      <- Require all denied (respaldo)
  .env             <- credenciales (NO se sube a git; se crea en el servidor)
  .env.example
```

---

## 1. Crear el subdominio

hPanel → **Dominios → Subdominios**:

- Subdominio: `ali`
- Dominio: `calimundo.com`
- **Carpeta personalizada (document root):** `public_html/ali/public`

> Importante que el docroot termine en `/public`. Así `src/`, `db/` y `.env`
> quedan **fuera** de lo accesible por web. Si tu hPanel no deja poner un docroot
> con subcarpeta, mira la nota al final.

Espera a que el DNS propague (unos minutos) y activa el **SSL gratis**
(hPanel → SSL) para `ali.calimundo.com`.

## 2. Crear la base de datos MariaDB

hPanel → **Bases de datos → Bases de datos MySQL**:

1. Crea una base nueva, ej. `micocina`. Hostinger la prefija: `uXXXXXXXX_micocina`.
2. Crea un usuario, ej. `micocina`, con una contraseña fuerte. Anótala.
3. Asigna el usuario a la base con **todos los privilegios**.

## 3. Compilar el frontend (en tu máquina)

```bash
cd client
npm install
npm run build          # genera client/dist/
```

No hace falta tocar `VITE_API_URL`: el frontend y la API viven en el mismo
dominio, así que las llamadas a `/api/...` funcionan solas.

## 4. Subir los archivos

Sube por **Administrador de archivos** de hPanel o por FTP:

| Local | Servidor |
|---|---|
| `php/public/index.php` y `php/public/.htaccess` | `public_html/ali/public/` |
| todo el contenido de `client/dist/` (`index.html` + `assets/`) | `public_html/ali/public/` |
| carpeta `php/src/` | `public_html/ali/src/` |
| carpeta `php/db/` | `public_html/ali/db/` |

> El Administrador de archivos de hPanel no muestra los archivos que empiezan con
> punto por defecto: activa **"Mostrar archivos ocultos"** (Configuración) para
> ver y subir `.htaccess`.

Es decir, en el servidor debe quedar:

```
public_html/ali/
  src/...            (incluye src/.htaccess -> Require all denied)
  db/...             (incluye db/.htaccess  -> Require all denied)
  .env
  public/
    index.php
    .htaccess
    index.html
    assets/...
```

## 5. Crear `php/.env` en el servidor

En `public_html/ali/`, copia `.env.example` a `.env` y edítalo:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=uXXXXXXXX_micocina
DB_USER=uXXXXXXXX_micocina
DB_PASS=la-contraseña-que-anotaste

DEEPSEEK_API_KEY=sk-...tu-key-de-deepseek...

ADMIN_TOKEN=pon-aqui-algo-largo-y-aleatorio-40-chars

CORS_ORIGIN=https://ali.calimundo.com
```

Genera el `ADMIN_TOKEN` con cualquier cosa impredecible (ej.
`openssl rand -hex 24` o un gestor de contraseñas).

## 6. Crear las tablas y cargar los datos

Desde el navegador (una sola vez):

```
https://ali.calimundo.com/api/admin/migrate?token=EL_ADMIN_TOKEN
https://ali.calimundo.com/api/admin/seed?token=EL_ADMIN_TOKEN
```

- `migrate` crea las tablas. Es idempotente: correrlo de nuevo no rompe nada.
- `seed` carga la despensa, recetas, plan, lista y descubrimientos de Gus.
  Solo llena si la base está vacía; para reiniciar todo a los valores de ejemplo:
  `...&force=1`.

Respuesta esperada: `{"ok":true,...}`.

## 7. Probar

- `https://ali.calimundo.com/api/health` → `{"ok":true}`
- `https://ali.calimundo.com/` → la app carga, muestra despensa, plan, etc.
- Abre el chat. Con `DEEPSEEK_API_KEY` puesta dirá "DeepSeek conectado";
  sin ella, "modo simulado".

---

## Actualizar la app más adelante

1. `cd client && npm run build`
2. Sube el nuevo `client/dist/` a `public_html/ali/public/` (reemplaza
   `index.html` y `assets/`).
3. Si cambió el código PHP, sube `php/src/`.
4. Si cambió `db/schema.sql`, vuelve a llamar `/api/admin/migrate?token=...`.

## Actualizar al arnés de Ali (el asistente con herramientas)

Cuando subas la versión con el asistente Ali, además de los pasos de arriba:

1. Sube `php/src/` y `php/db/` completos.
2. `https://ali.calimundo.com/api/admin/migrate?token=EL_TOKEN`
   — crea las tablas nuevas (`meals`, `meal_items`, `assistant_prefs`,
   `assistant_events`) y las columnas nuevas de `pantry_items` y
   `shopping_list_items`. Es idempotente.
3. `https://ali.calimundo.com/api/admin/recalc?token=EL_TOKEN`
   — **una vez** después de migrar. Sin borrar nada: rellena
   `qty_value` / `qty_unit` / `min_qty` de la despensa (las carnes pasan a
   "porciones") y siembra el perfil del hogar (`assistant_prefs`).
4. Solo si quieres **reiniciar todo** a los datos de ejemplo:
   `https://ali.calimundo.com/api/admin/seed?token=EL_TOKEN&force=1`
   (esto SÍ borra despensa, plan y lista actuales).

Detalle de cómo funciona el arnés: `php/ASISTENTE.md`.

## Backup

Todo el estado vive en MariaDB. hPanel → **Bases de datos → phpMyAdmin** →
Exportar, o usa los backups automáticos de Hostinger.

## Seguridad

- `.env` nunca se sube a git (está en `.gitignore`).
- `src/` y `db/` llevan su propio `.htaccess` con `Require all denied` como
  respaldo, por si el docroot quedara mal configurado.
- Tras el primer `seed`, si quieres, puedes cambiar el `ADMIN_TOKEN` en `.env`
  para invalidar el anterior.

## Si hPanel NO deja poner el docroot en una subcarpeta

Algunos planes fijan el docroot del subdominio en `public_html/ali/`. En ese caso
sube **todo plano** en `public_html/ali/`:

```
public_html/ali/
  .htaccess          <- el de php/public/.htaccess
  index.php          <- el de php/public/index.php
  index.html         <- de client/dist/
  assets/            <- de client/dist/
  src/               <- de php/src/  (con su src/.htaccess)
  db/                <- de php/db/   (con su db/.htaccess)
  .env
```

`index.php` detecta solo este layout (busca `src/` primero en la carpeta padre y
luego junto a sí mismo), así que **no hay que editar código**. El
`php/public/.htaccess` ya incluye las reglas de seguridad (`.env`, `.sql`, `src/`,
`db/`) además de la reescritura de la SPA.

Verificaciones de seguridad obligatorias en este layout:

- `https://ali.calimundo.com/.env` → **403**
- `https://ali.calimundo.com/src/bootstrap.php` → **403**
- `https://ali.calimundo.com/db/schema.sql` → **403**

Si alguna devuelve 200, no dejes la app pública hasta arreglar el `.htaccess`.
