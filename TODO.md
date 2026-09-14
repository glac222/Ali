# Tareas pendientes

## Fusión con la rama del arnés de IA — ✅ hecha
Se integró `claude/nuevo-proyecto-n1cqzh` (agente con herramientas reales,
`assistant_reply`, registro de comidas, ocasiones, mejor "modo comprar" con
precios por lote). Esa rama reemplaza el mecanismo viejo de tags
`[MEMORIA:]`/`[LISTA:]` en el chat — ahora todo pasa por el bucle de
herramientas del arnés en `php/src/chat.php`.

De esta rama se preservó (adaptado al esquema nuevo):
- `providers` / `products` / `product_prices` + `/api/prices/*` — el arnés
  no tenía catálogo de precios por proveedor.
- Auto-agregar a la lista cuando la despensa llega a 0 — enganchado en
  `pantry_discount()` (el descuento real del agente) y en el PUT manual de
  pantry en `routes.php`.

El "modo comprar" que trae el arnés (`client/src/screens/Lista.jsx`) es más
completo que el original de esta rama: entiende precios por paquete/lote,
ordena por urgencia/precio/tienda y actualiza el total de forma optimista.

**Nota:** todo esto solo existe en `php/` (producción/Hostinger). El backend
Node en `server/` quedó en su versión anterior (más simple, sin el arnés) —
es el que se usa para desarrollo local rápido, según el README.

## Conectar precios reales y quitar lo simulado — ✅ hecho
- `shop_ref_prices_for_row()` (PHP) y `realPricesForItem()` (Node) ya no
  inventan precios: si un ítem de la lista no tiene precio propio, buscan por
  nombre en el catálogo REAL `products`/`providers`/`product_prices` (el que
  se carga desde recibos vía `/api/prices/...`). Sin match real → el ítem
  queda "sin precio", nunca se fabrica un número.
- Se eliminó el catálogo hardcodeado `SHOP_PRICE_CATALOG`/`SHOP_PRICE_FALLBACK`
  (precios de Guayaquil inventados) de `php/src/shopping.php`.
- Se borraron de la base de desarrollo todos los datos de ejemplo/prueba:
  despensa, lista de compras, descubrimientos, nutrición e historial de chat
  (eran demo de "Gus" o pruebas mías). El catálogo de precios real (6
  productos de Megamaxi) se mantuvo intacto; se borraron 2 productos que eran
  pruebas mías ("Papel higiénico", "Detergente").
- No se tocaron `recipes`/`meal_plan`: codifican reglas de cocina reales y
  específicas de la casa (ej. "hígado sin cebolla"), no datos inventados.

## Pendiente / siguiente
- Subir los archivos a Hostinger (no tengo acceso — ver `php/DEPLOY.md`) y
  correr `…/api/admin/migrate?token=…` para crear las tablas nuevas
  (`providers`, `products`, `product_prices`, `occasions`, `meals`, etc.).
- Cargar el `DEEPSEEK_API_KEY` en producción para que el arnés use el modelo
  real en vez del modo simulado.
