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

## Pendiente / siguiente
- Subir los archivos a Hostinger (no tengo acceso — ver `php/DEPLOY.md`) y
  correr `…/api/admin/migrate?token=…` para crear las tablas nuevas
  (`providers`, `products`, `product_prices`, `occasions`, `meals`, etc.).
- Conectar `product_prices` como fuente real de los `price-tags` en la lista
  (hoy la lista sigue usando el JSON libre `shopping_list_items.prices`).
- Cargar el `DEEPSEEK_API_KEY` en producción para que el arnés use el modelo
  real en vez del modo simulado.
