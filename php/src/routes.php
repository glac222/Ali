<?php
declare(strict_types=1);

require __DIR__ . '/chat.php';

/**
 * Enrutador de la API. $path ya viene sin el prefijo /api (ej. "pantry/3").
 */
function handle_api(string $method, string $path): void
{
    $path = trim($path, '/');
    $seg = $path === '' ? [] : explode('/', $path);
    $r = fn(int $i) => $seg[$i] ?? null;

    // OPTIONS -> preflight CORS
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        json_out(null, 204);
    }

    switch ($r(0)) {
        case 'health':
            json_out(['ok' => true]);

        case 'admin':
            route_admin($method, $r(1));

        case 'pantry':
            route_pantry($method, $r(1));

        case 'recipes':
            route_recipes($method, $r(1));

        case 'plan':
            route_plan($method, $r(1));

        case 'shopping-list':
            route_shopping_list($method, $r(1));

        case 'discoveries':
            route_discoveries($method, $r(1), $r(2));

        case 'nutrition':
            route_nutrition($method, $r(1));

        case 'memory':
            route_memory($method, $r(1));

        case 'meals':
            route_meals($method, $r(1));

        case 'prefs':
            route_prefs($method, $r(1));

        case 'chat':
            route_chat($method, $r(1));

        default:
            fail('Ruta no encontrada: /api/' . $path, 404);
    }
}

// --- casting helpers -----------------------------------------------------
function as_bool($v): bool
{
    return (bool) $v;
}

function pantry_row(int $id): ?array
{
    return q_one('SELECT * FROM pantry_items WHERE id = ?', [$id]);
}

// --- pantry ------------------------------------------------------------
function route_pantry(string $method, ?string $id): void
{
    if ($method === 'GET' && $id === null) {
        json_out(q_all('SELECT * FROM pantry_items ORDER BY category, id'));
    }

    if ($method === 'POST' && $id === null) {
        $b = body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            fail('name es requerido');
        }
        q_exec(
            'INSERT INTO pantry_items (name, quantity, category, expires_label, status, notes) VALUES (?,?,?,?,?,?)',
            [
                $name,
                (string) ($b['quantity'] ?? ''),
                (string) ($b['category'] ?? 'granos'),
                (string) ($b['expires_label'] ?? ''),
                (string) ($b['status'] ?? 'ok'),
                (string) ($b['notes'] ?? ''),
            ]
        );
        json_out(pantry_row(last_id()), 201);
    }

    if ($method === 'PUT' && $id !== null) {
        $existing = pantry_row((int) $id);
        if (!$existing) {
            fail('no encontrado', 404);
        }
        $m = array_merge($existing, body());
        q_exec(
            "UPDATE pantry_items SET name=?, quantity=?, category=?, expires_label=?, status=?, notes=?, updated_at=NOW() WHERE id=?",
            [$m['name'], $m['quantity'], $m['category'], $m['expires_label'], $m['status'], $m['notes'], (int) $id]
        );
        json_out(pantry_row((int) $id));
    }

    if ($method === 'DELETE' && $id !== null) {
        q_exec('DELETE FROM pantry_items WHERE id = ?', [(int) $id]);
        no_content();
    }

    fail('Método no permitido', 405);
}

// --- recipes ---------------------------------------------------------

/** Condimentos que se asumen siempre en casa: no cuentan como "falta". */
const RECIPE_STAPLES = ['sal', 'aceite', 'azucar', 'agua', 'limon', 'lima', 'ajo', 'pimienta', 'vinagre', 'comino', 'achiote', 'sazon'];

