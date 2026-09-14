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
            'tags' => ['Sin cocción', '3 min', 'Desayuno'],
            'time_label' => '3 min',
            'gradient' => 'linear-gradient(160deg,#F5E9C8,#C8A03A)',
            'ings' => ['Guineos — 1-2 unidades', 'Leche entera o yogurt — 1 vaso grande'],
            'steps' => ['Pelar guineos y partir en trozos.', 'Licuadora con leche. Licuar. Listo.'],
        ],
        [
            'slug' => 'sandwich',
            'title' => '3 sándwiches: queso crema + jamón + tortilla',
            'description' => 'Tortilla de 4 huevos frita plana en sartén. Pan molde con queso crema y jamón de pavo. Armar 3 sándwiches. Rápido y muy contundente.',
            'tags' => ['Freír tortilla', '12 min', 'Desayuno'],
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
        // El desayuno NO es opcional: de lunes a sábado es el de siempre
        // (batido + sándwiches); el domingo, encebollado comprado.
        ['lunes', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Rápido y contundente', 0],
        ['lunes', 'Almuerzo', 'Pollo frito + ensalada (lechuga, tomate, aguacate) + choclos', 'Sin arroz · 3 cuerpos en el plato', 0],
        ['lunes', 'Merienda', 'Atún + cebolla + tomate + limón', 'Sin cocción', 0],
        ['martes', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Rápido y contundente', 0],
        ['martes', 'Almuerzo', 'Bistec de res frito + arroz + menestra', 'Freír bistec con sal y ajo', 0],
        ['martes', 'Merienda', 'Pollo frito + ensalada lechuga y tomate', 'Freír presas', 0],
        ['miercoles', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Rápido y contundente', 0],
        ['miercoles', 'Almuerzo', 'Hígado frito (solo sal, sin cebolla) + arroz + menestra', 'Úsalo hoy — lleva 2 días', 0],
        ['miercoles', 'Merienda', 'Sardinas en tomate + arroz + medio aguacate', 'Abrir lata', 0],
        ['jueves', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Rápido y contundente', 0],
        ['jueves', 'Almuerzo', 'Pescado frito + ensalada + limón', 'Freír con sal y ajo', 0],
        ['jueves', 'Merienda', 'Puré de papas + arroz + carne frita', 'Puré siempre con arroz', 0],
        ['viernes', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Rápido y contundente', 0],
        ['viernes', 'Almuerzo', 'Camarones fritos al ajo + arroz + ensalada tomate', '3 min por lado', 0],
        ['viernes', 'Merienda', 'Carne en trozos + tomate + pimiento + arroz', 'Salsita rápida', 0],
        ['sabado', 'Desayuno', 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla', 'Rápido y contundente', 0],
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
        // Ejemplo de precios cargados a mano; los que no tengan uno se completan
        // con el catálogo REAL de product_prices si existe (php/src/shopping.php).
        ['Urgentes', 'Pan molde integral', 1, 0, [['store' => 'Super Maxi', 'price' => '$1.45', 'best' => true], ['store' => 'Mi Comisariato', 'price' => '$1.60']]],
        ['Urgentes', 'Leche entera', 2, 0, [['store' => 'Mi Comisariato', 'price' => '$1.05', 'best' => true], ['store' => 'Super Maxi', 'price' => '$1.20'], ['store' => 'Tía', 'price' => '$1.15']]],
        ['Carnes', 'Camarones pelados', 1, 0, []],
        ['Carnes', 'Chuleta de chancho', 3, 0, [['store' => 'Mercado', 'price' => '$2.10', 'best' => true], ['store' => 'Super Maxi', 'price' => '$2.90']]],
        ['Carnes', 'Aguacate', 4, 1, []],
        ['Desayuno', 'Guineos para batido', 8, 0, []],
        ['Desayuno', 'Queso crema', 2, 0, [['store' => 'Mi Comisariato', 'price' => '$2.30', 'best' => true], ['store' => 'Super Maxi', 'price' => '$2.70']]],
    ],

    'discoveries' => [
        // [title, source, link, meta, rating, gradient, visited, dish_note]
        // visited = 0 -> "por probar";  visited = 1 -> "mis lugares" (ya fui) + qué pedir
        ['Marisquería El Puerto', 'Recomendación', '', 'Urdesa · marisco fresco', 5, 'linear-gradient(135deg,#D5EDCC,#4A8F68)', 1, 'Pídete los camarones apanados. El arroz marinero flojea.'],
        ['Encebollado del Malecón', 'Ya lo conozco', '', 'Centro · desde 5am', 4, 'linear-gradient(135deg,#EAD9C8,#8F6A4A)', 1, 'Encebollado doble con el pan y el chifle aparte.'],
        ['Bolonería de la 9 de Octubre', 'Ya lo conozco', '', 'Centro · mañanas', 4, 'linear-gradient(135deg,#F3E4C4,#C99A44)', 1, 'Bolón mixto (queso + chicharrón) y jugo de naranja.'],
        ['Cangrejal del Sur', 'Instagram', 'https://instagram.com', 'Sur · fines de semana', 0, 'linear-gradient(135deg,#D5E4EF,#3A6A8F)', 0, ''],
        ['Mercado de Urdesa — frescos directo', 'Instagram', 'https://instagram.com', 'Sáb 7am–1pm', 3, 'linear-gradient(135deg,#DAE8D4,#4A7856)', 0, ''],
    ],

    'occasions' => [
        // [slug, emoji, title, subtitle, sort_order, items[]]
        //   item: [label, detail, place ('casa'|'fuera'), price]
        [
            'noche-de-pelis', '🎬', 'Noche de pelis', 'Para picar mientras ven la peli', 0,
            [
                ['Sándwiches de atún', 'Pan + atún + tomate · sin cocción', 'casa', 'En casa · $0 extra'],
                ['Trocitos de pollo fritos', 'Cubos de pechuga con sal y ajo · 5 min', 'casa', 'En casa · $0 extra'],
                ['Canguil con mantequilla', 'Una olla, 4 min', 'casa', 'En casa · ~$0.50'],
                ['Hot dog + papas fritas', 'Pedir a domicilio o ir a buscar', 'fuera', 'Hot dog $1.25 + papas $2.00 = $3.25'],
                ['Hamburguesa completa', 'Más contundente para noche larga', 'fuera', 'Hamburguesa $3.00 + papas $2.00 = $5.00'],
                ['Pizza familiar', 'Si son varios', 'fuera', '~$12 la familiar'],
            ],
        ],
        [
            'desayuno-con-alguien', '🫓', 'Desayuno con alguien', 'Salir a desayunar típico guayaco', 1,
            [
                ['Bolón mixto', 'Queso + chicharrón, con café', 'fuera', 'Bolón $2.75 + café $0.75'],
                ['Humitas grandes', 'Para compartir', 'fuera', '3 por $5'],
                ['Tigrillo', 'Verde majado con huevo y queso', 'fuera', '$3.50'],
                ['Bollo de pescado', 'En hoja, bien lleno', 'fuera', '$2.50'],
                ['Huevos revueltos + pan + café', 'Si desayunan en casa', 'casa', 'En casa · ~$1.50 los dos'],
                ['Bolón casero', 'Con el verde que haya en casa', 'casa', 'En casa · ~$1'],
            ],
        ],
        [
            'visita-en-casa', '🏠', 'Cuando viene gente', 'Algo rápido para atender la visita', 2,
            [
                ['Picada de queso, chifles y maní', 'Se arma en 5 min', 'casa', 'En casa · ~$3'],
                ['Sánduches de miga', 'Comprados, rinden bastante', 'fuera', '~$4 la docena'],
                ['Canguil y gaseosa', 'Lo básico', 'casa', 'En casa · ~$2'],
                ['Pizza familiar a domicilio', 'Si se quedan a la hora de comer', 'fuera', '~$12'],
                ['Alitas para compartir', 'Con el pedido de pizza', 'fuera', '~$8 las 12'],
            ],
        ],
        [
            'antojo-de-finde', '🍢', 'Antojo de finde', 'Salir a picar sin plan fijo', 3,
            [
                ['Pinchos de la esquina', 'Con papa y ají', 'fuera', '$1.50 c/u'],
                ['Salchipapa', 'Para compartir', 'fuera', '$3 la grande'],
                ['Ceviche de concha', 'Bien picante', 'fuera', '~$5'],
                ['Choclos con queso', 'Si se quedan en casa', 'casa', 'En casa · ~$1.50'],
                ['Tostado con chicharrón', 'Para la tarde', 'casa', 'En casa · ~$2'],
            ],
        ],
    ],

    // nutrition_log de hoy: [calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target]
    'nutrition_today' => [2100, 2800, 95, 140, 190, 300, 52, 80],

    // Perfil del hogar y preferencias que usa Ali (assistant_prefs).
    // Se hace UPSERT: reseedar no borra las preferencias que el usuario cambió,
    // pero sí restablece estas claves base.
    'prefs' => [
        'nombre_usuario'        => 'Gus',
        'ciudad'                => 'Guayaquil, Ecuador',
        'personas'              => '1',
        'porciones_por_comida'  => '3',
        'gramos_por_porcion'    => '150',
        'objetivos'             => 'comer suficiente proteína, cocinar rápido, no desperdiciar, aprovechar lo que vence',
        'tiempo_cocina'         => '20-30 min entre semana',
        'nivel_picante'         => 'bajo',
        'tecnicas_permitidas'   => 'freír, hervir, abrir latas (sin horno, sin sopas, sin recetas complicadas)',
        'electrodomesticos'     => 'sartén, olla, licuadora',
        'alergias'              => 'ninguna registrada',
        'no_le_gusta'           => 'cebolla encima del hígado; patacones en casa; menestra y ensalada en el mismo plato',
        'supermercados'         => 'Mi Comisariato, Super Maxi, Tía, Mercado, Tuti, Tienda',
        'desayuno'              => 'de lunes a sábado: batido de guineo + 3 sándwiches (queso crema + jamón + tortilla); domingo: encebollado comprado. No es opcional.',
        'reglas_cocina'         => "1. Solo freír, hervir o abrir latas — sin sopas, sin horno, sin recetas complicadas.\n2. Porciones triples — no lo cuestiones.\n3. El arroz NO es obligatorio: puede ser proteína + ensalada + otro carbohidrato (choclo, puré).\n4. Encebollado: solo domingos al desayuno, y comprado hecho (no preparar).\n5. El puré siempre va con arroz, nunca solo.\n6. Menestra y ensalada no van juntas en el mismo plato.\n7. Sardinas nunca en el desayuno.\n8. Hígado sin cebolla encima.\n9. Sin patacones en casa (toma tiempo).\n10. El desayuno NO es opcional: de lunes a sábado es batido de guineo + 3 sándwiches (queso crema + jamón + tortilla); el domingo, encebollado comprado.",
    ],
];
