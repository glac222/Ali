# Ali — el arnés del asistente

Ali no es un chatbot que solo sugiere recetas: es un **agente** que entiende lo
que le dices, lo analiza y **hace los cambios en la base de datos** (despensa,
comidas, plan, lista, preferencias). El modelo propone; el servidor valida y
ejecuta.

Todo vive en [`src/chat.php`](src/chat.php). El endpoint es `POST /api/chat`.

## Las piezas

| Pieza | Dónde | Qué hace |
|---|---|---|
| **Prompt de sistema** | `assistant_system_prompt()` | Rol, tono, prioridades, precisión y política de acciones. Incluye las reglas de cocina de Gus. |
| **Contexto** | `assistant_context()` | Inyecta solo lo útil: fecha, comidas de hoy, plan de hoy/mañana, despensa (por vencer primero), lista activa, preferencias, notas, ocasiones para compartir y bitácora de lugares. |
| **Herramientas** | `assistant_tools()` | 22 funciones con schema estilo OpenAI que el modelo puede llamar. |
| **Ejecución** | `assistant_dispatch()` | Corre cada herramienta contra MariaDB, registra el evento y devuelve el resultado al modelo. |
| **Bucle** | `assistant_reply()` | Llama a DeepSeek → ejecuta tools → le pasa los resultados → repite (máx. 6 rondas) → respuesta final en texto. Junta los errores de herramienta para no cerrar con un "listo" falso. |
| **Cantidades** | `qty_parse` / `qty_convert` / `qty_format` | Restan con precisión. Carnes se miden en **porciones** (1 porción ≈ 150 g, editable). |

## Política de acciones (lo importante)

Ali **ejecuta sin volver a preguntar** cuando entiende un hecho que le dijiste:

- «comí / almorcé / cené / desayuné X» (en casa) → registra la comida y
  **descuenta los ingredientes** de la despensa.
- «usé / gasté / se acabó X» → ajusta el stock.
- «compré X» → lo agrega a la despensa (combina si ya existe). Solo pregunta si
  falta la cantidad o la presentación.
- «agrega X a la lista» → lo agrega a la lista de compras (`source = 'ia'`).
- «X cuesta $N en tal tienda» / «corrige el precio de X» → `precio_fijar` sobre
  el ítem de la lista de compras (recalcula el total estimado del período).
- un cambio en la **plantilla** semanal («los jueves quiero Y») → `plan_ajustar`.
- un cambio para una **fecha concreta** («mañana ceno Y», «la merienda del jueves
  4») → `comida_registrar` con esa fecha (`estado = "planificada"` si no pasó).
- «en realidad comí Y» → `comida_registrar` otra vez con el mismo día y tipo:
  **reemplaza** la comida anterior y deshace su descuento de stock antes de
  aplicar el nuevo. (Los `snack` no se reemplazan por defecto; se acumulan.)
- «comí fuera / en la calle / pedí a domicilio» → registra `lugar = "fuera"` y
  **no toca la despensa**.

Solo pide confirmación para lo **destructivo**: borrar un producto entero,
borrar una comida registrada, reemplazar todo el plan de la semana, o quitar
ítems de la lista. Esas herramientas devuelven `estado = "requiere_confirmacion"`
y Ali vuelve a llamarlas con `confirmado = true` tras el "sí".

Si un nombre o cantidad es ambiguo, pregunta cuál (eso es análisis, no permiso).

## Herramientas

Lectura: `despensa_consultar`, `recetas_consultar`, `preferencia_consultar`.

Escritura:
`despensa_agregar`, `despensa_ajustar`, `despensa_eliminar`(*),
`comida_registrar`, `comida_eliminar`(*),
`plan_ajustar`, `plan_reemplazar_semana`(*),
`lista_agregar`, `lista_quitar`(*), `lista_marcar_comprado`, `precio_fijar`,
`receta_guardar`, `preferencia_guardar`, `nota_guardar`,
`nutricion_registrar`, `descubrimiento_agregar`, `descubrimiento_actualizar`,
`ocasion_guardar`, `ocasion_idea_agregar`.

(*) con confirmación.

**Ocasiones y lugares.** `ocasion_idea_agregar` recibe una lista de ideas para una
"ocasión para compartir" (noche de pelis, desayuno con alguien, cuando viene
gente…); crea la ocasión si no existe. Cada idea marca `lugar` (`casa`/`fuera`) y
un `precio` en texto libre. `descubrimiento_agregar` / `descubrimiento_actualizar`
llevan la **bitácora de lugares**: `visited = 0` es "por probar", `visited = 1` es
"ya fui" y `dish_note` guarda la nota a futuro de qué pedir ahí. "Fui a X, pídete
Y" → `descubrimiento_actualizar` (marca visitado + nota).

## Cómo se descuenta el stock

`comida_registrar` con `estado = "consumida"` y `lugar = "casa"`:

1. Si pasas `ingredientes` con cantidad → resta cada uno.
2. Si no pasas cantidades → infiere la **proteína principal** del nombre
   (categoría `carnes` → `porciones_por_comida`, por defecto 3; categoría
   `latas` → 1 lata). El arroz y las verduras nunca se descuentan solos: Ali
   lo menciona si hace falta.
3. Si no puede convertir una unidad ("1 taza" de un producto en litros), no
   inventa: lo anota en el producto y lo dice.