/** minúsculas + sin tildes/ñ, para comparar nombres sin que el acento estorbe. */
function ali_deburr(string $s): string
{
    return strtr(mb_strtolower(trim($s)), [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
}

/** Tokens (≥3 letras, sin tildes) de todos los nombres de la despensa. Cacheado por request. */
function pantry_name_tokens(): array
{
    static $toks = null;
    if ($toks !== null) {
        return $toks;
    }
    $toks = [];
    // status 're' = agotado: no cuenta como disponible para cocinar.
    foreach (q_all("SELECT name FROM pantry_items WHERE status <> 're'") as $r) {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', ali_deburr((string) $r['name'])) ?: [] as $w) {
            if (mb_strlen($w) >= 3) {
                $toks[$w] = true;
            }
        }
    }
    return $toks;
}

/**
 * ¿Falta este ingrediente en la despensa? Compara la "cabeza" del texto
 * ("Pollo — 2 filetes" -> "Pollo") contra los nombres de la despensa.
 */
function recipe_ingredient_missing(string $text): bool
{
    $head = ali_deburr(preg_split('/[—:(\-]/u', $text)[0] ?? $text);
    $words = array_values(array_filter(
        preg_split('/[^\p{L}\p{N}]+/u', $head) ?: [],
        fn($w) => mb_strlen($w) >= 3 && !in_array($w, ALI_STOPWORDS, true)
    ));
    if (!$words) {
        return false;
    }
    $pantry = pantry_name_tokens();
    foreach ($words as $w) {
        if (in_array($w, RECIPE_STAPLES, true)) {
            return false;
        }
        if (isset($pantry[$w]) || isset($pantry[rtrim($w, 's')]) || isset($pantry[$w . 's'])) {
            return false;
        }
    }
    return true;
}

function full_recipe(array $row): array
{
    $ings = q_all('SELECT `text`, missing FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort_order', [$row['id']]);
    $steps = q_all('SELECT `text` FROM recipe_steps WHERE recipe_id = ? ORDER BY step_number', [$row['id']]);
    $row['tags'] = json_decode((string) $row['tags'], true) ?: [];
    // `missing` se calcula contra la despensa actual (la columna guardada solo
    // sirve de override manual: si está en 1, se respeta).
    $row['ingredients'] = array_map(
        fn($i) => ['text' => $i['text'], 'missing' => as_bool($i['missing']) || recipe_ingredient_missing((string) $i['text'])],
        $ings
    );
    $row['steps'] = array_map(fn($s) => $s['text'], $steps);
    return $row;
}

function route_recipes(string $method, ?string $key): void
{
    if ($method === 'GET' && $key === null) {
        $rows = q_all('SELECT * FROM recipes ORDER BY id');
        json_out(array_map('full_recipe', $rows));
    }

    if ($method === 'GET' && $key !== null) {
        $row = q_one('SELECT * FROM recipes WHERE slug = ? OR id = ?', [$key, ctype_digit($key) ? (int) $key : 0]);
        if (!$row) {
            fail('no encontrado', 404);
        }
        json_out(full_recipe($row));
    }

    if ($method === 'POST' && $key === null) {
        $b = body();
        $slug = trim((string) ($b['slug'] ?? ''));
        $title = trim((string) ($b['title'] ?? ''));
        if ($slug === '' || $title === '') {
            fail('slug y title son requeridos');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q_exec(
                "INSERT INTO recipes (slug, title, description, tags, time_label, gradient, source) VALUES (?,?,?,?,?,?, 'custom')",
                [
                    $slug,
                    $title,
                    (string) ($b['description'] ?? ''),
                    json_encode($b['tags'] ?? [], JSON_UNESCAPED_UNICODE),
                    (string) ($b['time_label'] ?? ''),
                    (string) ($b['gradient'] ?? ''),
                ]
            );
            $id = last_id();
            foreach (array_values((array) ($b['ingredients'] ?? [])) as $i => $ing) {
                $text = is_array($ing) ? (string) ($ing['text'] ?? '') : (string) $ing;
                $missing = is_array($ing) && !empty($ing['missing']) ? 1 : 0;
                q_exec('INSERT INTO recipe_ingredients (recipe_id, `text`, missing, sort_order) VALUES (?,?,?,?)', [$id, $text, $missing, $i]);
            }
            foreach (array_values((array) ($b['steps'] ?? [])) as $i => $s) {
                q_exec('INSERT INTO recipe_steps (recipe_id, step_number, `text`) VALUES (?,?,?)', [$id, $i + 1, (string) $s]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_out(full_recipe(q_one('SELECT * FROM recipes WHERE id = ?', [$id])), 201);
    }

    if ($method === 'DELETE' && $key !== null) {
        q_exec('DELETE FROM recipes WHERE id = ?', [(int) $key]);
        no_content();
    }

    fail('Método no permitido', 405);
}

// --- plan ----------------------------------------------------------
function route_plan(string $method, ?string $id): void
{
    if ($method === 'GET' && $id === null) {
        $rows = q_all('SELECT * FROM meal_plan ORDER BY sort_order');
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['weekday']][] = $r;
        }
        json_out($byDay ?: (object) []);
    }

    if ($method === 'GET' && $id !== null) {
        json_out(q_all('SELECT * FROM meal_plan WHERE weekday = ? ORDER BY sort_order', [$id]));
    }

    if ($method === 'POST' && $id === null) {
        $b = body();
        $weekday = trim((string) ($b['weekday'] ?? ''));
        $mealType = trim((string) ($b['meal_type'] ?? ''));
        $title = trim((string) ($b['title'] ?? ''));
        if ($weekday === '' || $mealType === '' || $title === '') {
            fail('weekday, meal_type y title son requeridos');
        }
        $max = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM meal_plan')['m']);
        q_exec(
            'INSERT INTO meal_plan (weekday, meal_type, title, detail, optional, sort_order) VALUES (?,?,?,?,?,?)',
            [$weekday, $mealType, $title, (string) ($b['detail'] ?? ''), !empty($b['optional']) ? 1 : 0, $max + 1]
        );
        json_out(q_one('SELECT * FROM meal_plan WHERE id = ?', [last_id()]), 201);
    }

    if ($method === 'PUT' && $id !== null) {
        $existing = q_one('SELECT * FROM meal_plan WHERE id = ?', [(int) $id]);
        if (!$existing) {
            fail('no encontrado', 404);
        }
        $m = array_merge($existing, body());
        q_exec(
            'UPDATE meal_plan SET weekday=?, meal_type=?, title=?, detail=?, optional=? WHERE id=?',
            [$m['weekday'], $m['meal_type'], $m['title'], $m['detail'], !empty($m['optional']) ? 1 : 0, (int) $id]
        );
        json_out(q_one('SELECT * FROM meal_plan WHERE id = ?', [(int) $id]));
    }

    if ($method === 'DELETE' && $id !== null) {
        q_exec('DELETE FROM meal_plan WHERE id = ?', [(int) $id]);
        no_content();
    }

    fail('Método no permitido', 405);
}

// --- shopping-list --------------------------------------------------
function shopping_item_out(array $row): array
{
    $row['prices'] = json_decode((string) $row['prices'], true) ?: [];
    $row['checked'] = as_bool($row['checked']);
    return $row;
}

function route_shopping_list(string $method, ?string $id): void
{
    if ($method === 'GET' && $id === null) {
        $period = (string) ($_GET['period'] ?? '1 semana');
        $rows = q_all('SELECT * FROM shopping_list_items WHERE period = ? ORDER BY sort_order', [$period]);
        $total = q_one('SELECT amount FROM shopping_list_totals WHERE period = ?', [$period]);
        json_out([
            'period' => $period,
            'total' => $total ? $total['amount'] : null,
            'items' => array_map('shopping_item_out', $rows),
        ]);
    }

    if ($method === 'GET' && $id === 'totals') {
        $rows = q_all('SELECT * FROM shopping_list_totals');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['period']] = $r['amount'];
        }
        json_out($out ?: (object) []);
    }

    // Genera la lista de forma determinista desde la despensa: cada producto
    // agotado (re) o bajo mínimo (am) entra a la lista, con la cantidad escalada
    // según el período. Solo toca los ítems source='auto'; respeta lo manual.
    if ($method === 'POST' && $id === 'generate') {
        json_out(shopping_list_generate((string) (body()['period'] ?? '1 semana')));
    }

    if ($method === 'POST' && $id === null) {
        $b = body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            fail('name es requerido');
        }
        $period = (string) ($b['period'] ?? '1 semana');
        $max = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items WHERE period = ?', [$period])['m']);
        q_exec(
            'INSERT INTO shopping_list_items (period, group_label, name, qty, prices, sort_order, source) VALUES (?,?,?,?,?,?,?)',
            [
                $period,
                (string) ($b['group_label'] ?? ''),
                $name,
                (int) ($b['qty'] ?? 1),
                json_encode($b['prices'] ?? [], JSON_UNESCAPED_UNICODE),
                $max + 1,
                in_array($b['source'] ?? '', ['manual', 'scan', 'auto', 'ia'], true) ? $b['source'] : 'manual',
            ]
        );
        json_out(shopping_item_out(q_one('SELECT * FROM shopping_list_items WHERE id = ?', [last_id()])), 201);
    }

    if ($method === 'PUT' && $id !== null && ctype_digit($id)) {
        $existing = q_one('SELECT * FROM shopping_list_items WHERE id = ?', [(int) $id]);
        if (!$existing) {
            fail('no encontrado', 404);
        }
        $m = array_merge($existing, body());
        $prices = $m['prices'];
        $pricesStr = is_string($prices) ? $prices : json_encode($prices ?? [], JSON_UNESCAPED_UNICODE);
        q_exec(
            'UPDATE shopping_list_items SET group_label=?, name=?, qty=?, checked=?, prices=? WHERE id=?',
            [$m['group_label'], $m['name'], (int) $m['qty'], !empty($m['checked']) ? 1 : 0, $pricesStr, (int) $id]
        );
        json_out(shopping_item_out(q_one('SELECT * FROM shopping_list_items WHERE id = ?', [(int) $id])));
    }

    if ($method === 'DELETE' && $id !== null && ctype_digit($id)) {
        q_exec('DELETE FROM shopping_list_items WHERE id = ?', [(int) $id]);
        no_content();
    }

    fail('Método no permitido', 405);
}

