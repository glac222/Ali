# Tareas pendientes

## 1. Conectar el chat con la lista de compras — ✅ hecho
- El chat ahora recibe la lista de compras pendiente (período "1 semana") en su contexto.
- Puede agregar productos con la etiqueta `[LISTA:producto]` (igual que `[MEMORIA:...]`);
  el server la parsea e inserta en `shopping_list_items` (evita duplicados).
- El front muestra un toast "🛒 Añadido a la lista: ..." cuando ocurre.

## 2. Tabla de productos, proveedores y precios — ✅ modelo creado
- Tablas nuevas: `products`, `providers`, `product_prices` (precio por producto+proveedor).
- Endpoints: `GET /api/prices/products`, `GET /api/prices/providers`,
  `POST /api/prices/products/:name/prices` (upsert por marca).
- Pendiente: UI de carga rápida en el súper (el usuario dijo que la arma él) y
  conectar `shopping_list_items.prices` a esta tabla en vez del JSON libre actual.

## 3. Auto-agregar a la lista cuando se agota un producto — ✅ hecho
- Nuevo estado `agotado` en despensa. En Despensa, click en el punto de estado
  cicla ok → am → re → agotado.
- Al pasar a `agotado`, el backend lo agrega automáticamente a la lista de
  compras (grupo "Despensa") si no está ya.

## 4. "Modo comprar" — ✅ hecho (en la vista Lista)
- Botón "🛒 Modo comprar" alterna a un flujo por tienda.
- Selector de tienda (chips, generadas de los precios existentes).
- Filtra pendientes disponibles en esa tienda; selección independiente (carrito)
  con total en vivo y botón "Marcar comprados".

## Pendiente / siguiente
- Portar estos cambios al backend PHP (`php/`) usado en producción — por ahora
  solo están en `server/` (Node, desarrollo).
- Conectar `product_prices` como fuente real de `price-tags` en la lista.
