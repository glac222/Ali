<?php
declare(strict_types=1);

/**
 * LISTA DE COMPRAS — generación desde el PLAN semanal + cálculo del total.
 *
 * Vive aparte de routes.php a propósito, para no chocar con otras ediciones.
 * routes.php solo hace `require __DIR__ . '/shopping.php'` y llama a:
 *   - shopping_list_generate($period)  ->  botón "Generar lista"
 *   - shopping_list_total($period)      ->  el "Estimado" que se muestra
 *
 * Idea:
 *   El plan (meal_plan) dice QUÉ se cocina cada día. De ahí salen los productos
 *   y las cantidades a comprar, escalados por el período elegido y descontando lo
 *   que YA hay en la despensa. Si hay API key de DeepSeek, Ali desglosa el plan
 *   en ingredientes; si no, una tabla de equivalencias determinista hace el
 *   trabajo para que la lista nunca salga vacía.
 *
 *   El total NO se inventa: es la suma de (mejor precio × cantidad) de los ítems
 *   que TIENEN precio cargado. Los demás se cuentan aparte ("N sin precio").
 *
 * Depende de helpers de chat.php (se requiere antes): qty_norm_unit, qty_num,
 * ali_deaccent, ali_gpp, ali_porciones_comida, pantry_resolve, deepseek_call,
 * chat_is_configured. Y de bootstrap.php: q_all/q_one/q_exec/db/fail.
 */

/** Cuánto de una semana-tipo compra cada período. */
const SHOP_PERIOD_FACTOR = [
    '3 días' => 0.5, '1 semana' => 1.0, '2 semanas' => 2.0,
    '3 semanas' => 3.0, '1 mes' => 4.0,
];

/** Etiqueta de grupo por categoría (lo que agrupa la pantalla Lista). */
const SHOP_GROUP_LABEL = [
    'carnes' => 'Carnes', 'lacteos' => 'Lácteos y pan', 'granos' => 'Granos',
    'vegetales' => 'Vegetales', 'latas' => 'Latas y despensa', 'otros' => 'Otros',
];

/** Siempre hay en casa: nunca entran a la lista. */
const SHOP_STAPLES = [
    'sal', 'aceite', 'agua', 'azucar', 'pimienta', 'comino', 'achiote', 'vinagre',
    'ajo', 'limon', 'lima', 'sazon', 'especias', 'condimentos', 'canela', 'clavo',
];

/**
 * Tabla determinista palabra-clave -> ingrediente, POR PORCIÓN (≈1 persona).
 * Se multiplica por las porciones/comida (pref, def. 3) y por cada aparición en
 * el plan. Solo se usa si Ali (IA) no está disponible. Ajústala con confianza.
 *
 *   token => [nombre canónico, valor por porción, unidad, categoría]
 */