/**
 * Genera la lista de compras de un período de forma DETERMINISTA (sin IA):
 * cada producto de la despensa agotado ('re') o bajo mínimo ('am') se agrega
 * a la lista con la cantidad escalada por el factor del período. Reconstruye
 * solo los ítems `source='auto'`; nunca toca lo que se puso a mano o por escáner,
 * y conserva el estado `checked` / `prices` de la generación anterior.
 */
function shopping_list_generate(string $period): array
{
    $factor = [
        '3 días' => 0.5, '1 semana' => 1.0, '2 semanas' => 2.0,
        '3 semanas' => 3.0, '1 mes' => 4.0,
    ];
    if (!isset($factor[$period])) {
        fail('period inválido: ' . $period);
    }
    $mult = $factor[$period];

    $groupLabel = [
        'carnes' => 'Carnes', 'lacteos' => 'Lácteos y pan', 'granos' => 'Granos',
        'vegetales' => 'Vegetales', 'latas' => 'Latas y despensa',
    ];

    // Lo que la despensa necesita reponer.
    $low = q_all("SELECT * FROM pantry_items WHERE status IN ('re', 'am') ORDER BY FIELD(status,'re','am'), category, name");

    // Nombres ya presentes a mano / por escáner: no duplicar.
    $manual = [];
    foreach (q_all("SELECT name FROM shopping_list_items WHERE period = ? AND source <> 'auto'", [$period]) as $r) {
        $manual[mb_strtolower($r['name'])] = true;
    }
    // Estado previo de los 'auto' para conservar lo ya marcado / sus precios.
    $prev = [];
    foreach (q_all("SELECT name, checked, prices FROM shopping_list_items WHERE period = ? AND source = 'auto'", [$period]) as $r) {
        $prev[mb_strtolower($r['name'])] = $r;
    }

    q_exec("DELETE FROM shopping_list_items WHERE period = ? AND source = 'auto'", [$period]);

    $sort = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM shopping_list_items WHERE period = ?', [$period])['m']);
    $created = 0;
    foreach ($low as $p) {
        $key = mb_strtolower((string) $p['name']);
        if (isset($manual[$key])) {
            continue;
        }
        // Cantidad base: la brecha hasta el mínimo si la conocemos, si no 1.
        $base = 1.0;
        if ($p['min_qty'] !== null && $p['qty_value'] !== null && (float) $p['qty_value'] < (float) $p['min_qty']) {
            $base = (float) $p['min_qty'] - (float) $p['qty_value'];
        }
        $qty = max(1, (int) ceil($base * $mult));
        $old = $prev[$key] ?? null;
        q_exec(
            "INSERT INTO shopping_list_items (period, group_label, name, qty, checked, prices, sort_order, source)
             VALUES (?,?,?,?,?,?,?, 'auto')",
            [
                $period,
                $groupLabel[$p['category']] ?? 'Otros',
                $p['name'],
                $qty,
                $old ? (int) $old['checked'] : 0,
                $old ? (string) $old['prices'] : '[]',
                ++$sort,
            ]
        );
        $created++;
    }

    $rows = q_all('SELECT * FROM shopping_list_items WHERE period = ? ORDER BY sort_order', [$period]);
    $total = q_one('SELECT amount FROM shopping_list_totals WHERE period = ?', [$period]);
    return [
        'period' => $period,
        'total' => $total ? $total['amount'] : null,
        'generated' => $created,
        'items' => array_map('shopping_item_out', $rows),
    ];
}