Las carnes se guardan en **porciones**. `GET /api/admin/recalc?token=` convierte
el texto existente ("1.2 kg" → "8 porciones"), siembra `assistant_prefs` y
normaliza los desayunos entre semana que quedaron como "Opcional" (pasan a ser
el desayuno de siempre: batido + sándwiches), todo sin borrar datos. Es el único
paso post-`migrate` que hace falta.

## Precios de la lista de compras

Cada ítem de la lista muestra **precio por tienda**. La fuente:

1. El precio que alguien cargó a mano (UI) o con `precio_fijar` — **siempre gana**.
2. Si no hay ninguno, un **catálogo de referencia** por tienda
   (`SHOP_PRICE_CATALOG` en [`src/shopping.php`](src/shopping.php)) lo calcula al
   vuelo — no se guarda en la BD. Un producto fuera del catálogo cae al
   `SHOP_PRICE_FALLBACK` por categoría, así que **ningún ítem queda sin precio**.

Tiendas registradas (`SHOP_STORES`): Mi Comisariato, Super Maxi, Tía, Mercado,
Tuti, Tienda. Unidad `kg`/`l` en el catálogo = precio a granel (se multiplica por
"· 1.8 kg"); `un`/`lata`/`paq`/`funda` = precio por pieza (lo multiplica `qty`).
El "Estimado" del período suma el mejor precio × cantidad de todos los ítems.
Ajusta los números del catálogo con confianza.

**Formas de precio** (`shop_price_parse` en `src/shopping.php`, mismo criterio en
`client/src/screens/Lista.jsx`):

| Texto | Qué es | Cómo entra al total |
|---|---|---|
| `$1.20` | precio total por pieza | `× qty` |
| `$1.00/pack 8 un` | precio de lote (un dólar por un paquete de 8) | `× ceil(qty / 8)` paquetes; el stepper `+/−` cuenta paquetes |
| `$3.80/lb`, `$0.30/un` | tarifa a granel sin conteo | no se puede multiplicar → cuenta como "sin precio" |

Con un precio de lote, `precio_fijar` guarda el texto tal cual; la pantalla Lista
muestra el subtotal `= $N` de esa línea y opera el stepper en paquetes.

## Preferencias / perfil del hogar

Tabla `assistant_prefs` (clave → valor). Se lee entera en el contexto. Claves
base sembradas: `personas`, `porciones_por_comida`, `gramos_por_porcion`,
`objetivos`, `tiempo_cocina`, `nivel_picante`, `tecnicas_permitidas`,
`electrodomesticos`, `alergias`, `no_le_gusta`, `supermercados`, `reglas_cocina`.

Ali las actualiza con `preferencia_guardar`. La UI: `GET/PUT /api/prefs`.

## Endpoints nuevos

| Método | Ruta | Uso |
|---|---|---|
| `GET` | `/api/meals` | comidas registradas (`?date=YYYY-MM-DD` o últimas 60) |
| `GET` | `/api/meals/today` | comidas de hoy |
| `DELETE` | `/api/meals/{id}` | borrar una comida |
| `GET` | `/api/prefs` | todas las preferencias |
| `PUT` | `/api/prefs` | `{key, value}` |
| `GET` | `/api/chat/events` | bitácora de acciones de Ali |
| `GET` | `/api/admin/recalc?token=` | recalcular cantidades numéricas |
| `GET` | `/api/occasions` | ocasiones para compartir + sus ideas anidadas |
| `POST` | `/api/occasions` | crea una ocasión (`{title, emoji?, subtitle?}`) |
| `PUT` `DELETE` | `/api/occasions/{id}` | edita / borra una ocasión |
| `POST` | `/api/occasions/{id}/items` | agrega una idea (`{label, detail?, place, price?}`) |
| `DELETE` | `/api/occasions/{id}/items/{itemId}` | quita una idea |
| `PUT` | `/api/discoveries/{id}` | edita un lugar (`{visited, dish_note, meta, rating, ...}`) |

## Respuesta de `POST /api/chat`

```json
{
  "reply": "Registré tu almuerzo y descché 3 porciones de pollo; quedan 5.",
  "simulated": false,
  "actions": [{ "tool": "comida_registrar", "resumen": "Registré almuerzo: ..." }],
  "changed": ["meals", "pantry"]
}
```

El frontend usa `actions` para el aviso ("✓ …") y para bumpear `dataVersion`
(remonta la pantalla activa y refresca). `ChatPanel` rehidrata la conversación
desde `/api/chat/history` al abrirse y tiene una vista de bitácora
(`/api/chat/events`) en el botón del reloj.

**Comidas en la UI:** `Inicio` ("Comidas de hoy") y `Plan` (día seleccionado)
fusionan las filas de `meals` sobre la plantilla `meal_plan` con
`client/src/meals.js` → `mergeMeals()`. Una comida registrada pisa la fila de la
plantilla de su tipo; `cena`/`snack` se añaden. Por eso, cuando Ali registra o
corrige una comida, se ve en el acto.

## Sin API key

Si `DEEPSEEK_API_KEY` está vacío, Ali entra en **modo simulado**: reconoce
"comí X" con heurística local y descuenta, pero para el resto pide la key. En
producción la key está puesta, así que el modo completo está activo.

## Config

`.env`:
- `DEEPSEEK_API_KEY` — obligatorio para el modo completo.
- (no hay más flags nuevos; el modelo es `deepseek-chat`, 4 rondas de
  herramientas, timeout 55 s por llamada).