const SHOP_KEYWORDS = [
    'higado'      => ['Hígado de res', 160, 'g', 'carnes'],
    'bistec'      => ['Bistec de res', 160, 'g', 'carnes'],
    ' res'        => ['Bistec de res', 160, 'g', 'carnes'],
    'carne en'    => ['Bistec de res', 160, 'g', 'carnes'],
    'carne frita' => ['Bistec de res', 160, 'g', 'carnes'],
    'pollo'       => ['Pechuga de pollo', 180, 'g', 'carnes'],
    'pechuga'     => ['Pechuga de pollo', 180, 'g', 'carnes'],
    'chancho'     => ['Carne de chancho', 170, 'g', 'carnes'],
    'chuleta'     => ['Chuleta de chancho', 1, 'un', 'carnes'],
    'camaron'     => ['Camarones', 140, 'g', 'carnes'],
    'pescado'     => ['Pescado', 200, 'g', 'carnes'],
    'salchicha'   => ['Salchichas viena', 2, 'un', 'carnes'],
    'jamon'       => ['Jamón de pavo', 45, 'g', 'carnes'],
    'huevo'       => ['Huevos', 2, 'un', 'lacteos'],
    'tortilla'    => ['Huevos', 2, 'un', 'lacteos'],
    'leche'       => ['Leche entera', 0.25, 'l', 'lacteos'],
    'yogurt'      => ['Yogurt', 0.2, 'l', 'lacteos'],
    'queso'       => ['Queso crema', 0.2, 'paq', 'lacteos'],
    'pan'         => ['Pan molde integral', 0.2, 'funda', 'lacteos'],
    'sandwich'    => ['Pan molde integral', 0.25, 'funda', 'lacteos'],
    'arroz'       => ['Arroz blanco', 90, 'g', 'granos'],
    'pure'        => ['Papas chola', 230, 'g', 'granos'],
    'papa'        => ['Papas chola', 200, 'g', 'granos'],
    'choclo'      => ['Choclos', 1, 'un', 'granos'],
    'guineo'      => ['Guineos (para batido)', 2, 'un', 'granos'],
    'batido'      => ['Guineos (para batido)', 2, 'un', 'granos'],
    'avena'       => ['Avena', 40, 'g', 'granos'],
    'menestra'    => ['Menestra de lenteja (lata)', 0.5, 'lata', 'latas'],
    'lenteja'     => ['Menestra de lenteja (lata)', 0.5, 'lata', 'latas'],
    'atun'        => ['Atún en agua (lata)', 0.5, 'lata', 'latas'],
    'sardina'     => ['Sardinas en tomate (lata)', 0.5, 'lata', 'latas'],
    'lechuga'     => ['Lechuga crespa', 0.3, 'un', 'vegetales'],
    'ensalada'    => ['Lechuga crespa', 0.3, 'un', 'vegetales'],
    'tomate'      => ['Tomate riñón', 1, 'un', 'vegetales'],
    'aguacate'    => ['Aguacate', 0.5, 'un', 'vegetales'],
    'cebolla'     => ['Cebolla colorada', 0.3, 'un', 'vegetales'],
    'pimiento'    => ['Pimiento verde', 0.5, 'un', 'vegetales'],
    'encebollado' => ['Encebollado (comprado)', 0.4, 'un', 'otros'],
    'chifle'      => ['Chifle', 0.4, 'funda', 'otros'],
];

/** Unidades que se compran "de a una": la cantidad va como qty entero. */
const SHOP_COUNT_UNITS = ['un', 'lata', 'paq', 'funda', 'frasco', 'presa', 'filete', 'diente'];

// ---------------------------------------------------------------------------
//  Precios de referencia por tienda
// ---------------------------------------------------------------------------

/** Tiendas donde compra el hogar. El catálogo cotiza cada producto en algunas. */
const SHOP_STORES = ['Mi Comisariato', 'Super Maxi', 'Tía', 'Mercado', 'Tuti', 'Tienda'];

/**
 * Unidades a granel: si el precio real cargado para el producto está en esta
 * unidad, se multiplica por la cantidad del ítem ("· 1.8 kg" -> precio × 1.8).
 * Cualquier otra unidad (unidad, lata, caja, pack...) se toma como precio ya
 * cerrado por lo que se compra; la columna qty de la lista lo multiplica.
 */
const SHOP_BULK_UNITS = ['kg', 'l'];

// ---------------------------------------------------------------------------
//  Unidades: base común para poder sumar y restar contra la despensa
// ---------------------------------------------------------------------------

/** @return array{base: float, kind: string}|null */
function shop_to_base(float $v, string $unit): ?array
{
    $unit = $unit === '' ? 'un' : $unit;
    if (isset(ALI_MASS[$unit])) {
        return ['base' => $v * ALI_MASS[$unit], 'kind' => 'mass'];
    }
    if ($unit === 'porcion') {
        return ['base' => $v * ali_gpp(), 'kind' => 'mass'];
    }
    if (isset(ALI_VOL[$unit])) {
        return ['base' => $v * ALI_VOL[$unit], 'kind' => 'vol'];
    }
    if ($unit === 'taza') {
        return ['base' => $v * 240.0, 'kind' => 'vol'];
    }
    if (in_array($unit, ALI_COUNT, true)) {
        return ['base' => $v, 'kind' => 'count:' . $unit];
    }
    return null;
}

/** Convierte $v de $from a $to. null si no hay conversión segura. */
function shop_convert(float $v, string $from, string $to): ?float
{
    $from = $from === '' ? 'un' : $from;
    $to = $to === '' ? 'un' : $to;
    if ($from === $to) {
        return $v;
    }
    $a = shop_to_base($v, $from);
    $unit = shop_to_base(1.0, $to);
    if ($a === null || $unit === null || $a['kind'] !== $unit['kind'] || $unit['base'] == 0.0) {
        return null;
    }
    return $a['base'] / $unit['base'];
}