// --- discoveries ---------------------------------------------------
function route_discoveries(string $method, ?string $id, ?string $sub): void
{
    if ($method === 'GET' && $id === null) {
        json_out(q_all('SELECT * FROM discoveries ORDER BY id'));
    }

    if ($method === 'POST' && $id === null) {
        $b = body();
        $title = trim((string) ($b['title'] ?? ''));
        if ($title === '') {
            fail('title es requerido');
        }
        q_exec(
            'INSERT INTO discoveries (title, source, link, meta, gradient) VALUES (?,?,?,?,?)',
            [$title, (string) ($b['source'] ?? ''), (string) ($b['link'] ?? ''), (string) ($b['meta'] ?? ''), (string) ($b['gradient'] ?? '')]
        );
        json_out(q_one('SELECT * FROM discoveries WHERE id = ?', [last_id()]), 201);
    }

    if ($method === 'PUT' && $id !== null && $sub === 'rating') {
        $rating = body()['rating'] ?? null;
        if (!is_numeric($rating) || $rating < 0 || $rating > 5) {
            fail('rating debe ser 0-5');
        }
        q_exec('UPDATE discoveries SET rating = ? WHERE id = ?', [(int) $rating, (int) $id]);
        json_out(q_one('SELECT * FROM discoveries WHERE id = ?', [(int) $id]));
    }

    if ($method === 'DELETE' && $id !== null) {
        q_exec('DELETE FROM discoveries WHERE id = ?', [(int) $id]);
        no_content();
    }

    fail('Método no permitido', 405);
}

