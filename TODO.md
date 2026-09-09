# Tareas pendientes

## 1. Conectar el chat con la lista de compras
El chat no puede ver ni modificar la lista de compras actual. Al pedirle que
agregue lo que falta, no tiene forma de consultarla.
- Dar al chat acceso de lectura a `shopping_list_items`.
- Permitir que agregue/marque ítems en la lista desde la conversación.

## 2. Tabla de productos, proveedores y precios
No existe. Hoy `shopping_list_items.prices` es solo texto libre.
- Crear modelo: `products` (producto genérico), `providers` (marca/proveedor),
  `product_prices` (producto + proveedor + precio, historial o último precio).
- Permitir cargar un precio de forma rápida al ver una marca en el súper.

## 3. Auto-agregar a la lista cuando se agota un producto
Verificar si ya existe esta lógica (pantry_items.status → shopping_list_items).
Si no está, implementarla: al marcar un producto de la despensa como agotado,
debe aparecer automáticamente en la lista de compras.

## 4. "Modo comprar"
Flujo para comprar en distintas tiendas en momentos distintos:
- Elegir la tienda/lugar donde se está comprando.
- Filtrar de la lista de compras los productos disponibles en ese lugar.
- Seleccionar cuáles de esos se compran ahora (no todos los filtrados).
- Mostrar el total en vivo de lo seleccionado, para cuadrar con lo que se
  lleva físicamente en el carrito.