/** Texto de compra amable: 1800 g -> "1.8 kg"; 750 ml -> "750 ml". */
function shop_format_amount(float $v, string $unit): string
{
    $b = shop_to_base($v, $unit);
    if ($b === null) {
        return qty_num($v) . ($unit !== '' ? ' ' . $unit : '');
    }
    if ($b['kind'] === 'mass') {
        return $b['base'] >= 1000
            ? qty_num(round($b['base'] / 1000, 2)) . ' kg'
            : qty_num(max(50.0, round($b['base'] / 50) * 50)) . ' g';
    }
    if ($b['kind'] === 'vol') {
        return $b['base'] >= 1000
            ? qty_num(round($b['base'] / 1000, 2)) . ' L'
            : qty_num(max(50.0, round($b['base'] / 50) * 50)) . ' ml';
    }
    return qty_num((float) ceil($v - 0.001)) . ' un.';
}

/** Nombre sin el sufijo " · 1.8 kg", en minúsculas y sin tildes (clave estable). */
function shop_basename_key(string $name): string
{
    $head = preg_split('/\s+·\s+/u', trim($name))[0] ?? $name;
    return ali_deaccent(mb_strtolower(trim($head)));
}

function shop_is_staple(string $name): bool
{
    $n = ali_deaccent(mb_strtolower($name));
    foreach (SHOP_STAPLES as $s) {
        if (str_contains($n, $s)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
//  Necesidades del plan
// ---------------------------------------------------------------------------

/**
 * Suma una necesidad al acumulador $out (clave = nombre normalizado).
 * Si ya existe con otra unidad, intenta convertir; si no puede, la deja aparte.
 */
function shop_merge_need(array &$out, string $name, float $value, string $unit, string $category): void
{
    if ($value <= 0 || $name === '' || shop_is_staple($name)) {
        return;
    }
    if (!isset(SHOP_GROUP_LABEL[$category])) {
        $category = 'otros';
    }
    $key = shop_basename_key($name);
    if (isset($out[$key])) {
        $conv = shop_convert($value, $unit, $out[$key]['unit']);
        if ($conv !== null) {
            $out[$key]['value'] += $conv;
            return;
        }
        $key .= '|' . $unit; // unidades incompatibles: entrada separada
        if (isset($out[$key])) {
            $out[$key]['value'] += $value;
            return;
        }
    }
    $out[$key] = ['name' => $name, 'value' => $value, 'unit' => $unit, 'category' => $category];
}

/**
 * Desglose vía DeepSeek: total semanal de cada ingrediente. null si no hay key,
 * si la llamada falla o si la respuesta no es utilizable (el caller cae a la
 * tabla determinista).
 *
 * @param array<int,string> $planLines
 * @return array<string,array{name:string,value:float,unit:string,category:string}>|null
 */
function shopping_needs_via_ia(array $planLines, float $servings): ?array
{
    if (!function_exists('deepseek_call') || !function_exists('chat_is_configured') || !chat_is_configured()) {
        return null;
    }

    $srv = qty_num($servings);
    $sys = <<<TXT
Eres un planificador de compras para un hogar de Guayaquil, Ecuador.
Te doy el plan semanal de comidas. Devuelve SOLO un JSON válido (sin texto ni
explicación alrededor) con la cantidad TOTAL de cada ingrediente para UNA SEMANA.

Reglas:
- Asume {$srv} porciones por cada comida (una porción ≈ 150 g de proteína).
- Las comidas marcadas "(opcional)" cuéntalas a la mitad.
- NO incluyas sal, aceite, agua, azúcar, ajo, limón, pimienta ni especias
  (siempre hay en casa).
- Unidades permitidas SOLO: g, kg, ml, l, un, lata, paq, funda.
- Categorías SOLO: carnes, lacteos, granos, vegetales, latas, otros.
- Si una comida se compra hecha (p. ej. encebollado), pon el plato como 1 ítem
  en categoría "otros".
- Agrupa: un ingrediente aparece una sola vez, con su total.

Formato EXACTO:
{"items":[{"nombre":"Pechuga de pollo","cantidad":1800,"unidad":"g","categoria":"carnes"}]}
TXT;

    $plan = implode("\n", $planLines);
    try {
        $resp = deepseek_call([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => "Plan semanal:\n{$plan}"],
        ], null);
    } catch (Throwable $e) {
        error_log('[ali] shopping IA: ' . $e->getMessage());
        return null;
    }

    $txt = trim((string) ($resp['content'] ?? ''));
    if ($txt === '') {
        return null;
    }
    $txt = (string) preg_replace('/```(?:json)?/i', '', $txt);
    $a = strpos($txt, '{');
    $b = strrpos($txt, '}');
    $json = ($a !== false && $b !== false && $b > $a) ? substr($txt, $a, $b - $a + 1) : $txt;

    $data = json_decode($json, true);
    $items = (is_array($data) && isset($data['items']) && is_array($data['items'])) ? $data['items'] : null;
    if ($items === null) {
        // Salvamento ante respuesta truncada: rescata objetos sueltos.
        if (preg_match_all('/\{[^{}]*"nombre"[^{}]*\}/u', $json, $mm)) {
            $items = [];
            foreach ($mm[0] as $frag) {
                $o = json_decode($frag, true);
                if (is_array($o)) {
                    $items[] = $o;
                }
            }
        }
    }
    if (!$items) {
        return null;
    }

    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = trim((string) ($it['nombre'] ?? $it['name'] ?? ''));
        $value = (float) ($it['cantidad'] ?? $it['qty'] ?? 0);
        $unit = qty_norm_unit((string) ($it['unidad'] ?? $it['unit'] ?? ''));
        $cat = strtolower(trim((string) ($it['categoria'] ?? $it['category'] ?? 'otros')));
        if ($name === '' || $value <= 0) {
            continue;
        }
        shop_merge_need($out, $name, $value, $unit, $cat);
    }
    return $out ?: null;
}

/**
 * Desglose determinista: recorre el plan y aplica la tabla SHOP_KEYWORDS.
 * Nunca sale vacío si el plan tiene comidas reconocibles.
 *
 * @param array<int,array<string,mixed>> $planMeals
 * @return array<string,array{name:string,value:float,unit:string,category:string}>
 */
function shopping_needs_deterministic(array $planMeals, float $servings): array
{
    $out = [];
    foreach ($planMeals as $meal) {
        $weight = !empty($meal['optional']) ? 0.5 : 1.0;
        $hay = ' ' . ali_deaccent(mb_strtolower(($meal['title'] ?? '') . ' ' . ($meal['detail'] ?? ''))) . ' ';
        $addedThisMeal = []; // no sumar dos veces el mismo ingrediente por comida
        foreach (SHOP_KEYWORDS as $kw => $spec) {
            if (isset($addedThisMeal[$spec[0]]) || !str_contains($hay, ali_deaccent($kw))) {
                continue;
            }
            $addedThisMeal[$spec[0]] = true;
            shop_merge_need($out, $spec[0], (float) $spec[1] * $servings * $weight, $spec[2], $spec[3]);
        }
    }
    return $out;
}

/** Da forma de fila de lista: [nombre, qty(int), grupo]. */
function shop_emit_shape(array $need): array
{
    $unit = $need['unit'] === '' ? 'un' : $need['unit'];
    $value = (float) $need['value'];
    $group = !empty($need['urgent']) ? 'Urgentes' : (SHOP_GROUP_LABEL[$need['category']] ?? 'Otros');

    if (in_array($unit, SHOP_COUNT_UNITS, true)) {
        return [$need['name'], max(1, (int) ceil($value - 0.001)), $group];
    }
    return [$need['name'] . ' · ' . shop_format_amount($value, $unit), 1, $group];
}

/** La parte de cantidad de un nombre de lista: "Pollo · 1.8 kg" -> "1.8 kg". */
function shop_name_amount(string $name): string
{
    $parts = preg_split('/\s+·\s+/u', trim($name), 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

/**
 * Todos los precios reales cargados (recibo o carga manual), agrupados por
 * clave de producto normalizada (sin tildes, minúsculas). Se cachea por
 * request: el catálogo no cambia dentro de una misma respuesta.
 *
 * @return array<string,array<int,array{price:float,unit:string,provider:string}>>
 */
function shop_real_price_catalog(): array
{
    static $byKey = null;
    if ($byKey !== null) {
        return $byKey;
    }
    $rows = q_all(
        'SELECT products.name AS product_name, product_prices.price, product_prices.unit,
                providers.name AS provider_name
         FROM product_prices
         JOIN products ON products.id = product_prices.product_id
         JOIN providers ON providers.id = product_prices.provider_id'
    );
    $byKey = [];
    foreach ($rows as $r) {
        $key = ali_deaccent(mb_strtolower(trim((string) $r['product_name'])));
        if ($key === '') {
            continue;
        }
        $byKey[$key][] = ['price' => (float) $r['price'], 'unit' => (string) $r['unit'], 'provider' => (string) $r['provider_name']];
    }
    return $byKey;
}

/**
 * Precios REALES (cargados desde un recibo o a mano) para una fila de la
 * lista, buscando por coincidencia de nombre en el catálogo de productos.
 * Marca como best el más barato. No toca la BD ni inventa nada: si no hay un
 * precio real cargado para ese producto, devuelve [] y el ítem queda "sin
 * precio" en el total (ver shopping_list_total).
 *
 * @param array{name?:string,group_label?:string} $row
 * @return array<int,array{store:string,price:string,best?:bool}>
 */
function shop_ref_prices_for_row(array $row): array
{
    $name = (string) ($row['name'] ?? '');
    $key = shop_basename_key($name);
    if ($key === '') {
        return [];
    }

    $matches = [];
    foreach (shop_real_price_catalog() as $productKey => $entries) {
        if (str_contains($key, $productKey) || str_contains($productKey, $key)) {
            array_push($matches, ...$entries);
        }
    }
    if (!$matches) {
        return [];
    }

    $tags = [];
    $cheapIdx = 0;
    $cheap = INF;
    foreach ($matches as $i => $m) {
        $val = $m['price'];
        // Unidad a granel (kg/L): el precio cargado es por unidad de peso/volumen;
        // se multiplica por la cantidad del ítem ("· 1.8 kg" -> precio × 1.8).
        if (in_array($m['unit'], SHOP_BULK_UNITS, true)) {
            $amt = qty_parse(shop_name_amount($name));
            if ($amt['value'] !== null) {
                $conv = shop_convert((float) $amt['value'], $amt['unit'] !== '' ? $amt['unit'] : $m['unit'], $m['unit']);
                if ($conv !== null && $conv > 0) {
                    $val *= $conv;
                }
            }
        }
        $val = round($val, 2);
        if ($val < $cheap) {
            $cheap = $val;
            $cheapIdx = $i;
        }
        $tags[] = ['store' => $m['provider'], 'price' => '$' . number_format($val, 2)];
    }
    $tags[$cheapIdx]['best'] = true;
    return $tags;
}

// ---------------------------------------------------------------------------
//  Generación
// ---------------------------------------------------------------------------

/**
 * Reconstruye los ítems `source='auto'` de un período desde el plan semanal.
 * Nunca toca lo manual / de escáner, y conserva checked/prices de lo auto
 * anterior (por nombre base).
 */
function shopping_list_generate(string $period): array
{
    if (!isset(SHOP_PERIOD_FACTOR[$period])) {
        fail('period inválido: ' . $period);
    }
    $mult = SHOP_PERIOD_FACTOR[$period];
    $servings = function_exists('ali_porciones_comida') ? ali_porciones_comida() : 3.0;

    // Plan, sin filas vacías o "Opcional" sueltas (placeholders viejos del seed).
    $planMeals = array_values(array_filter(
        q_all("SELECT weekday, meal_type, title, detail, optional FROM meal_plan ORDER BY sort_order"),
        function ($m) {
            $t = trim(mb_strtolower((string) $m['title']));
            return $t !== '' && $t !== 'opcional';
        }
    ));

    $planLines = array_map(function ($m) {
        $detail = trim((string) $m['detail']) !== '' ? ' — ' . $m['detail'] : '';
        $opt = !empty($m['optional']) ? ' (opcional)' : '';
        return "{$m['weekday']} · {$m['meal_type']}: {$m['title']}{$detail}{$opt}";
    }, $planMeals);

    $needs = shopping_needs_via_ia($planLines, $servings);
    $usedIa = $needs !== null && $needs !== [];
    if (!$usedIa) {
        $needs = shopping_needs_deterministic($planMeals, $servings);
    }

    // Escala por período.
    foreach ($needs as &$n) {
        $n['value'] *= $mult;
    }
    unset($n);

    // Descuenta lo que ya hay en la despensa. Un producto 're' (agotado) no
    // descuenta aunque tenga un número viejo guardado, y va al grupo "Urgentes".
    foreach ($needs as &$n) {
        $res = pantry_resolve($n['name']);
        $match = $res['match'] ?? null;
        if (!$match) {
            continue;
        }
        $status = (string) ($match['status'] ?? 'ok');
        if ($status === 're') {
            $n['urgent'] = true;
        } elseif ($match['qty_value'] !== null && (string) $match['qty_unit'] !== '') {
            $have = shop_convert((float) $match['qty_value'], (string) $match['qty_unit'], $n['unit']);
            if ($have !== null && $have > 0) {
                $n['value'] = max(0.0, $n['value'] - $have);
            }
        }
    }
    unset($n);

    // Nombres ya presentes a mano / por escáner / por chat: no duplicar.
    // Ya no se filtra por período: hay UNA sola lista; el período solo escala
    // las cantidades (el $mult de arriba). Así no quedan "silos" vacíos por
    // período sin generar.
    $manual = [];
    foreach (q_all("SELECT name FROM shopping_list_items WHERE source <> 'auto'") as $r) {
        $manual[shop_basename_key((string) $r['name'])] = true;
    }
    // Estado previo de lo 'auto' para conservar checked / precios.
    $prev = [];
    foreach (q_all("SELECT name, checked, prices FROM shopping_list_items WHERE source = 'auto'") as $r) {
        $prev[shop_basename_key((string) $r['name'])] = $r;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q_exec("DELETE FROM shopping_list_items WHERE source = 'auto'");
        $sort = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items')['m']);
        $created = 0;
        $seen = [];

        foreach ($needs as $need) {
            if ($need['value'] <= 0.05) {
                continue;
            }
            [$name, $qty, $group] = shop_emit_shape($need);
            $bk = shop_basename_key($name);
            $seen[$bk] = true;
            if (isset($manual[$bk])) {
                continue;
            }
            $old = $prev[$bk] ?? null;
            q_exec(
                "INSERT INTO shopping_list_items (period, group_label, name, qty, checked, prices, sort_order, source)
                 VALUES (?,?,?,?,?,?,?, 'auto')",
                [$period, $group, $name, $qty, $old ? (int) $old['checked'] : 0, $old ? (string) $old['prices'] : '[]', ++$sort]
            );
            $created++;
        }

        // Despensa agotada ('re') o bajo mínimo ('am') que el plan no cubrió.
        $low = q_all("SELECT * FROM pantry_items WHERE status IN ('re', 'am') ORDER BY FIELD(status,'re','am'), category, name");
        foreach ($low as $p) {
            $bk = shop_basename_key((string) $p['name']);
            if (isset($seen[$bk]) || isset($manual[$bk])) {
                continue;
            }
            $base = 1.0;
            if ($p['min_qty'] !== null && $p['qty_value'] !== null && (float) $p['qty_value'] < (float) $p['min_qty']) {
                $base = (float) $p['min_qty'] - (float) $p['qty_value'];
            }
            $qty = max(1, (int) ceil($base * $mult));
            $group = $p['status'] === 're' ? 'Urgentes' : (SHOP_GROUP_LABEL[$p['category']] ?? 'Otros');
            $old = $prev[$bk] ?? null;
            q_exec(
                "INSERT INTO shopping_list_items (period, group_label, name, qty, checked, prices, sort_order, source)
                 VALUES (?,?,?,?,?,?,?, 'auto')",
                [$period, $group, $p['name'], $qty, $old ? (int) $old['checked'] : 0, $old ? (string) $old['prices'] : '[]', ++$sort]
            );
            $created++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $rows = q_all('SELECT * FROM shopping_list_items ORDER BY sort_order');
    $totals = shopping_list_total($period, $rows);
    return [
        'period' => $period,
        'total' => $totals['amount'],
        'total_note' => $totals['note'],
        'generated' => $created,
        'source' => $usedIa ? 'ia' : 'tabla',
        'items' => array_map('shopping_item_out', $rows),
    ];
}

// ---------------------------------------------------------------------------
//  Total (suma real, sin inventar precios)
// ---------------------------------------------------------------------------

/** Texto del mejor precio de un ítem (el marcado `best`, o el primero). null si no hay. */
function shop_best_price_raw($prices): ?string
{
    $list = is_string($prices) ? (json_decode($prices, true) ?: []) : (is_array($prices) ? $prices : []);
    if (!$list) {
        return null;
    }
    $best = null;
    foreach ($list as $p) {
        if (is_array($p) && !empty($p['best'])) {
            $best = $p;
            break;
        }
    }
    $best = $best ?? $list[0];
    return (is_array($best) && isset($best['price'])) ? (string) $best['price'] : null;
}

/**
 * Descompone un precio en monto y —si es "precio de lote"— el tamaño del lote.
 *   "$1.20"           -> ['amount' => 1.2, 'pack' => null]   precio total: × qty
 *   "$1.00/pack 8 un" -> ['amount' => 1.0, 'pack' => 8.0]    lote: × ceil(qty / 8)
 *   "$1.00/8un"       -> ['amount' => 1.0, 'pack' => 8.0]
 *   "$3.80/lb"        -> null   tarifa a granel sin conteo: no se puede multiplicar
 *   "$0.30/un"        -> null
 *
 * @return array{amount: float, pack: ?float}|null
 */
function shop_price_parse(?string $raw): ?array
{
    $raw = trim((string) $raw);
    if ($raw === '' || !preg_match('/(\d+(?:[.,]\d+)?)/', $raw, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $amount = (float) str_replace(',', '.', $m[1][0]);
    if ($amount <= 0) {
        return null;
    }
    $rest = substr($raw, (int) $m[1][1] + strlen((string) $m[1][0]));
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $rest, $pm)) {
        $pack = (float) str_replace(',', '.', $pm[1]);
        if ($pack >= 1.0) {
            return ['amount' => $amount, 'pack' => $pack];
        }
    }
    // Queda "/" con unidad pero sin número ("$3.80/lb"): tarifa, no multiplicable.
    if (str_contains($raw, '/')) {
        return null;
    }
    return ['amount' => $amount, 'pack' => null];
}

/**
 * Costo de una fila de la lista: precio × cantidad, o precio × paquetes
 * (ceil(qty / lote)) si el precio es "por paquete". null si el precio no se
 * puede multiplicar (tarifa a granel sin conteo).
 */
function shop_line_cost($prices, int $qty): ?float
{
    $p = shop_price_parse(shop_best_price_raw($prices));
    if ($p === null) {
        return null;
    }
    $qty = max(1, $qty);
    if ($p['pack'] !== null && $p['pack'] > 1.0) {
        return round($p['amount'] * (float) ceil($qty / $p['pack']), 2);
    }
    return round($p['amount'] * $qty, 2);
}

/**
 * Total del período: suma del costo de cada línea (shop_line_cost = mejor precio
 * × cantidad, o × paquetes si el precio es "por lote"). Los ítems sin precio
 * propio se completan con precios REALES cargados para ese producto
 * (shop_ref_prices_for_row); lo que no tiene ni uno ni otro cae en "sin precio".
 * @return array{amount: ?string, sum: float, priced: int, unpriced: int, note: ?string}
 */
function shopping_list_total(string $period, ?array $rows = null): array
{
    $rows = $rows ?? q_all('SELECT name, group_label, qty, prices, checked FROM shopping_list_items');
    $sum = 0.0;
    $priced = 0;
    $unpriced = 0;
    foreach ($rows as $r) {
        $qty = (int) ($r['qty'] ?? 1);
        $cost = shop_line_cost($r['prices'] ?? null, $qty);
        if ($cost === null) {
            $cost = shop_line_cost(shop_ref_prices_for_row($r), $qty);
        }
        if ($cost === null) {
            $unpriced++;
            continue;
        }
        $sum += $cost;
        $priced++;
    }
    $note = null;
    if ($unpriced > 0) {
        $note = $priced > 0 ? "{$unpriced} sin precio" : 'sin precios cargados';
    }
    return [
        'amount' => $priced > 0 ? '$' . number_format($sum, 2) : null,
        'sum' => round($sum, 2),
        'priced' => $priced,
        'unpriced' => $unpriced,
        'note' => $note,
    ];
}
