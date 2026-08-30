<?php
// Datos de ejemplo de Gus. Portado 1:1 desde server/src/seed.js.
// Devuelve un array asociativo con todo lo que necesita seed().

return [
    'pantry' => [
        // [name, quantity, category, expires_label, status, notes]
        ['Pechuga de pollo', '1.2 kg', 'carnes', 'Refrigerado · 4 días', 'ok', ''],
        ['Bistec de res', '800 g', 'carnes', 'Refrigerado · 3 días', 'ok', ''],
        ['Hígado de res', '500 g', 'carnes', 'Úsalo en 2 días', 'am', 'sin cebolla'],
        ['Salchichas viena', '1 paq.', 'carnes', '6 días', 'ok', ''],
        ['Jamón de pavo', '250 g', 'carnes', '5 días', 'ok', ''],
        ['Leche entera', '1 L', 'lacteos', 'Vence mañana', 're', ''],
        ['Pan molde integral', '1 funda', 'lacteos', 'Vence hoy', 're', ''],
        ['Queso crema', '1 paq.', 'lacteos', '8 días', 'ok', ''],
        ['Yogurt', '1 galón', 'lacteos', '5 días', 'ok', ''],
        ['Huevos', '22 un.', 'lacteos', '~18 días', 'ok', ''],
        ['Arroz blanco', '3.5 kg', 'granos', '8 meses', 'ok', ''],
        ['Papas chola', '2 kg', 'granos', '~10 días', 'ok', ''],
        ['Choclos', '6 un.', 'granos', 'Frescos · ~5 días', 'ok', ''],
        ['Guineos (para batido)', '6 un.', 'granos', '~4 días', 'ok', ''],
        ['Lechuga crespa', '1 un.', 'vegetales', 'Fresca · ~2 días', 'am', ''],
        ['Aguacate', '2 un.', 'vegetales', 'Fresco · ~3 días', 'am', ''],
        ['Cebolla colorada', '4 un.', 'vegetales', '~12 días', 'ok', ''],
        ['Tomate riñón', '6 un.', 'vegetales', '~7 días', 'ok', ''],
        ['Pimiento verde', '3 un.', 'vegetales', '~8 días', 'ok', ''],
        ['Atún en agua (lata)', '3 latas', 'latas', '2 años', 'ok', ''],
        ['Sardinas en tomate (lata)', '2 latas', 'latas', '2 años', 'ok', ''],
        ['Menestra de lenteja (lata)', '4 latas', 'latas', '18 meses', 'ok', ''],
        ['Pasta de ajo', '1 frasco', 'latas', '6 meses', 'ok', ''],
    ],

    'recipes' => [
        [
            'slug' => 'batido',
            'title' => 'Batido de guineo con leche o yogurt',
            'description' => 'Guineo maduro + leche entera (o yogurt) en licuadora. Porción grande: 2 guineos + 1 vaso grande. Variante: añadir avena para más saciedad.',
            'tags' => ['Sin cocción', '3 min', 'Desayuno opcional'],
            'time_label' => '3 min',
            'gradient' => 'linear-gradient(160deg,#F5E9C8,#C8A03A)',
            'ings' => ['Guineos — 1-2 unidades', 'Leche entera o yogurt — 1 vaso grande'],
            'steps' => ['Pelar guineos y partir en trozos.', 'Licuadora con leche. Licuar. Listo.'],
        ],
        [
            'slug' => 'sandwich',
            'title' => '3 sándwiches: queso crema + jamón + tortilla',
            'description' => 'Tortilla de 4 huevos frita plana en sartén. Pan molde con queso crema y jamón de pavo. Armar 3 sándwiches. Rápido y muy contundente.',
            'tags' => ['Freír tortilla', '12 min', 'Desayuno opcional'],
            'time_label' => '12 min',
            'gradient' => 'linear-gradient(160deg,#EBF5EF,#4A8F68)',
            'ings' => ['Huevos — 4 unidades', 'Pan molde — 6 rebanadas', 'Queso crema — al gusto', 'Jamón de pavo — 3-4 rebanadas'],
            'steps' => ['Batir 4 huevos con sal.', 'Freír como tortilla plana en sartén.', 'Untar queso crema en el pan.', 'Añadir jamón + tortilla. Armar 3 sándwiches.'],
        ],
        [
            'slug' => 'polloensalada',
            'title' => 'Pollo frito + ensalada + choclos',
            'description' => 'Pechuga o presas fritas con sal y ajo. Ensalada grande de lechuga crespa, tomate y aguacate con limón. Choclos fritos o hervidos. 3 cuerpos en el plato, sin arroz necesariamente.',
            'tags' => ['Freír', '20 min', 'Sin arroz ok'],
            'time_label' => '20 min',
            'gradient' => 'linear-gradient(160deg,#D0E8D8,#3A7856)',
            'ings' => ['Pollo — 2 filetes o varias presas', 'Lechuga crespa, tomate, aguacate', 'Choclos — 2 unidades', 'Limón para la ensalada'],
            'steps' => ['Freír pollo con sal y pasta ajo, 7 min c/lado.', 'Hervir o freír choclos.', 'Picar lechuga, tomate, aguacate + limón.', 'Servir los 3 elementos en el plato.'],
        ],
        [
            'slug' => 'atun',
            'title' => 'Atún con cebolla y tomate',
            'description' => '1 lata de atún en agua escurrida con cebolla colorada picada, tomate riñón y limón. Sin cocción. Solo o sobre arroz.',
            'tags' => ['Sin cocción', '3 min', 'Merienda/Cena'],
            'time_label' => '3 min',
            'gradient' => 'linear-gradient(160deg,#C8D9EA,#3A5A8F)',
            'ings' => ['Atún en lata — 1 (máx.)', 'Cebolla colorada — media', 'Tomate riñón — 1', 'Limón'],
            'steps' => ['Escurrir el atún.', 'Picar cebolla y tomate fino.', 'Mezclar todo con limón.', 'Servir solo o sobre arroz.'],
        ],
        [
            'slug' => 'higado',
            'title' => 'Hígado frito · solo sal · sin cebolla',
            'description' => 'Bistec de hígado frito con sal únicamente. Sin cebolla encima. Con arroz y menestra de lata o con ensalada si no hay menestra.',
            'tags' => ['Freír', '10 min', 'Almuerzo'],
            'time_label' => '10 min',
            'gradient' => 'linear-gradient(160deg,#EAD9C8,#8F5A3A)',
            'ings' => ['Hígado de res — bistec', 'Sal y aceite', 'Arroz + menestra de lata'],
            'steps' => ['Sartén caliente con aceite.', 'Sazonar hígado solo con sal.', 'Freír 3-4 min por lado.', 'Servir sin cebolla encima.'],
        ],
    ],

    'plan' => [
        // [weekday, meal_type, title, detail, optional]
        ['lunes', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Opcional — rápido y contundente', 1],
        ['lunes', 'Almuerzo', 'Pollo frito + ensalada (lechuga, tomate, aguacate) + choclos', 'Sin arroz · 3 cuerpos en el plato', 0],
        ['lunes', 'Merienda', 'Atún + cebolla + tomate + limón', 'Sin cocción', 0],
        ['martes', 'Desayuno', 'Batido o sándwiches — opcional', 'Según el tiempo disponible', 1],
        ['martes', 'Almuerzo', 'Bistec de res frito + arroz + menestra', 'Freír bistec con sal y ajo', 0],
        ['martes', 'Merienda', 'Pollo frito + ensalada lechuga y tomate', 'Freír presas', 0],
        ['miercoles', 'Desayuno', 'Opcional', '', 1],
        ['miercoles', 'Almuerzo', 'Hígado frito (solo sal, sin cebolla) + arroz + menestra', 'Úsalo hoy — lleva 2 días', 0],
        ['miercoles', 'Merienda', 'Sardinas en tomate + arroz + medio aguacate', 'Abrir lata', 0],
        ['jueves', 'Desayuno', 'Opcional', '', 1],
        ['jueves', 'Almuerzo', 'Pescado frito + ensalada + limón', 'Freír con sal y ajo', 0],
        ['jueves', 'Merienda', 'Puré de papas + arroz + carne frita', 'Puré siempre con arroz', 0],
        ['viernes', 'Desayuno', 'Opcional', '', 1],
        ['viernes', 'Almuerzo', 'Camarones fritos al ajo + arroz + ensalada tomate', '3 min por lado', 0],
        ['viernes', 'Merienda', 'Carne en trozos + tomate + pimiento + arroz', 'Salsita rápida', 0],
        ['sabado', 'Desayuno', 'Opcional', '', 1],
        ['sabado', 'Almuerzo', 'Chancho frito + arroz + menestra + ensalada', '', 0],
        ['sabado', 'Merienda', 'Arroz + tortilla de huevo + salchicha', '', 0],
        ['domingo', 'Desayuno', 'Encebollado comprado (dosis doble) + chifle + pan', 'No preparar — comprar hecho', 0],
        ['domingo', 'Almuerzo', 'Pollo frito + arroz + puré de papas + ensalada', 'Puré siempre con arroz', 0],
        ['domingo', 'Merienda', 'Sándwiches triples queso crema + jamón', 'Sin cocción', 0],
    ],

    'totals' => [
        '3 días' => '$19.40',
        '1 semana' => '$47.80',
        '2 semanas' => '$89.50',
        '3 semanas' => '$128.00',
        '1 mes' => '$162.00',
    ],

    'shopping_items' => [
        // [group_label, name, qty, checked, prices]
        ['Urgentes', 'Pan molde integral', 1, 0, [['store' => 'Supermaxi', 'price' => '$1.45', 'best' => true], ['store' => 'Mi Comisariato', 'price' => '$1.60']]],
        ['Urgentes', 'Leche entera', 2, 0, [['store' => 'Mi Comisariato', 'price' => '$1.05', 'best' => true], ['store' => 'Supermaxi', 'price' => '$1.20'], ['store' => 'Tía', 'price' => '$1.15']]],
        ['Carnes', 'Camarones pelados', 1, 0, [['store' => 'Puerto Durán', 'price' => '$3.80/lb', 'best' => true], ['store' => 'Supermaxi', 'price' => '$5.50/lb']]],
        ['Carnes', 'Chuleta de chancho', 3, 0, [['store' => 'Mercado Urdesa', 'price' => '$2.10', 'best' => true], ['store' => 'Supermaxi', 'price' => '$2.90']]],
        ['Carnes', 'Aguacate', 4, 1, [['store' => 'Mercado Urdesa', 'price' => '$0.30/un', 'best' => true]]],
        ['Desayuno', 'Guineos para batido', 8, 0, [['store' => 'Mercado Urdesa', 'price' => '$0.15/un', 'best' => true], ['store' => 'Supermaxi', 'price' => '$0.25/un']]],
        ['Desayuno', 'Queso crema', 2, 0, [['store' => 'Mi Comisariato', 'price' => '$2.30', 'best' => true], ['store' => 'Supermaxi', 'price' => '$2.70']]],
    ],

    'discoveries' => [
        // [title, source, link, meta, rating, gradient]
        ['Marisquería El Puerto — camarones', 'Instagram', 'https://instagram.com', 'Urdesa · Marisco fresco', 0, 'linear-gradient(135deg,#D5EDCC,#4A8F68)'],
        ['Encebollado del Malecón — dosis doble', 'Facebook', 'https://facebook.com', 'Centro · desde 5am', 4, 'linear-gradient(135deg,#EAD9C8,#8F6A4A)'],
        ['Mercado Urdesa — frescos directo', 'Instagram', 'https://instagram.com', 'Sáb 7am–1pm', 3, 'linear-gradient(135deg,#DAE8D4,#4A7856)'],
    ],

    // nutrition_log de hoy: [calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target]
    'nutrition_today' => [2100, 2800, 95, 140, 190, 300, 52, 80],
];