// --- nutrition ---------------------------------------------------
const NUTRITION_DEFAULTS = [
    'calories' => 0, 'calories_target' => 2800, 'protein' => 0, 'protein_target' => 140,
    'carbs' => 0, 'carbs_target' => 300, 'fat' => 0, 'fat_target' => 80,
];

function route_nutrition(string $method, ?string $sub): void
{
    if ($sub !== 'today') {
        fail('Ruta no encontrada', 404);
    }
    $today = date('Y-m-d');

    if ($method === 'GET') {
        $row = q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]);
        if (!$row) {
            q_exec(
                'INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
                 VALUES (?,0,2800,0,140,0,300,0,80)',
                [$today]
            );
            $row = q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]);
        }
        json_out($row);
    }

    if ($method === 'PUT') {
        $existing = q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]) ?? NUTRITION_DEFAULTS;
        $m = array_merge($existing, body());
        q_exec(
            'INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
             VALUES (:d,:cal,:calt,:pro,:prot,:car,:cart,:fat,:fatt)
             ON DUPLICATE KEY UPDATE calories=VALUES(calories), calories_target=VALUES(calories_target),
               protein=VALUES(protein), protein_target=VALUES(protein_target), carbs=VALUES(carbs), carbs_target=VALUES(carbs_target),
               fat=VALUES(fat), fat_target=VALUES(fat_target)',
            [
                'd' => $today,
                'cal' => (int) $m['calories'], 'calt' => (int) $m['calories_target'],
                'pro' => (int) $m['protein'], 'prot' => (int) $m['protein_target'],
                'car' => (int) $m['carbs'], 'cart' => (int) $m['carbs_target'],
                'fat' => (int) $m['fat'], 'fatt' => (int) $m['fat_target'],
            ]
        );
        json_out(q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]));
    }

    fail('Método no permitido', 405);
}

// --- memory ----------------------------------------------------
function route_memory(string $method, ?string $id): void
{
    if ($method === 'GET' && $id === null) {
        json_out(q_all('SELECT * FROM meal_memory ORDER BY id DESC LIMIT 50'));
    }
    if ($method === 'DELETE' && $id !== null) {
        q_exec('DELETE FROM meal_memory WHERE id = ?', [(int) $id]);
        no_content();
    }
    fail('Método no permitido', 405);
}

