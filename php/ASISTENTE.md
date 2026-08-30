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
| **Contexto** | `assistant_context()` | Inyecta solo lo útil: fecha, comidas de hoy, plan de hoy/mañana, despensa (por vencer primero), lista activa, preferencias y notas. |
| **Herramientas** | `assistant_tools()` | 18 funciones con schema estilo OpenAI que el modelo puede llamar. |
| **Ejecución** | `assistant_dispatch()` | Corre cada herramienta contra MariaDB, registra el evento y devuelve el resultado al modelo. |
| **Bucle** | `assistant_reply()` | Llama a DeepSeek → ejecuta tools → le pasa los resultados → repite (máx. 4 rondas) → respuesta final en texto. |
| **Cantidades** | `qty_parse` / `qty_convert` / `qty_format` | Restan con precisión. Carnes se miden en **porciones** (1 porción ≈ 150 g, editable). |

## Política de acciones (lo importante)

Ali **ejecuta sin volver a preguntar** cuando entiende un hecho que le dijiste:

- «comí / almorcé / cené / desayuné X» (en casa) → registra la comida y
  **descuenta los ingredientes** de la despensa.
- «usé / gasté / se acabó X» → ajusta el stock.
- «compré X» → lo agrega a la despensa (combina si ya existe). Solo pregunta si
  falta la cantidad o la presentación.
- «agrega X a la lista» → lo agrega a la lista de compras (`source = 'ia'`).
- un cambio en un día del plan → lo aplica.
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
`lista_agregar`, `lista_quitar`(*), `lista_marcar_comprado`,
`receta_guardar`, `preferencia_guardar`, `nota_guardar`,
`nutricion_registrar`, `descubrimiento_agregar`.

(*) con confirmación.

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
el texto existente ("1.2 kg" → "8 porciones") y siembra `assistant_prefs`, todo
sin borrar datos. Es el único paso post-`migrate` que hace falta.

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

## Respuesta de `POST /api/chat`

```json
{
  "reply": "Registré tu almuerzo y descché 3 porciones de pollo; quedan 5.",
  "simulated": false,
  "actions": [{ "tool": "comida_registrar", "resumen": "Registré almuerzo: ..." }],
  "changed": ["meals", "pantry"]
}
```

El frontend usa `actions` para el aviso ("✓ …") y `changed` para refrescar la
pantalla activa.

## Sin API key

Si `DEEPSEEK_API_KEY` está vacío, Ali entra en **modo simulado**: reconoce
"comí X" con heurística local y descuenta, pero para el resto pide la key. En
producción la key está puesta, así que el modo completo está activo.

## Config

`.env`:
- `DEEPSEEK_API_KEY` — obligatorio para el modo completo.
- (no hay más flags nuevos; el modelo es `deepseek-chat`, 4 rondas de
  herramientas, timeout 55 s por llamada).