// --- meals: comidas registradas (distinto de meal_plan) -----------
function route_meals(string $method, ?string $id): void
{
    if ($method === 'GET' && $id === null) {
        $date = (string) ($_GET['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $rows = q_all('SELECT * FROM meals WHERE log_date = ? ORDER BY id', [$date]);
        } else {
            $rows = q_all('SELECT * FROM meals ORDER BY log_date DESC, id DESC LIMIT 60');
        }
        foreach ($rows as &$m) {
            $m['items'] = q_all('SELECT name, qty_text, deducted FROM meal_items WHERE meal_id = ? ORDER BY id', [(int) $m['id']]);
        }
        json_out($rows);
    }

    if ($method === 'GET' && $id === 'today') {
        json_out(q_all('SELECT * FROM meals WHERE log_date = CURDATE() ORDER BY id'));
    }

    if ($method === 'DELETE' && $id !== null && ctype_digit($id)) {
        q_exec('DELETE FROM meals WHERE id = ?', [(int) $id]);
        no_content();
    }

    fail('Método no permitido', 405);
}

// --- prefs: perfil del hogar y preferencias ----------------------
function route_prefs(string $method, ?string $key): void
{
    if ($method === 'GET' && $key === null) {
        json_out(pref_all());
    }

    if (($method === 'PUT' || $method === 'POST') && $key === null) {
        $b = body();
        $k = strtolower(trim((string) ($b['key'] ?? $b['clave'] ?? '')));
        $k = preg_replace('/[^a-z0-9_]+/', '_', $k);
        $v = (string) ($b['value'] ?? $b['valor'] ?? '');
        if ($k === '') {
            fail('key es requerido');
        }
        pref_set($k, $v);
        json_out(['ok' => true, 'key' => $k, 'value' => $v]);
    }

    if ($method === 'DELETE' && $key !== null) {
        q_exec('DELETE FROM assistant_prefs WHERE pref_key = ?', [$key]);
        no_content();
    }

    fail('Método no permitido', 405);
}

// --- chat: el agente Ali -----------------------------------------
function route_chat(string $method, ?string $sub): void
{
    if ($method === 'GET' && $sub === 'status') {
        json_out(['configured' => chat_is_configured()]);
    }

    if ($method === 'GET' && $sub === 'history') {
        json_out(q_all('SELECT * FROM chat_messages ORDER BY id ASC LIMIT 200'));
    }

    if ($method === 'GET' && $sub === 'events') {
        json_out(q_all('SELECT id, tool, summary, created_at FROM assistant_events ORDER BY id DESC LIMIT 50'));
    }

    if ($method === 'POST' && $sub === null) {
        $b = body();
        $message = trim((string) ($b['message'] ?? ''));
        if ($message === '') {
            fail('message es requerido');
        }
        $history = is_array($b['history'] ?? null) ? $b['history'] : [];

        q_exec('INSERT INTO chat_messages (role, content) VALUES (?,?)', ['user', $message]);

        try {
            $result = assistant_reply($message, $history);
            $reply = trim((string) $result['reply']);

            q_exec('INSERT INTO chat_messages (role, content) VALUES (?,?)', ['assistant', $reply]);

            json_out([
                'reply' => $reply,
                'simulated' => (bool) $result['simulated'],
                'actions' => $result['actions'] ?? [],
                'changed' => $result['changed'] ?? [],
            ]);
        } catch (Throwable $e) {
            error_log('[ali] chat: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            q_exec('INSERT INTO chat_messages (role, content) VALUES (?,?)', ['assistant', '[error de conexión]']);
            json_out(['error' => 'Ali no pudo responder: ' . $e->getMessage() . '. Revisa la API key de DeepSeek en el servidor.'], 502);
        }
    }

    fail('Método no permitido', 405);
}

// --- admin: migraciones y seed via HTTP con token -----------------
function route_admin(string $method, ?string $action): void
{
    $token = env('ADMIN_TOKEN', '');
    $given = (string) ($_GET['token'] ?? '');
    if ($token === null || $token === '' || !hash_equals($token, $given)) {
        fail('token inválido', 403);
    }

    if ($action === 'migrate') {
        $sql = file_get_contents(APP_ROOT . '/db/schema.sql');
        db()->exec($sql);
        json_out(['ok' => true, 'action' => 'migrate', 'message' => 'Esquema aplicado (idempotente).']);
    }

    if ($action === 'seed') {
        $force = !empty($_GET['force']);
        json_out(run_seed($force));
    }

    if ($action === 'recalc') {
        // Adapta una base YA poblada al arnés de Ali, sin borrar nada:
        //  - rellena qty_value/qty_unit/min_qty de la despensa desde el texto
        //  - siembra el perfil del hogar (assistant_prefs) por UPSERT
        $n = pantry_backfill_all();
        $data = require APP_ROOT . '/db/seed_data.php';
        $prefs = 0;
        foreach (($data['prefs'] ?? []) as $k => $v) {
            q_exec(
                'INSERT INTO assistant_prefs (pref_key, pref_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()',
                [$k, $v]
            );
            $prefs++;
        }
        json_out([
            'ok' => true, 'action' => 'recalc', 'productos' => $n, 'preferencias' => $prefs,
            'message' => 'Cantidades recalculadas y perfil del hogar sembrado (sin borrar datos).',
        ]);
    }

    fail('Acción admin desconocida. Usa /api/admin/migrate | seed | recalc', 404);
}

function run_seed(bool $force): array
{
    try {
        $count = (int) (q_one('SELECT COUNT(*) AS n FROM pantry_items')['n'] ?? 0);
    } catch (Throwable $e) {
        fail('Primero corre /api/admin/migrate?token=... para crear las tablas.', 409);
    }
    if ($count > 0 && !$force) {
        return ['ok' => true, 'skipped' => true, 'message' => 'La base ya tiene datos. Usa ?force=1 para reiniciar.'];
    }

    $data = require APP_ROOT . '/db/seed_data.php';
    $pdo = db();
    // Nota: TRUNCATE hace commit implicito en MariaDB, asi que usamos DELETE
    // para que todo el seed viva dentro de una sola transaccion.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->beginTransaction();
    try {
        foreach ([
            'meal_items', 'meals', 'assistant_events',
            'pantry_items', 'recipe_ingredients', 'recipe_steps', 'recipes',
            'meal_plan', 'shopping_list_items', 'shopping_list_totals',
            'discoveries', 'nutrition_log', 'meal_memory', 'chat_messages',
        ] as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }

        foreach ($data['pantry'] as $p) {
            q_exec(
                'INSERT INTO pantry_items (name, quantity, category, expires_label, status, notes) VALUES (?,?,?,?,?,?)',
                $p
            );
        }
        // Rellena cantidad numérica + unidad (carnes -> porciones) desde el texto.
        pantry_backfill_all();

        foreach (($data['prefs'] ?? []) as $k => $v) {
            q_exec(
                'INSERT INTO assistant_prefs (pref_key, pref_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()',
                [$k, $v]
            );
        }

        foreach ($data['recipes'] as $r) {
            q_exec(
                "INSERT INTO recipes (slug, title, description, tags, time_label, gradient, source) VALUES (?,?,?,?,?,?, 'mio')",
                [$r['slug'], $r['title'], $r['description'], json_encode($r['tags'], JSON_UNESCAPED_UNICODE), $r['time_label'], $r['gradient']]
            );
            $rid = last_id();
            foreach (array_values($r['ings']) as $i => $text) {
                q_exec('INSERT INTO recipe_ingredients (recipe_id, `text`, missing, sort_order) VALUES (?,?,0,?)', [$rid, $text, $i]);
            }
            foreach (array_values($r['steps']) as $i => $text) {
                q_exec('INSERT INTO recipe_steps (recipe_id, step_number, `text`) VALUES (?,?,?)', [$rid, $i + 1, $text]);
            }
        }

        foreach (array_values($data['plan']) as $i => $row) {
            q_exec(
                'INSERT INTO meal_plan (weekday, meal_type, title, detail, optional, sort_order) VALUES (?,?,?,?,?,?)',
                [$row[0], $row[1], $row[2], $row[3], $row[4], $i]
            );
        }

        foreach ($data['totals'] as $period => $amount) {
            q_exec('INSERT INTO shopping_list_totals (period, amount) VALUES (?,?)', [$period, $amount]);
        }

        foreach (array_values($data['shopping_items']) as $i => $row) {
            q_exec(
                'INSERT INTO shopping_list_items (period, group_label, name, qty, checked, prices, sort_order) VALUES (?,?,?,?,?,?,?)',
                ['1 semana', $row[0], $row[1], $row[2], $row[3], json_encode($row[4], JSON_UNESCAPED_UNICODE), $i]
            );
        }

        foreach ($data['discoveries'] as $d) {
            q_exec(
                'INSERT INTO discoveries (title, source, link, meta, rating, gradient) VALUES (?,?,?,?,?,?)',
                $d
            );
        }

        $n = $data['nutrition_today'];
        q_exec(
            'INSERT INTO nutrition_log (log_date, calories, calories_target, protein, protein_target, carbs, carbs_target, fat, fat_target)
             VALUES (?,?,?,?,?,?,?,?,?)',
            array_merge([date('Y-m-d')], $n)
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return ['ok' => true, 'seeded' => true, 'message' => 'Base poblada con los datos de Gus.'];
}
