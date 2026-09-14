<?php
declare(strict_types=1);

/**
 * ARNÉS DE ALI — el asistente de despensa, compras y comidas.
 *
 * No es un chatbot: es un agente con herramientas que LEEN y MODIFICAN la
 * plataforma (despensa, comidas, plan, lista, preferencias). El modelo (DeepSeek,
 * function-calling estilo OpenAI) propone llamadas a herramientas; este archivo
 * las ejecuta contra MariaDB, le devuelve el resultado y repite hasta que Ali
 * da una respuesta final en texto.
 *
 * Piezas:
 *   - Cantidades: qty_parse / qty_convert / qty_format  (restar con precisión)
 *   - Contexto:   assistant_context()  (solo lo útil para la conversación)
 *   - Prompt:     assistant_system_prompt()  (rol, tono, prioridades, acciones)
 *   - Herramientas: assistant_tools() + assistant_dispatch()
 *   - Bucle:      assistant_reply()
 */

const DEEPSEEK_URL   = 'https://api.deepseek.com/chat/completions';
const ALI_MODEL      = 'deepseek-chat';
const ALI_MAX_ROUNDS = 6;      // rondas de herramientas antes de forzar respuesta
const ALI_HTTP_TIMEOUT = 55;   // s por llamada (Hostinger corta a los 300 s)

// Desayuno por defecto entre semana (lunes a sábado). No es opcional.
const ALI_DESAYUNO_DEFECTO = 'Batido guineo+leche · 3 sándwiches queso crema+jamón+tortilla';
const ALI_DESAYUNO_DEFECTO_DETALLE = 'Rápido y contundente';

// -------------------------------------------------------------------------
//  Configuración
// -------------------------------------------------------------------------

const DEFAULT_LIST_PERIOD = '1 semana';

function chat_is_configured(): bool
{
    $key = env('DEEPSEEK_API_KEY', '');
    return $key !== null && strlen($key) > 10;
}

/** Gramos por "porción" estándar de proteína (editable como preferencia). */
function ali_gpp(): float
{
    return max(50.0, (float) pref_get('gramos_por_porcion', '150'));
}

/** Porciones que sirve Gus por comida cuando no especifica cantidad. */
function ali_porciones_comida(): float
{
    return max(1.0, (float) pref_get('porciones_por_comida', '3'));
}

// -------------------------------------------------------------------------
//  Preferencias / perfil del hogar
// -------------------------------------------------------------------------

function pref_all(): array
{
    $out = [];
    foreach (q_all('SELECT pref_key, pref_value FROM assistant_prefs ORDER BY pref_key') as $r) {
        $out[$r['pref_key']] = $r['pref_value'];
    }
    return $out;
}

function pref_get(string $key, ?string $default = null): ?string
{
    $row = q_one('SELECT pref_value FROM assistant_prefs WHERE pref_key = ?', [$key]);
    return $row ? (string) $row['pref_value'] : $default;
}

function pref_set(string $key, string $value): void
{
    q_exec(
        'INSERT INTO assistant_prefs (pref_key, pref_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()',
        [$key, $value]
    );
}

// -------------------------------------------------------------------------
//  Cantidades: parseo, conversión y formato
// -------------------------------------------------------------------------

const ALI_MASS = ['g' => 1.0, 'kg' => 1000.0, 'lb' => 453.6];
const ALI_VOL  = ['ml' => 1.0, 'l' => 1000.0, 'galon' => 3785.0];
const ALI_COUNT = ['un', 'lata', 'paq', 'funda', 'frasco', 'diente', 'taza', 'presa', 'filete'];

const ALI_UNIT_LABEL = [
    'porcion' => 'porciones', 'g' => 'g', 'kg' => 'kg', 'l' => 'L', 'ml' => 'ml',
    'galon' => 'galón', 'un' => 'un.', 'lata' => 'latas', 'paq' => 'paq.',
    'funda' => 'funda', 'frasco' => 'frasco', 'diente' => 'dientes',
    'taza' => 'tazas', 'presa' => 'presas', 'filete' => 'filetes',
];

/** Normaliza el token de unidad a una forma canónica. */
function qty_norm_unit(string $raw): string
{
    $u = trim(mb_strtolower($raw));
    $u = rtrim($u, '.');
    $map = [
        'kg' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kgs' => 'kg',
        'g' => 'g', 'gr' => 'g', 'grs' => 'g', 'gramo' => 'g', 'gramos' => 'g',
        'l' => 'l', 'lt' => 'l', 'lts' => 'l', 'litro' => 'l', 'litros' => 'l',
        'ml' => 'ml',
        'galon' => 'galon', 'galón' => 'galon', 'galones' => 'galon',
        'lb' => 'lb', 'libra' => 'lb', 'libras' => 'lb',
        'un' => 'un', 'u' => 'un', 'und' => 'un', 'unid' => 'un', 'unidad' => 'un',
        'unidades' => 'un', 'uni' => 'un',
        'lata' => 'lata', 'latas' => 'lata',
        'paq' => 'paq', 'paquete' => 'paq', 'paquetes' => 'paq', 'paqu' => 'paq',
        'funda' => 'funda', 'fundas' => 'funda', 'bolsa' => 'funda', 'bolsas' => 'funda',
        'frasco' => 'frasco', 'frascos' => 'frasco',
        'diente' => 'diente', 'dientes' => 'diente',
        'taza' => 'taza', 'tazas' => 'taza',
        'porcion' => 'porcion', 'porción' => 'porcion', 'porciones' => 'porcion',
        'presa' => 'presa', 'presas' => 'presa',
        'filete' => 'filete', 'filetes' => 'filete', 'bistec' => 'filete', 'bisteces' => 'filete',
    ];
    if (isset($map[$u])) {
        return $map[$u];
    }
    // "un." al inicio de "unidades", etc.
    foreach ($map as $k => $v) {
        if ($u !== '' && str_starts_with($k, $u)) {
            return $v;
        }
    }
    return '';
}

/**
 * "1.2 kg" -> ['value'=>1.2, 'unit'=>'kg'];  "media" -> ['value'=>0.5,'unit'=>''].
 * @return array{value: float|null, unit: string}
 */
function qty_parse(string $text): array
{
    $t = mb_strtolower(trim($text));
    if ($t === '') {
        return ['value' => null, 'unit' => ''];
    }
    $t = str_replace([',', '½', 'medio ', 'media ', ' y medio', ' y media'], ['.', '0.5', '0.5 ', '0.5 ', '.5', '.5'], $t);

    if (!preg_match('/(\d+(?:\.\d+)?)/', $t, $m)) {
        return ['value' => null, 'unit' => qty_norm_unit($t)];
    }
    $value = (float) $m[1];
    $rest = trim(substr($t, strpos($t, $m[1]) + strlen($m[1])));
    $rest = preg_replace('/[^\p{L}]+/u', ' ', $rest ?? '');
    $first = trim(explode(' ', trim((string) $rest))[0] ?? '');
    return ['value' => $value, 'unit' => qty_norm_unit($first)];
}

function qty_to_grams(?float $v, string $u): ?float
{
    return ($v !== null && isset(ALI_MASS[$u])) ? $v * ALI_MASS[$u] : null;
}

/**
 * Convierte $v $from a la unidad $to. Devuelve null si no hay conversión segura
 * (mejor no inventar que restar mal).
 */
function qty_convert(?float $v, string $from, string $to, string $category = ''): ?float
{
    if ($v === null || $from === '') {
        return null;
    }
    if ($from === $to) {
        return $v;
    }
    $g = qty_to_grams($v, $from);

    if ($to === 'porcion') {
        if ($g !== null) {
            return round($g / ali_gpp(), 2);
        }
        if (in_array($from, ['un', 'presa', 'filete', 'porcion'], true)) {
            return $v;
        }
        return null;
    }
    if (isset(ALI_MASS[$to]) && $g !== null) {
        return round($g / ALI_MASS[$to], 3);
    }
    $ml = isset(ALI_VOL[$from]) ? $v * ALI_VOL[$from] : ($from === 'taza' ? $v * 240.0 : null);
    if (isset(ALI_VOL[$to]) && $ml !== null) {
        return round($ml / ALI_VOL[$to], 3);
    }
    if (in_array($from, ALI_COUNT, true) && in_array($to, ALI_COUNT, true)) {
        return $v; // 1 presa ~ 1 unidad; aproximación explícita
    }
    return null;
}

function qty_num(float $v): string
{
    if (abs($v - round($v)) < 0.01) {
        return (string) (int) round($v);
    }
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

function qty_format(?float $v, string $unit): string
{
    if ($v === null) {
        return '';
    }
    if ($unit === '') {
        return qty_num($v);
    }
    return qty_num($v) . ' ' . (ALI_UNIT_LABEL[$unit] ?? $unit);
}

// -------------------------------------------------------------------------
//  Despensa: recálculo numérico y resolución de nombres
// -------------------------------------------------------------------------

function ali_category_min(string $category, string $unit): ?float
{
    return match ($category) {
        'carnes'    => $unit === 'porcion' ? 3.0 : null,
        'lacteos'   => match ($unit) { 'l', 'galon' => 1.0, 'un' => 6.0, default => null },
        'granos'    => match ($unit) { 'kg' => 0.5, 'un' => 2.0, default => null },
        'vegetales' => $unit === 'un' ? 1.0 : null,
        'latas'     => $unit === 'lata' ? 2.0 : null,
        default     => null,
    };
}

/**
 * Rellena qty_value / qty_unit / min_qty desde el texto `quantity`.
 * En `carnes` normaliza a "porciones" (1 porción ≈ ali_gpp() gramos) y reescribe
 * el texto visible. Idempotente.
 */
function pantry_recalc_row(array $row): void
{
    $p = qty_parse((string) $row['quantity']);
    $cat = (string) $row['category'];
    $val = $p['value'];
    $unit = $p['unit'];
    $newText = null;

    if ($cat === 'carnes') {
        $g = qty_to_grams($p['value'], $p['unit']);
        if ($g !== null) {
            $val = round($g / ali_gpp(), 1);
            $unit = 'porcion';
        } elseif (in_array($p['unit'], ['un', 'presa', 'filete', 'porcion'], true) || ($p['unit'] === '' && $p['value'] !== null)) {
            $val = $p['value'];
            $unit = 'porcion';
        }
        if ($unit === 'porcion' && $val !== null) {
            $newText = qty_format($val, 'porcion');
        }
    }

    $min = ali_category_min($cat, $unit);

    q_exec(
        'UPDATE pantry_items SET qty_value = ?, qty_unit = ?, min_qty = COALESCE(min_qty, ?), quantity = COALESCE(?, quantity) WHERE id = ?',
        [$val, $unit, $min, $newText, (int) $row['id']]
    );
}

function pantry_backfill_all(): int
{
    $rows = q_all('SELECT * FROM pantry_items');
    foreach ($rows as $r) {
        pantry_recalc_row($r);
    }
    return count($rows);
}

/** Palabras sin valor para emparejar nombres. */
const ALI_STOPWORDS = ['de', 'la', 'el', 'los', 'las', 'del', 'para', 'con', 'sin', 'en', 'un', 'una', 'y', 'o', 'al', 'lata', 'agua', 'aceite', 'tomate'];

/** Quita tildes/diéresis para comparar nombres sin depender de acentos. */
function ali_deaccent(string $s): string
{
    return strtr(mb_strtolower($s), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
}

/** Tokens significativos (sin tilde, >=4 letras, sin stopwords) de un nombre. */
function ali_name_tokens(string $s): array
{
    return array_values(array_filter(
        preg_split('/[^\p{L}\p{N}]+/u', ali_deaccent($s)) ?: [],
        fn($w) => mb_strlen($w) >= 4 && !in_array($w, ALI_STOPWORDS, true)
    ));
}

/**
 * Encuentra el producto de despensa que mejor encaja con $q (id o nombre).
 * @return array{match: array|null, candidates: array<int,array>, score: int}
 */
function pantry_resolve(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['match' => null, 'candidates' => [], 'score' => 0];
    }
    if (ctype_digit($q)) {
        $row = q_one('SELECT * FROM pantry_items WHERE id = ?', [(int) $q]);
        return ['match' => $row, 'candidates' => $row ? [$row] : [], 'score' => $row ? 100 : 0];
    }

    $all = q_all('SELECT * FROM pantry_items');
    $qd = ali_deaccent($q);
    $tokens = ali_name_tokens($q);

    $scored = [];
    foreach ($all as $r) {
        $nd = ali_deaccent((string) $r['name']);
        $score = 0;
        if ($nd === $qd) {
            $score = 100;
        } elseif (str_contains($nd, $qd) || str_contains($qd, $nd)) {
            $score = 50;
        }
        foreach ($tokens as $tk) {
            if (str_contains($nd, $tk)) {
                $score += 12;
            }
        }
        if ($score > 0) {
            $scored[] = ['row' => $r, 'score' => $score];
        }
    }
    if (!$scored) {
        return ['match' => null, 'candidates' => [], 'score' => 0];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = $scored[0]['score'];
    $best = array_values(array_filter($scored, fn($s) => $s['score'] === $top));

    return [
        'match' => count($best) === 1 ? $best[0]['row'] : null,
        'candidates' => array_map(fn($s) => $s['row'], array_slice($scored, 0, 5)),
        'score' => $top,
    ];
}

function pantry_public(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'nombre' => $row['name'],
        'cantidad' => $row['quantity'],
        'categoria' => $row['category'],
        'vence' => $row['expires_label'] !== '' ? $row['expires_label'] : null,
        'estado' => $row['status'],
        'notas' => $row['notes'] !== '' ? $row['notes'] : null,
    ];
}

/**
 * Resta $consumoText de un producto. No inventa: si no puede convertir la unidad,
 * lo deja anotado en `notes` y avisa.
 */
function pantry_discount(array $item, string $consumoText, string $motivo): array
{
    $id = (int) $item['id'];
    $c = qty_parse($consumoText);
    $unit = (string) $item['qty_unit'];
    $curVal = $item['qty_value'] !== null ? (float) $item['qty_value'] : null;

    $delta = qty_convert($c['value'], $c['unit'], $unit, (string) $item['category']);

    if ($delta === null || $curVal === null) {
        q_exec(
            "UPDATE pantry_items SET notes = TRIM(BOTH ' · ' FROM CONCAT_WS(' · ', NULLIF(notes,''), ?)), updated_at = NOW() WHERE id = ?",
            ["usó {$consumoText} ({$motivo})", $id]
        );
        return [
            'estado' => 'anotado_sin_resta',
            'item' => $item['name'],
            'nota' => "No pude convertir «{$consumoText}» a {$unit}; lo dejé anotado en el producto sin cambiar el número.",
        ];
    }

    $newVal = max(0.0, round($curVal - $delta, 2));
    $newText = qty_format($newVal, $unit);
    $min = $item['min_qty'] !== null ? (float) $item['min_qty'] : null;
    $status = $newVal <= 0.0 ? 're' : (($min !== null && $newVal < $min) ? 'am' : 'ok');

    q_exec(
        'UPDATE pantry_items SET qty_value = ?, quantity = ?, status = ?, updated_at = NOW() WHERE id = ?',
        [$newVal, $newText, $status, $id]
    );

    if ($newVal <= 0.0) {
        add_to_shopping_list_if_missing((string) $item['name'], 'ia');
    }

    return [
        'estado' => 'ok',
        'item' => $item['name'],
        'antes' => qty_format($curVal, $unit),
        'ahora' => $newText,
        'agotado' => $newVal <= 0.0,
        'bajo_minimo' => $status === 'am',
    ];
}

/**
 * Devuelve a la despensa lo que una comida ya registrada había descontado.
 * Se usa al borrar o al REEMPLAZAR una comida, para no descontar dos veces.
 */
function meal_restore_stock(int $mealId): void
{
    foreach (q_all('SELECT * FROM meal_items WHERE meal_id = ? AND deducted = 1 AND pantry_item_id IS NOT NULL', [$mealId]) as $mi) {
        $p = q_one('SELECT * FROM pantry_items WHERE id = ?', [(int) $mi['pantry_item_id']]);
        if (!$p || (string) $mi['qty_text'] === '') {
            continue;
        }
        $qp = qty_parse((string) $mi['qty_text']);
        $back = qty_convert($qp['value'], $qp['unit'], (string) $p['qty_unit'], (string) $p['category']);
        if ($back === null || $p['qty_value'] === null) {
            continue;
        }
        $nv = round((float) $p['qty_value'] + $back, 2);
        $min = $p['min_qty'] !== null ? (float) $p['min_qty'] : null;
        $status = $nv <= 0.0 ? 're' : (($min !== null && $nv < $min) ? 'am' : 'ok');
        q_exec(
            'UPDATE pantry_items SET qty_value = ?, quantity = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$nv, qty_format($nv, (string) $p['qty_unit']), $status, (int) $p['id']]
        );
    }
}

/**
 * Cachea el total estimado de un período en shopping_list_totals. La cuenta viva
 * la hace shopping_list_total() (php/src/shopping.php): mejor precio × cantidad,
 * o × paquetes si el precio es "por lote" ("$1.00/pack 8 un"). Deja el total como
 * estaba si ningún ítem tiene un precio multiplicable.
 */
function shopping_total_recalc(string $period): void
{
    if (!function_exists('shopping_list_total')) {
        return;
    }
    $t = shopping_list_total($period);
    if (($t['priced'] ?? 0) === 0) {
        return;
    }
    q_exec(
        'INSERT INTO shopping_list_totals (period, amount) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
        [$period, '$' . number_format((float) $t['sum'], 2, '.', '')]
    );
}

// -------------------------------------------------------------------------
//  Contexto y prompt de sistema
// -------------------------------------------------------------------------

const ALI_DAY_KEYS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
const ALI_DAY_LABEL = [
    'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles', 'jueves' => 'Jueves',
    'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
];
const ALI_MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const ALI_REGLAS_COCINA = <<<'TXT'
1. Solo freír, hervir o abrir latas — sin sopas, sin horno, sin recetas complicadas.
2. Porciones triples — no lo cuestiones.
3. El arroz NO es obligatorio: puede ser proteína + ensalada + otro carbohidrato (choclo, puré).
4. Encebollado: solo domingos al desayuno, y comprado hecho (no preparar).
5. El puré siempre va con arroz, nunca solo.
6. Menestra y ensalada no van juntas en el mismo plato.
7. Sardinas nunca en el desayuno.
8. Hígado sin cebolla encima.
9. Sin patacones en casa (toma tiempo).
10. El desayuno NO es opcional: de lunes a sábado es batido de guineo + 3 sándwiches (queso crema + jamón + tortilla); el domingo, encebollado comprado.
TXT;

function assistant_context(): string
{
    $now = time();
    $dayKey = ALI_DAY_KEYS[(int) date('w', $now)];
    $tomorrowKey = ALI_DAY_KEYS[(int) date('w', $now + 86400)];
    $fecha = sprintf(
        '%s %d de %s de %s, %s',
        ALI_DAY_LABEL[$dayKey],
        (int) date('j', $now),
        ALI_MESES[(int) date('n', $now)],
        date('Y', $now),
        date('H:i', $now)
    );

    // Despensa, por vencer primero
    $pantry = q_all("SELECT * FROM pantry_items ORDER BY FIELD(status,'re','am','ok'), category, name");
    $pLines = array_map(function ($p) {
        $extra = $p['expires_label'] !== '' ? "  · {$p['expires_label']}" : '';
        $flag = in_array($p['status'], ['re', 'am'], true) ? ' ⚠' : '';
        $note = $p['notes'] !== '' ? " ({$p['notes']})" : '';
        return "- {$p['name']}: {$p['quantity']}{$note}{$extra}{$flag}";
    }, $pantry);

    // Plan (plantilla semanal)
    $planRows = q_all('SELECT weekday, meal_type, title FROM meal_plan ORDER BY sort_order');
    $byDay = [];
    foreach ($planRows as $r) {
        $byDay[$r['weekday']][] = "{$r['meal_type']}: {$r['title']}";
    }
    $planHoy = isset($byDay[$dayKey]) ? implode(' | ', $byDay[$dayKey]) : 'sin plan';
    $planMan = isset($byDay[$tomorrowKey]) ? implode(' | ', $byDay[$tomorrowKey]) : 'sin plan';

    // Comidas registradas hoy
    $meals = q_all('SELECT meal_type, name, place, status FROM meals WHERE log_date = CURDATE() ORDER BY id');
    $mealStr = $meals
        ? implode('; ', array_map(fn($m) => "{$m['meal_type']}: {$m['name']}" . ($m['place'] !== 'casa' ? " ({$m['place']})" : '') . ($m['status'] === 'planificada' ? ' [planificada]' : ''), $meals))
        : 'ninguna todavía';

    // Lista activa
    $list = q_all("SELECT name, qty, checked FROM shopping_list_items WHERE period = '1 semana' ORDER BY sort_order");
    $listStr = $list
        ? implode(', ', array_map(fn($i) => $i['name'] . ($i['qty'] > 1 ? " x{$i['qty']}" : '') . ($i['checked'] ? ' ✓' : ''), $list))
        : 'vacía';

    // Preferencias
    $prefs = pref_all();
    unset($prefs['reglas_cocina']); // van aparte en el prompt
    $prefStr = $prefs
        ? implode("\n", array_map(fn($k, $v) => "- {$k}: {$v}", array_keys($prefs), $prefs))
        : '- (sin preferencias guardadas)';

    // Notas recientes
    $notes = q_all('SELECT entry FROM meal_memory ORDER BY id DESC LIMIT 8');
    $noteStr = $notes ? implode("\n", array_map(fn($n) => "- {$n['entry']}", $notes)) : '- (ninguna)';

    // Ocasiones para compartir (nº de ideas por tarjeta)
    $occ = q_all(
        'SELECT o.title, o.emoji, o.sort_order, COUNT(i.id) AS n
         FROM occasions o LEFT JOIN occasion_items i ON i.occasion_id = o.id
         GROUP BY o.id, o.title, o.emoji, o.sort_order
         ORDER BY o.sort_order, o.title'
    );
    $occStr = $occ
        ? implode("\n", array_map(fn($o) => trim("- {$o['emoji']} {$o['title']} ({$o['n']} ideas)"), $occ))
        : '- (ninguna)';

    // Lugares: por probar vs. ya visitados (con qué pedir)
    $places = q_all('SELECT title, visited, dish_note FROM discoveries ORDER BY visited DESC, id');
    $pv = array_values(array_filter($places, fn($p) => !$p['visited']));
    $vi = array_values(array_filter($places, fn($p) => (int) $p['visited'] === 1));
    $placeStr = 'Por probar: ' . ($pv ? implode(', ', array_map(fn($p) => $p['title'], $pv)) : '—') . "\n"
        . 'Ya fui: ' . ($vi
            ? implode('; ', array_map(fn($p) => $p['title'] . ($p['dish_note'] !== '' ? " (pedir: {$p['dish_note']})" : ''), $vi))
            : '—');

    $pantryBlock = implode("\n", $pLines);
    $pantryCount = count($pantry) . ' productos';

    return <<<TXT
Fecha y hora: {$fecha}.

Comidas registradas hoy: {$mealStr}.
Plan de hoy ({$dayKey}): {$planHoy}.
Plan de mañana ({$tomorrowKey}): {$planMan}.

DESPENSA ({$pantryCount} — ⚠ = urgente / por vencer):
{$pantryBlock}

Lista de compras activa (1 semana): {$listStr}.

PREFERENCIAS Y PERFIL:
{$prefStr}

NOTAS RECIENTES:
{$noteStr}

OCASIONES PARA COMPARTIR:
{$occStr}

LUGARES (bitácora):
{$placeStr}
TXT;
}

function assistant_system_prompt(): string
{
    $reglas = pref_get('reglas_cocina') ?: ALI_REGLAS_COCINA;
    $ctx = assistant_context();
    $nombre = pref_get('nombre_usuario', 'Gus');
    $lugar = pref_get('ciudad', 'Guayaquil, Ecuador');

    return <<<TXT
Eres **Ali**, el asistente personal de despensa, compras y comidas del hogar de {$nombre} ({$lugar}).
Organizas alimentos, controlas existencias y vencimientos, planificas comidas y armas listas de compra.
Tu prioridad: aprovechar lo que ya hay en casa, reducir el desperdicio y respetar las preferencias de {$nombre}.
No eres un chatbot genérico ni un nutricionista rígido: eres un asistente doméstico organizado que además EJECUTA.

CÓMO ACTÚAS — lo más importante
- Tienes herramientas que leen y MODIFICAN la plataforma de verdad (despensa, comidas, plan, lista, preferencias, recetas, nutrición). Úsalas; no describas cambios que no hiciste.
- Cuando {$nombre} afirma un hecho, EJECUTA el cambio. No pidas permiso para algo que él ya te dijo:
  • "comí / almorcé / cené / desayuné X" (en casa) → registra la comida y DESCUENTA los ingredientes de la despensa. No vuelvas a preguntar.
  • "usé / gasté / se acabó / se terminó X" → ajusta el stock hacia abajo.
  • "compré X" → agrégalo a la despensa (combínalo si ya existe). Solo pregunta si falta la cantidad o la presentación.
  • "agrega X a la lista" → agrégalo a la lista de compras.
  • "corrige el precio de X" / "X cuesta \$N en tal tienda" → precio_fijar sobre el ítem de la lista de compras.
  • un cambio en la plantilla semanal ("los jueves quiero Y") → plan_ajustar.
  • un cambio para una FECHA concreta, hoy o futura ("mañana ceno Y", "cambia la merienda del jueves 4") → comida_registrar con esa fecha (estado="planificada" si aún no pasó). NO uses plan_ajustar para una fecha puntual.
  • "en realidad comí Y" / "no, fue Y" → vuelve a llamar comida_registrar con el mismo día y tipo; reemplaza la anterior y corrige el stock. No hace falta borrar nada.
  • "para noche de pelis / cuando venga gente / para desayunar con alguien se me antoja Z" → ocasion_idea_agregar (crea la ocasión si no existe).
  • "fui a X, pídete Y" / "ya conozco X, es bueno" → descubrimiento_actualizar (márcalo visitado + qué pedir); si el lugar no existe aún, descubrimiento_agregar con ya_fui=true.
- Pide confirmación SOLO para acciones destructivas: borrar un producto entero, borrar una comida ya registrada, reemplazar todo el plan de la semana, o quitar ítems de la lista. Esas herramientas devuelven estado "requiere_confirmacion": ahí pregunta breve y vuelve a llamarlas con confirmado=true.
- Si el nombre o la cantidad es ambiguo (la herramienta devuelve "ambiguo" con varios candidatos), pregunta cuál. Eso es análisis, no permiso.
- "comí fuera / en la calle / en un restaurante / pedí a domicilio" → registra la comida con lugar="fuera" y NO descuentes nada de la despensa.
- Si {$nombre} no da cantidades al registrar una comida, descuenta la proteína principal por el valor de "porciones_por_comida"; no inventes cantidades de arroz o verduras: menciónalo si hace falta.

NO MIENTAS SOBRE LO QUE HICISTE
- NUNCA digas "listo", "guardado", "corregido", "actualicé" o "cambié" si en ESTA conversación la herramienta correspondiente no devolvió estado "ok". Si devolvió "error", "no_encontrado" o no llegaste a llamarla, dilo claro y ofrece la alternativa (p. ej. "ese producto no está en la lista; ¿lo agrego?").
- Si una acción necesita varias herramientas, llámalas todas antes de responder. No prometas hacerlo "ahora".

PRIORIDADES
1. Usa primero lo que vence pronto.  2. Luego lo que ya está en casa.  3. Evita compras innecesarias.
4. Respeta preferencias, restricciones, presupuesto y el tiempo disponible.  5. Si falta un ingrediente, ofrécelo como opcional o para la lista.

PRECISIÓN — no inventar
- Nunca inventes existencias, cantidades, precios, vencimientos, comidas registradas ni compras. Si no tienes el dato, dilo o pregúntalo.
- Distingue datos confirmados de estimaciones (usa "~", "aprox.").
- Antes de recomendar usar algo sensible o por vencer, recuerda verificar olor, aspecto, empaque y conservación.
- Nunca recomiendes consumir algo vencido o en mal estado.

FORMATO
- Español cercano y directo. 2 a 5 líneas por defecto. Listas o tablas solo si ayudan a decidir.
- Después de cambiar algo, dilo en una línea concreta: qué cambiaste y cómo quedó ("Descché 3 porciones de pollo; quedan 5 porciones").
- Para recomendar comida: plato + por qué conviene + qué tienes + qué falta (opcional) + tiempo aprox. + siguiente acción.
- Para la lista: agrupa por categoría, no dupliques lo que ya hay en casa.

REGLAS DE COCINA DE {$nombre} (respétalas siempre)
{$reglas}

===== ESTADO ACTUAL DE LA PLATAFORMA =====
{$ctx}
TXT;
}

// -------------------------------------------------------------------------
//  Definición de herramientas (schema function-calling)
// -------------------------------------------------------------------------

function assistant_tools(): array
{
    $s = fn(string $d, ?array $enum = null) => $enum ? ['type' => 'string', 'description' => $d, 'enum' => $enum] : ['type' => 'string', 'description' => $d];
    $i = fn(string $d) => ['type' => 'integer', 'description' => $d];
    $b = fn(string $d) => ['type' => 'boolean', 'description' => $d];
    $tool = fn(string $name, string $desc, array $props, array $req = []) => [
        'type' => 'function',
        'function' => [
            'name' => $name,
            'description' => $desc,
            'parameters' => [
                'type' => 'object',
                'properties' => $props ?: (object) [], // JSON {} y no [] cuando no hay params
                'required' => $req,
            ],
        ],
    ];

    $cats = ['carnes', 'lacteos', 'granos', 'vegetales', 'latas'];
    $tipos = ['desayuno', 'almuerzo', 'merienda', 'cena', 'snack'];
    $dias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

    return [
        $tool('despensa_consultar', 'Lee la despensa. Úsala para filtrar por vencimiento, bajo stock, categoría o buscar un producto.', [
            'filtro' => $s('qué mostrar', ['todo', 'por_vencer', 'bajo_minimo']),
            'categoria' => $s('opcional: ' . implode('/', $cats)),
            'busqueda' => $s('opcional: texto a buscar en el nombre'),
        ]),

        $tool('despensa_agregar', 'Agrega un producto a la despensa (o suma cantidad si ya existe). Para "compré X".', [
            'nombre' => $s('nombre del producto'),
            'cantidad' => $s('cantidad con unidad, ej. "2 kg", "12 un", "3 porciones", "1 lata"'),
            'categoria' => $s('categoría', $cats),
            'vence' => $s('opcional: etiqueta de vencimiento, ej. "4 días", "fresco ~5 días"'),
            'minimo' => $s('opcional: stock mínimo con unidad'),
            'notas' => $s('opcional'),
        ], ['nombre', 'cantidad']),

        $tool('despensa_ajustar', 'Cambia la cantidad de un producto: resta un consumo ("usé 400 g") o fija un valor nuevo. Para "usé / se acabó / gasté".', [
            'item' => $s('nombre o id del producto'),
            'consumo' => $s('cantidad usada a restar, ej. "400 g", "2 porciones", "media"'),
            'nueva_cantidad' => $s('opcional: fija la cantidad total a este valor en vez de restar'),
            'motivo' => $s('por qué cambia, ej. "almuerzo", "se dañó"'),
        ], ['item', 'motivo']),

        $tool('despensa_eliminar', 'Borra un producto entero de la despensa. DESTRUCTIVO: requiere confirmación.', [
            'item' => $s('nombre o id'),
            'motivo' => $s('por qué se borra'),
            'confirmado' => $b('true solo cuando el usuario ya confirmó'),
        ], ['item', 'motivo']),

        $tool('comida_registrar', 'Registra —o CORRIGE— una comida (consumida o planificada). Si ya hay una comida de ese mismo día y tipo, la reemplaza (y deshace el descuento de stock anterior antes de aplicar el nuevo). Usa esto también para "no, en realidad comí X" o "cambia la merienda del jueves a Y". Si es consumida y en casa, descuenta los ingredientes de la despensa automáticamente.', [
            'tipo' => $s('tipo de comida', $tipos),
            'nombre' => $s('qué se comió, ej. "pollo frito con ensalada y choclos"'),
            'fecha' => $s('opcional YYYY-MM-DD; por defecto hoy. Acepta fechas futuras para planificar.'),
            'personas' => $i('opcional; por defecto el valor de preferencia "personas"'),
            'lugar' => $s('dónde', ['casa', 'fuera', 'comprado']),
            'estado' => $s('consumida (ya se comió) o planificada', ['consumida', 'planificada']),
            'ingredientes' => [
                'type' => 'array',
                'description' => 'opcional: ingredientes usados con su cantidad. Si se omite, se descuenta la proteína principal por "porciones_por_comida".',
                'items' => ['type' => 'object', 'properties' => [
                    'item' => $s('nombre del producto de la despensa'),
                    'cantidad' => $s('cantidad usada, ej. "400 g", "2 un", "1 taza"'),
                ], 'required' => ['item']],
            ],
            'descontar' => $b('por defecto true; false para registrar sin tocar el stock'),
            'reemplazar' => $b('si ya hay una comida de ese día y tipo, la reemplaza. Por defecto true para desayuno/almuerzo/merienda/cena y false para snack. Pon false para añadir una comida extra sin borrar la anterior.'),
        ], ['tipo', 'nombre']),

        $tool('comida_eliminar', 'Borra una comida registrada. DESTRUCTIVO: requiere confirmación.', [
            'id' => $i('id de la comida'),
            'reponer_stock' => $b('si true, intenta devolver a la despensa lo que se había descontado'),
            'confirmado' => $b('true solo cuando el usuario ya confirmó'),
        ], ['id']),

        $tool('plan_ajustar', 'Crea o reemplaza una comida de la PLANTILLA semanal para un día y tipo (afecta ese día de la semana en general, todas las semanas). Para cambiar solo una fecha puntual usa comida_registrar con estado="planificada" y esa fecha.', [
            'dia' => $s('día de la semana', $dias),
            'tipo' => $s('tipo de comida', ['Desayuno', 'Almuerzo', 'Merienda', 'Cena']),
            'titulo' => $s('qué se planifica'),
            'detalle' => $s('opcional: nota corta'),
            'opcional' => $b('opcional: si la comida es opcional'),
        ], ['dia', 'tipo', 'titulo']),

        $tool('plan_reemplazar_semana', 'Reescribe el plan de varios días de una vez. DESTRUCTIVO: requiere confirmación.', [
            'dias' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => [
                    'dia' => $s('día', $dias),
                    'comidas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'tipo' => $s('Desayuno/Almuerzo/Merienda/Cena'),
                        'titulo' => $s('qué se planifica'),
                        'detalle' => $s('opcional'),
                    ], 'required' => ['tipo', 'titulo']]],
                ], 'required' => ['dia', 'comidas']],
            ],
            'confirmado' => $b('true solo cuando el usuario ya confirmó'),
        ], ['dias']),

        $tool('lista_agregar', 'Agrega ítems a la lista de compras.', [
            'items' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => [
                    'nombre' => $s('producto'),
                    'cantidad' => $i('opcional, por defecto 1'),
                    'grupo' => $s('opcional: categoría visible, ej. "Carnes", "Lácteos", "Urgentes"'),
                    'motivo' => $s('opcional: por qué se agrega'),
                ], 'required' => ['nombre']],
            ],
            'periodo' => $s('opcional; por defecto "1 semana"'),
        ], ['items']),

        $tool('lista_quitar', 'Quita ítems de la lista de compras. Requiere confirmación.', [
            'items' => ['type' => 'array', 'items' => $s('nombre o id del ítem')],
            'periodo' => $s('opcional; por defecto "1 semana"'),
            'confirmado' => $b('true solo cuando el usuario ya confirmó'),
        ], ['items']),

        $tool('lista_marcar_comprado', 'Marca ítems de la lista como comprados y, por defecto, los mueve a la despensa. Para "ya compré X".', [
            'items' => ['type' => 'array', 'items' => $s('nombre o id del ítem')],
            'periodo' => $s('opcional; por defecto "1 semana"'),
            'mover_a_despensa' => $b('por defecto true'),
        ], ['items']),

        $tool('precio_fijar', 'Fija o corrige los precios por tienda de un ítem de la lista de compras. Para "el pan cuesta $1.20 en Tía", "corrige el precio del atún", "en Super Maxi la leche está a 1.35". La lista ya muestra precios de referencia por tienda en todos los ítems; usa esto para corregirlos con un dato real.', [
            'item' => $s('nombre o id del ítem en la lista de compras'),
            'precios' => [
                'type' => 'array',
                'description' => 'uno o más precios por tienda',
                'items' => ['type' => 'object', 'properties' => [
                    'tienda' => $s('tienda registrada del hogar: ' . implode(', ', SHOP_STORES) . ' (u otra si el usuario la nombra)'),
                    'precio' => $s('precio. Total por pieza: "$1.20". Precio de lote / paquete: "$1.00/pack 8 un" (=$1 por un paquete de 8; el total suma ceil(cantidad/8) paquetes). Tarifa a granel: "$3.80/lb".'),
                    'mejor' => $b('opcional: marca este como el mejor precio'),
                ], 'required' => ['tienda', 'precio']],
            ],
            'periodo' => $s('opcional; por defecto "1 semana"'),
            'reemplazar' => $b('por defecto true (reemplaza toda la lista de precios del ítem); false para combinar con los que ya tenía'),
        ], ['item', 'precios']),

        $tool('recetas_consultar', 'Lee el recetario guardado.', [
            'busqueda' => $s('opcional: texto a buscar en título o descripción'),
        ]),

        $tool('receta_guardar', 'Guarda una receta nueva en el recetario.', [
            'titulo' => $s('nombre de la receta'),
            'descripcion' => $s('resumen breve'),
            'ingredientes' => ['type' => 'array', 'items' => $s('ingrediente con cantidad')],
            'pasos' => ['type' => 'array', 'items' => $s('paso')],
            'tags' => ['type' => 'array', 'items' => $s('etiqueta corta')],
            'tiempo' => $s('opcional, ej. "20 min"'),
        ], ['titulo', 'descripcion']),

        $tool('preferencia_guardar', 'Guarda o actualiza una preferencia / dato del perfil del hogar (alergias, no le gusta, objetivos, personas, supermercados, porciones_por_comida, etc.).', [
            'clave' => $s('nombre de la preferencia en minúsculas con guion_bajo'),
            'valor' => $s('valor'),
        ], ['clave', 'valor']),

        $tool('preferencia_consultar', 'Lee todas las preferencias y el perfil del hogar.', []),

        $tool('nota_guardar', 'Guarda una nota libre de memoria (algo a recordar que no encaja en una preferencia estructurada).', [
            'texto' => $s('la nota'),
        ], ['texto']),

        $tool('nutricion_registrar', 'Suma o fija los macros del día de hoy.', [
            'calorias' => $i('kcal'),
            'proteina' => $i('g'),
            'carbos' => $i('g'),
            'grasa' => $i('g'),
            'modo' => $s('sumar (default) o fijar', ['sumar', 'fijar']),
        ]),

        $tool('descubrimiento_agregar', 'Guarda un lugar / restaurante / mercado en la bitácora. Úsalo tanto para algo "por probar" como para un sitio donde el usuario YA fue (pon ya_fui=true y qué_pedir).', [
            'titulo' => $s('nombre del lugar'),
            'fuente' => $s('opcional: Instagram, Facebook, recomendación...'),
            'link' => $s('opcional: URL'),
            'meta' => $s('opcional: zona, horario, nota corta'),
            'que_pedir' => $s('opcional: nota a futuro de qué pedir ahí, ej. "los camarones apanados, no el arroz marinero"'),
            'ya_fui' => $b('opcional: true si el usuario ya visitó el lugar (va a "Mis lugares" en vez de "Por probar")'),
        ], ['titulo']),

        $tool('descubrimiento_actualizar', 'Actualiza un lugar que YA está en la bitácora: márcalo como visitado y/o ponle o cambia la nota de qué pedir. Para "fui a X, pídete Y" o "ya conozco X".', [
            'lugar' => $s('nombre (o parte) del lugar en la bitácora'),
            'que_pedir' => $s('opcional: qué pedir ahí'),
            'ya_fui' => $b('opcional: true para marcarlo como visitado (por defecto true)'),
            'rating' => $i('opcional: estrellas 0-5'),
        ], ['lugar']),

        $tool('ocasion_guardar', 'Crea o actualiza una "ocasión para compartir" (tarjeta de ideas por situación: noche de pelis, desayuno con alguien, cuando viene gente...). Si ya existe una con ese título, la actualiza.', [
            'titulo' => $s('nombre de la ocasión, ej. "Cumpleaños en casa"'),
            'emoji' => $s('opcional: un emoji para la tarjeta'),
            'subtitulo' => $s('opcional: descripción corta'),
        ], ['titulo']),

        $tool('ocasion_idea_agregar', 'Agrega una o más ideas de comida a una ocasión. Si la ocasión no existe, la crea. Para "para noche de pelis se me antojan nachos" o "cuando venga gente podríamos pedir pizza".', [
            'ocasion' => $s('título, slug o id de la ocasión'),
            'ideas' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => [
                    'label' => $s('la idea, ej. "Nachos con guacamole"'),
                    'detalle' => $s('opcional: nota corta, ej. "sin cocción"'),
                    'lugar' => $s('donde se resuelve', ['casa', 'fuera']),
                    'precio' => $s('opcional: precio o texto de costo, ej. "$4 a domicilio", "En casa · $0 extra"'),
                ], 'required' => ['label']],
            ],
        ], ['ocasion', 'ideas']),
    ];
}

// -------------------------------------------------------------------------
//  Ejecución de herramientas
// -------------------------------------------------------------------------

function ali_log_event(string $tool, string $summary, array $payload): void
{
    q_exec(
        'INSERT INTO assistant_events (tool, summary, payload) VALUES (?,?,?)',
        [$tool, mb_substr($summary, 0, 250), json_encode($payload, JSON_UNESCAPED_UNICODE)]
    );
}

function ali_slugify(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $t = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $t ?: $s));
    $t = trim($t, '-');
    return $t !== '' ? substr($t, 0, 90) : 'receta-' . substr(md5($s . microtime()), 0, 6);
}

/** Encuentra una ocasión por id, slug o parte del título. null si no hay match claro. */
function ali_occasion_resolve(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    if (ctype_digit($ref)) {
        return q_one('SELECT * FROM occasions WHERE id = ?', [(int) $ref]);
    }
    $exact = q_one('SELECT * FROM occasions WHERE slug = ? OR LOWER(title) = LOWER(?)', [ali_slugify($ref), $ref]);
    if ($exact) {
        return $exact;
    }
    return q_one('SELECT * FROM occasions WHERE LOWER(title) LIKE ? ORDER BY sort_order, id LIMIT 1', ['%' . mb_strtolower($ref) . '%']);
}

/**
 * @param array<string> $changed  dominios modificados (para que la UI refresque)
 * @param array<array>  $actions  resumen legible de cada acción ejecutada
 */
function assistant_dispatch(string $name, array $args, array &$changed, array &$actions): array
{
    $touch = function (string $domain) use (&$changed) {
        if (!in_array($domain, $changed, true)) {
            $changed[] = $domain;
        }
    };
    $act = function (string $resumen) use (&$actions, $name) {
        $actions[] = ['tool' => $name, 'resumen' => $resumen];
    };

    try {
        switch ($name) {
            // ---- lecturas -------------------------------------------------
            case 'despensa_consultar': {
                $rows = q_all("SELECT * FROM pantry_items ORDER BY FIELD(status,'re','am','ok'), category, name");
                $filtro = $args['filtro'] ?? 'todo';
                $cat = trim((string) ($args['categoria'] ?? ''));
                $q = mb_strtolower(trim((string) ($args['busqueda'] ?? '')));
                $rows = array_values(array_filter($rows, function ($r) use ($filtro, $cat, $q) {
                    if ($cat !== '' && $r['category'] !== $cat) return false;
                    if ($q !== '' && !str_contains(mb_strtolower((string) $r['name']), $q)) return false;
                    if ($filtro === 'por_vencer' && !in_array($r['status'], ['am', 're'], true)) return false;
                    if ($filtro === 'bajo_minimo') {
                        if ($r['min_qty'] === null || $r['qty_value'] === null) return false;
                        if ((float) $r['qty_value'] > (float) $r['min_qty']) return false;
                    }
                    return true;
                }));
                return ['estado' => 'ok', 'total' => count($rows), 'productos' => array_map('pantry_public', $rows)];
            }

            case 'recetas_consultar': {
                $q = mb_strtolower(trim((string) ($args['busqueda'] ?? '')));
                $rows = q_all('SELECT * FROM recipes ORDER BY id');
                $out = [];
                foreach ($rows as $r) {
                    if ($q !== '' && !str_contains(mb_strtolower($r['title'] . ' ' . $r['description']), $q)) continue;
                    $ings = q_all('SELECT `text` FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort_order', [$r['id']]);
                    $steps = q_all('SELECT `text` FROM recipe_steps WHERE recipe_id = ? ORDER BY step_number', [$r['id']]);
                    $out[] = [
                        'titulo' => $r['title'], 'descripcion' => $r['description'],
                        'ingredientes' => array_column($ings, 'text'),
                        'pasos' => array_column($steps, 'text'),
                    ];
                }
                return ['estado' => 'ok', 'total' => count($out), 'recetas' => $out];
            }

            case 'preferencia_consultar':
                return ['estado' => 'ok', 'preferencias' => pref_all()];

            // ---- despensa ----------------------------------------------
            case 'despensa_agregar': {
                $nombre = trim((string) ($args['nombre'] ?? ''));
                $cantidad = trim((string) ($args['cantidad'] ?? ''));
                if ($nombre === '' || $cantidad === '') {
                    return ['estado' => 'error', 'mensaje' => 'nombre y cantidad son obligatorios'];
                }
                $res = pantry_resolve($nombre);
                $vence = trim((string) ($args['vence'] ?? ''));
                $notas = trim((string) ($args['notas'] ?? ''));

                // Combinar SOLO si es el mismo producto y las cantidades se pueden sumar
                // de verdad (misma unidad o convertible). Si no, es un producto distinto.
                if ($res['match'] && $res['score'] >= 50) {
                    $ex = $res['match'];
                    $a = qty_parse((string) $ex['quantity']);
                    $bqp = qty_parse($cantidad);
                    $add = qty_convert($bqp['value'], $bqp['unit'], $a['unit'], (string) $ex['category']);
                    if ($a['value'] !== null && $add !== null) {
                        $newText = qty_format($a['value'] + $add, $a['unit']);
                        q_exec(
                            'UPDATE pantry_items SET quantity = ?, expires_label = COALESCE(NULLIF(?,\'\'), expires_label), notes = COALESCE(NULLIF(?,\'\'), notes), status = \'ok\', updated_at = NOW() WHERE id = ?',
                            [$newText, $vence, $notas, (int) $ex['id']]
                        );
                        pantry_recalc_row(q_one('SELECT * FROM pantry_items WHERE id = ?', [(int) $ex['id']]));
                        $row = q_one('SELECT * FROM pantry_items WHERE id = ?', [(int) $ex['id']]);
                        $touch('pantry');
                        $act("Despensa: {$row['name']} ahora {$row['quantity']}");
                        ali_log_event($name, "sumó a {$row['name']}", $args);
                        return ['estado' => 'ok', 'accion' => 'combinado', 'producto' => pantry_public($row)];
                    }
                }

                $cat = in_array($args['categoria'] ?? '', ['carnes', 'lacteos', 'granos', 'vegetales', 'latas'], true) ? $args['categoria'] : 'granos';
                q_exec(
                    'INSERT INTO pantry_items (name, quantity, category, expires_label, status, notes) VALUES (?,?,?,?,?,?)',
                    [$nombre, $cantidad, $cat, $vence, 'ok', $notas]
                );
                $id = last_id();
                $row = q_one('SELECT * FROM pantry_items WHERE id = ?', [$id]);
                pantry_recalc_row($row);
                if (($mn = trim((string) ($args['minimo'] ?? ''))) !== '') {
                    $mp = qty_parse($mn);
                    if ($mp['value'] !== null) {
                        q_exec('UPDATE pantry_items SET min_qty = ? WHERE id = ?', [$mp['value'], $id]);
                    }
                }
                $row = q_one('SELECT * FROM pantry_items WHERE id = ?', [$id]);
                $touch('pantry');
                $act("Despensa: + {$row['name']} ({$row['quantity']})");
                ali_log_event($name, "agregó {$nombre}", $args);
                return ['estado' => 'ok', 'accion' => 'creado', 'producto' => pantry_public($row)];
            }

            case 'despensa_ajustar': {
                $res = pantry_resolve((string) ($args['item'] ?? ''));
                if (!$res['match']) {
                    if (count($res['candidates']) > 1) {
                        return ['estado' => 'ambiguo', 'mensaje' => 'Hay varios productos que encajan; pregunta cuál.',
                            'candidatos' => array_map(fn($c) => ['id' => (int) $c['id'], 'nombre' => $c['name'], 'cantidad' => $c['quantity']], $res['candidates'])];
                    }
                    return ['estado' => 'no_encontrado', 'mensaje' => 'No encontré ese producto en la despensa.'];
                }
                $item = $res['match'];
                $motivo = trim((string) ($args['motivo'] ?? 'ajuste'));
                $nueva = trim((string) ($args['nueva_cantidad'] ?? ''));

                if ($nueva !== '') {
                    q_exec('UPDATE pantry_items SET quantity = ?, status = \'ok\', updated_at = NOW() WHERE id = ?', [$nueva, (int) $item['id']]);
                    pantry_recalc_row(q_one('SELECT * FROM pantry_items WHERE id = ?', [(int) $item['id']]));
                    $row = q_one('SELECT * FROM pantry_items WHERE id = ?', [(int) $item['id']]);
                    $touch('pantry');
                    $act("Despensa: {$row['name']} = {$row['quantity']} ({$motivo})");
                    ali_log_event($name, "fijó {$row['name']} a {$row['quantity']}", $args);
                    return ['estado' => 'ok', 'producto' => pantry_public($row)];
                }

                $consumo = trim((string) ($args['consumo'] ?? ''));
                if ($consumo === '') {
                    return ['estado' => 'error', 'mensaje' => 'Indica "consumo" (cuánto restar) o "nueva_cantidad".'];
                }
                $r = pantry_discount($item, $consumo, $motivo);
                $touch('pantry');
                if ($r['estado'] === 'ok') {
                    $act("Descché {$consumo} de {$r['item']}: {$r['antes']} → {$r['ahora']}");
                }
                ali_log_event($name, "restó {$consumo} de {$item['name']}", $args + ['resultado' => $r]);
                return $r;
            }

            case 'despensa_eliminar': {
                $res = pantry_resolve((string) ($args['item'] ?? ''));
                if (!$res['match']) {
                    return ['estado' => 'no_encontrado', 'mensaje' => 'No encontré ese producto.'];
                }
                $item = $res['match'];
                if (empty($args['confirmado'])) {
                    return ['estado' => 'requiere_confirmacion',
                        'resumen' => "Vas a BORRAR «{$item['name']}» ({$item['quantity']}) de la despensa. ¿Confirmas?"];
                }
                q_exec('DELETE FROM pantry_items WHERE id = ?', [(int) $item['id']]);
                $touch('pantry');
                $act("Despensa: borré {$item['name']}");
                ali_log_event($name, "borró {$item['name']}", $args);
                return ['estado' => 'ok', 'borrado' => $item['name']];
            }

            // ---- comidas ----------------------------------------------
            case 'comida_registrar': {
                $tipo = strtolower(trim((string) ($args['tipo'] ?? 'almuerzo')));
                $tipo = in_array($tipo, ['desayuno', 'almuerzo', 'merienda', 'cena', 'snack'], true) ? $tipo : 'almuerzo';
                $nombre = trim((string) ($args['nombre'] ?? ''));
                if ($nombre === '') {
                    return ['estado' => 'error', 'mensaje' => 'Falta el nombre de la comida.'];
                }
                $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($args['fecha'] ?? '')) ? $args['fecha'] : date('Y-m-d');
                $lugar = in_array($args['lugar'] ?? 'casa', ['casa', 'fuera', 'comprado'], true) ? $args['lugar'] : 'casa';
                $estado = ($args['estado'] ?? 'consumida') === 'planificada' ? 'planificada' : 'consumida';
                $personas = (int) ($args['personas'] ?? (int) pref_get('personas', '1')) ?: 1;
                $descontar = ($args['descontar'] ?? true) !== false;

                // ¿Reemplazar una comida ya registrada de ese día/tipo? Por defecto sí
                // para las comidas principales (corregir "en realidad comí X"), no para
                // los snacks (que suelen acumularse). Se deshace su descuento anterior.
                $reemplazar = array_key_exists('reemplazar', $args) ? ($args['reemplazar'] !== false) : ($tipo !== 'snack');
                $reemplazada = null;
                if ($reemplazar) {
                    $prev = q_one('SELECT * FROM meals WHERE log_date = ? AND meal_type = ? ORDER BY id DESC LIMIT 1', [$fecha, $tipo]);
                    if ($prev) {
                        if ($prev['status'] === 'consumida' && $prev['place'] === 'casa') {
                            meal_restore_stock((int) $prev['id']);
                            $touch('pantry');
                        }
                        q_exec('DELETE FROM meals WHERE id = ?', [(int) $prev['id']]); // cascada -> meal_items
                        $reemplazada = (string) $prev['name'];
                    }
                }

                q_exec(
                    'INSERT INTO meals (log_date, meal_type, name, servings, place, status) VALUES (?,?,?,?,?,?)',
                    [$fecha, $tipo, $nombre, $personas, $lugar, $estado]
                );
                $mealId = last_id();
                $touch('meals');

                $descuentos = [];
                $doDiscount = $descontar && $estado === 'consumida' && $lugar === 'casa';

                $ings = is_array($args['ingredientes'] ?? null) ? $args['ingredientes'] : [];
                if ($doDiscount && $ings) {
                    foreach ($ings as $ing) {
                        $iname = trim((string) ($ing['item'] ?? ''));
                        if ($iname === '') continue;
                        $cant = trim((string) ($ing['cantidad'] ?? ''));
                        $res = pantry_resolve($iname);
                        $pid = $res['match']['id'] ?? null;

                        if ($res['match'] && $cant !== '') {
                            $d = pantry_discount($res['match'], $cant, "{$tipo}: {$nombre}");
                        } elseif ($res['match'] && $res['match']['category'] === 'carnes') {
                            $cant = ali_porciones_comida() . ' porciones';
                            $d = pantry_discount($res['match'], $cant, "{$tipo}: {$nombre}");
                        } elseif ($res['match']) {
                            $d = ['estado' => 'sin_cantidad', 'item' => $res['match']['name']];
                        } else {
                            $d = ['estado' => 'no_encontrado', 'item' => $iname];
                        }
                        $descuentos[] = $d;
                        q_exec(
                            'INSERT INTO meal_items (meal_id, pantry_item_id, name, qty_text, deducted) VALUES (?,?,?,?,?)',
                            [$mealId, $pid, $iname, $cant, ($d['estado'] ?? '') === 'ok' ? 1 : 0]
                        );
                    }
                } elseif ($doDiscount) {
                    // Sin lista de ingredientes: inferir del nombre la proteína principal
                    // (carnes -> porciones_por_comida) y latas mencionadas (1 lata).
                    $nd = ali_deaccent($nombre);
                    $nWords = preg_split('/[^\p{L}\p{N}]+/u', $nd) ?: [];
                    $hit = false;
                    foreach (q_all("SELECT * FROM pantry_items WHERE category IN ('carnes','latas')") as $c) {
                        $matchTok = false;
                        foreach (ali_name_tokens((string) $c['name']) as $tk) {
                            if (in_array($tk, $nWords, true)) {
                                $matchTok = true;
                                break;
                            }
                        }
                        if (!$matchTok) continue;

                        $cant = $c['category'] === 'carnes' ? ali_porciones_comida() . ' porciones' : '1 lata';
                        $d = pantry_discount($c, $cant, "{$tipo}: {$nombre}");
                        $descuentos[] = $d;
                        q_exec('INSERT INTO meal_items (meal_id, pantry_item_id, name, qty_text, deducted) VALUES (?,?,?,?,?)',
                            [$mealId, (int) $c['id'], $c['name'], $cant, ($d['estado'] ?? '') === 'ok' ? 1 : 0]);
                        $hit = true;
                    }
                    if (!$hit) {
                        $descuentos[] = ['estado' => 'sin_match', 'mensaje' => 'No identifiqué una proteína de la despensa en el nombre. Registré la comida pero no descché nada; dime qué ingredientes usar si quieres el descuento.'];
                    }
                }
                if ($doDiscount) {
                    $touch('pantry');
                }

                $okD = array_values(array_filter($descuentos, fn($d) => ($d['estado'] ?? '') === 'ok'));
                $verbo = $reemplazada !== null && ali_deaccent($reemplazada) !== ali_deaccent($nombre) ? 'Corregí' : ($estado === 'planificada' ? 'Planifiqué' : 'Registré');
                $resumen = $estado === 'planificada'
                    ? "{$verbo} {$tipo} ({$fecha}): {$nombre}"
                    : ($lugar !== 'casa'
                        ? "{$verbo} {$tipo} fuera de casa: {$nombre} (sin tocar la despensa)"
                        : "{$verbo} {$tipo}: {$nombre}" . ($okD ? ' · descché ' . implode(', ', array_map(fn($d) => "{$d['item']} → {$d['ahora']}", $okD)) : ''));
                if ($reemplazada !== null && ali_deaccent($reemplazada) !== ali_deaccent($nombre)) {
                    $resumen .= " (antes: {$reemplazada})";
                }
                $act($resumen);
                ali_log_event($name, $resumen, $args + ['descuentos' => $descuentos, 'reemplazada' => $reemplazada]);

                return ['estado' => 'ok', 'comida_id' => $mealId, 'registrada' => $resumen, 'reemplazo_a' => $reemplazada, 'descuentos' => $descuentos];
            }

            case 'comida_eliminar': {
                $id = (int) ($args['id'] ?? 0);
                $meal = $id ? q_one('SELECT * FROM meals WHERE id = ?', [$id]) : null;
                if (!$meal) {
                    return ['estado' => 'no_encontrado', 'mensaje' => 'No encontré esa comida.'];
                }
                if (empty($args['confirmado'])) {
                    return ['estado' => 'requiere_confirmacion',
                        'resumen' => "Vas a borrar la comida «{$meal['meal_type']}: {$meal['name']}» del {$meal['log_date']}. ¿Confirmas?"];
                }
                if (!empty($args['reponer_stock'])) {
                    meal_restore_stock($id);
                    $touch('pantry');
                }
                q_exec('DELETE FROM meals WHERE id = ?', [$id]);
                $touch('meals');
                $act("Borré la comida: {$meal['name']}");
                ali_log_event($name, "borró comida {$meal['name']}", $args);
                return ['estado' => 'ok', 'borrada' => $meal['name']];
            }

            // ---- plan ------------------------------------------------
            case 'plan_ajustar': {
                $dia = strtolower(trim((string) ($args['dia'] ?? '')));
                $dias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
                if (!in_array($dia, $dias, true)) {
                    return ['estado' => 'error', 'mensaje' => 'día inválido'];
                }
                $tipo = ucfirst(strtolower(trim((string) ($args['tipo'] ?? 'Almuerzo'))));
                $titulo = trim((string) ($args['titulo'] ?? ''));
                if ($titulo === '') {
                    return ['estado' => 'error', 'mensaje' => 'falta el título'];
                }
                $detalle = trim((string) ($args['detalle'] ?? ''));
                $opcional = !empty($args['opcional']) ? 1 : 0;

                $ex = q_one('SELECT * FROM meal_plan WHERE weekday = ? AND meal_type = ?', [$dia, $tipo]);
                if ($ex) {
                    q_exec('UPDATE meal_plan SET title = ?, detail = ?, optional = ? WHERE id = ?', [$titulo, $detalle, $opcional, (int) $ex['id']]);
                } else {
                    $max = (int) (q_one('SELECT COALESCE(MAX(sort_order),-1) m FROM meal_plan')['m']);
                    q_exec('INSERT INTO meal_plan (weekday, meal_type, title, detail, optional, sort_order) VALUES (?,?,?,?,?,?)',
                        [$dia, $tipo, $titulo, $detalle, $opcional, $max + 1]);
                }
                $touch('plan');
                $act("Plan {$dia} {$tipo}: {$titulo}");
                ali_log_event($name, "plan {$dia}/{$tipo}", $args);
                return ['estado' => 'ok', 'dia' => $dia, 'tipo' => $tipo, 'titulo' => $titulo];
            }

            case 'plan_reemplazar_semana': {
                $dias = is_array($args['dias'] ?? null) ? $args['dias'] : [];
                if (!$dias) {
                    return ['estado' => 'error', 'mensaje' => 'no hay días que reemplazar'];
                }
                if (empty($args['confirmado'])) {
                    $prev = array_map(fn($d) => $d['dia'] ?? '?', $dias);
                    return ['estado' => 'requiere_confirmacion',
                        'resumen' => 'Vas a reemplazar el plan de: ' . implode(', ', $prev) . '. ¿Confirmas?'];
                }
                $done = [];
                foreach ($dias as $d) {
                    $dk = strtolower(trim((string) ($d['dia'] ?? '')));
                    if ($dk === '') continue;
                    q_exec('DELETE FROM meal_plan WHERE weekday = ?', [$dk]);
                    $max = (int) (q_one('SELECT COALESCE(MAX(sort_order),-1) m FROM meal_plan')['m']);
                    foreach ((array) ($d['comidas'] ?? []) as $j => $c) {
                        q_exec('INSERT INTO meal_plan (weekday, meal_type, title, detail, optional, sort_order) VALUES (?,?,?,?,0,?)',
                            [$dk, ucfirst(strtolower((string) ($c['tipo'] ?? 'Almuerzo'))), (string) ($c['titulo'] ?? ''), (string) ($c['detalle'] ?? ''), $max + 1 + $j]);
                    }
                    $done[] = $dk;
                }
                $touch('plan');
                $act('Reescribí el plan de: ' . implode(', ', $done));
                ali_log_event($name, 'reemplazó plan semana', $args);
                return ['estado' => 'ok', 'dias' => $done];
            }

            // ---- lista de compras -----------------------------------
            case 'lista_agregar': {
                $items = is_array($args['items'] ?? null) ? $args['items'] : [];
                $periodo = trim((string) ($args['periodo'] ?? '1 semana')) ?: '1 semana';
                $added = [];
                foreach ($items as $it) {
                    $nom = trim((string) ($it['nombre'] ?? ''));
                    if ($nom === '') continue;
                    $qty = max(1, (int) ($it['cantidad'] ?? 1));
                    $grupo = trim((string) ($it['grupo'] ?? ''));
                    $ex = q_one('SELECT * FROM shopping_list_items WHERE period = ? AND LOWER(name) = LOWER(?)', [$periodo, $nom]);
                    if ($ex) {
                        q_exec('UPDATE shopping_list_items SET qty = qty + ? WHERE id = ?', [$qty, (int) $ex['id']]);
                    } else {
                        $max = (int) (q_one('SELECT COALESCE(MAX(sort_order),-1) m FROM shopping_list_items WHERE period = ?', [$periodo])['m']);
                        q_exec('INSERT INTO shopping_list_items (period, group_label, name, qty, prices, sort_order, source) VALUES (?,?,?,?,?,?,\'ia\')',
                            [$periodo, $grupo, $nom, $qty, '[]', $max + 1]);
                    }
                    $added[] = $nom;
                }
                if (!$added) {
                    return ['estado' => 'error', 'mensaje' => 'no había ítems válidos'];
                }
                $touch('list');
                $act('Lista: + ' . implode(', ', $added));
                ali_log_event($name, 'lista +' . implode(',', $added), $args);
                return ['estado' => 'ok', 'agregados' => $added, 'periodo' => $periodo];
            }

            case 'lista_quitar': {
                $items = is_array($args['items'] ?? null) ? $args['items'] : [];
                $periodo = trim((string) ($args['periodo'] ?? '1 semana')) ?: '1 semana';
                if (empty($args['confirmado'])) {
                    return ['estado' => 'requiere_confirmacion',
                        'resumen' => 'Vas a quitar de la lista: ' . implode(', ', array_map('strval', $items)) . '. ¿Confirmas?'];
                }
                $removed = [];
                foreach ($items as $ref) {
                    $ref = trim((string) $ref);
                    $row = ctype_digit($ref)
                        ? q_one('SELECT * FROM shopping_list_items WHERE id = ? AND period = ?', [(int) $ref, $periodo])
                        : q_one('SELECT * FROM shopping_list_items WHERE period = ? AND LOWER(name) LIKE ?', [$periodo, '%' . mb_strtolower($ref) . '%']);
                    if ($row) {
                        q_exec('DELETE FROM shopping_list_items WHERE id = ?', [(int) $row['id']]);
                        $removed[] = $row['name'];
                    }
                }
                $touch('list');
                $act('Lista: − ' . implode(', ', $removed));
                ali_log_event($name, 'lista -' . implode(',', $removed), $args);
                return ['estado' => 'ok', 'quitados' => $removed];
            }

            case 'lista_marcar_comprado': {
                $items = is_array($args['items'] ?? null) ? $args['items'] : [];
                $periodo = trim((string) ($args['periodo'] ?? '1 semana')) ?: '1 semana';
                $mover = ($args['mover_a_despensa'] ?? true) !== false;
                $done = [];
                foreach ($items as $ref) {
                    $ref = trim((string) $ref);
                    $row = ctype_digit($ref)
                        ? q_one('SELECT * FROM shopping_list_items WHERE id = ? AND period = ?', [(int) $ref, $periodo])
                        : q_one('SELECT * FROM shopping_list_items WHERE period = ? AND LOWER(name) LIKE ?', [$periodo, '%' . mb_strtolower($ref) . '%']);
                    if (!$row) continue;
                    q_exec('UPDATE shopping_list_items SET checked = 1 WHERE id = ?', [(int) $row['id']]);
                    $done[] = $row['name'];
                    if ($mover) {
                        $r2 = assistant_dispatch('despensa_agregar', [
                            'nombre' => $row['name'],
                            'cantidad' => (string) ($row['qty'] ?? 1) . ' un',
                        ], $changed, $actions);
                    }
                }
                if (!$done) {
                    return ['estado' => 'no_encontrado', 'mensaje' => 'No encontré esos ítems en la lista.'];
                }
                $touch('list');
                $act('Compré: ' . implode(', ', $done) . ($mover ? ' (a la despensa)' : ''));
                ali_log_event($name, 'compró ' . implode(',', $done), $args);
                return ['estado' => 'ok', 'comprados' => $done, 'movidos_a_despensa' => $mover];
            }

            case 'precio_fijar': {
                $periodo = trim((string) ($args['periodo'] ?? '1 semana')) ?: '1 semana';
                $ref = trim((string) ($args['item'] ?? ''));
                if ($ref === '') {
                    return ['estado' => 'error', 'mensaje' => 'Falta el ítem al que ponerle precio.'];
                }
                $row = ctype_digit($ref)
                    ? q_one('SELECT * FROM shopping_list_items WHERE id = ? AND period = ?', [(int) $ref, $periodo])
                    : q_one('SELECT * FROM shopping_list_items WHERE period = ? AND LOWER(name) LIKE ? ORDER BY sort_order', [$periodo, '%' . mb_strtolower($ref) . '%']);
                if (!$row) {
                    return ['estado' => 'no_encontrado', 'mensaje' => "«{$ref}» no está en la lista de compras ({$periodo}). Agrégalo con lista_agregar si quieres registrarle un precio."];
                }

                $in = is_array($args['precios'] ?? null) ? $args['precios'] : [];
                $nuevos = [];
                foreach ($in as $p) {
                    $tienda = trim((string) ($p['tienda'] ?? ''));
                    $precio = trim((string) ($p['precio'] ?? ''));
                    if ($tienda === '' || $precio === '') {
                        continue;
                    }
                    // "1.35" -> "$1.35"; deja intactos "$3.80/lb", "$1,20", etc.
                    if ($precio[0] !== '$') {
                        $norm = str_replace(',', '.', $precio);
                        if (is_numeric($norm)) {
                            $precio = '$' . number_format((float) $norm, 2, '.', '');
                        }
                    }
                    $entry = ['store' => $tienda, 'price' => $precio];
                    if (!empty($p['mejor'])) {
                        $entry['best'] = true;
                    }
                    $nuevos[] = $entry;
                }
                if (!$nuevos) {
                    return ['estado' => 'error', 'mensaje' => 'No diste ningún precio válido (tienda + precio).'];
                }

                $base = ($args['reemplazar'] ?? true) === false
                    ? (json_decode((string) $row['prices'], true) ?: [])
                    : [];
                $byStore = [];
                foreach ($base as $e) {
                    $byStore[mb_strtolower((string) ($e['store'] ?? ''))] = $e;
                }
                foreach ($nuevos as $e) {
                    $byStore[mb_strtolower($e['store'])] = $e;
                }
                $merged = array_values($byStore);

                // Exactamente un "best": si el usuario no marcó ninguno, el más barato.
                $seenBest = false;
                foreach ($merged as $k => &$m) {
                    if (!empty($m['best'])) {
                        if ($seenBest) {
                            unset($m['best']);
                        } else {
                            $seenBest = true;
                        }
                    }
                }
                unset($m);
                if (!$seenBest) {
                    $cheapIdx = null;
                    $cheap = INF;
                    foreach ($merged as $k => $m) {
                        $n = (float) preg_replace('/[^\d.]/', '', str_replace(',', '.', (string) $m['price']));
                        if ($n > 0 && $n < $cheap) {
                            $cheap = $n;
                            $cheapIdx = $k;
                        }
                    }
                    if ($cheapIdx !== null) {
                        $merged[$cheapIdx]['best'] = true;
                    }
                }

                q_exec('UPDATE shopping_list_items SET prices = ? WHERE id = ?', [json_encode($merged, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
                shopping_total_recalc($periodo);
                $touch('list');
                $act("Precio {$row['name']}: " . implode(' / ', array_map(fn($m) => "{$m['store']} {$m['price']}" . (!empty($m['best']) ? ' ✓' : ''), $merged)));
                ali_log_event($name, "precio {$row['name']}", $args);
                return ['estado' => 'ok', 'item' => $row['name'], 'periodo' => $periodo, 'precios' => $merged];
            }

            // ---- recetas / preferencias / notas / nutrición / lugares ----
            case 'receta_guardar': {
                $titulo = trim((string) ($args['titulo'] ?? ''));
                $desc = trim((string) ($args['descripcion'] ?? ''));
                if ($titulo === '') {
                    return ['estado' => 'error', 'mensaje' => 'falta el título'];
                }
                $slug = ali_slugify($titulo);
                if (q_one('SELECT id FROM recipes WHERE slug = ?', [$slug])) {
                    $slug .= '-' . substr(md5(microtime()), 0, 4);
                }
                q_exec(
                    "INSERT INTO recipes (slug, title, description, tags, time_label, gradient, source) VALUES (?,?,?,?,?,?, 'custom')",
                    [$slug, $titulo, $desc, json_encode((array) ($args['tags'] ?? []), JSON_UNESCAPED_UNICODE),
                        (string) ($args['tiempo'] ?? ''), 'linear-gradient(160deg,#EBF5EF,#4A8F68)']
                );
                $rid = last_id();
                foreach (array_values((array) ($args['ingredientes'] ?? [])) as $k => $t) {
                    q_exec('INSERT INTO recipe_ingredients (recipe_id, `text`, missing, sort_order) VALUES (?,?,0,?)', [$rid, (string) $t, $k]);
                }
                foreach (array_values((array) ($args['pasos'] ?? [])) as $k => $t) {
                    q_exec('INSERT INTO recipe_steps (recipe_id, step_number, `text`) VALUES (?,?,?)', [$rid, $k + 1, (string) $t]);
                }
                $touch('recipes');
                $act("Receta guardada: {$titulo}");
                ali_log_event($name, "receta {$titulo}", $args);
                return ['estado' => 'ok', 'receta' => $titulo, 'slug' => $slug];
            }

            case 'preferencia_guardar': {
                $clave = strtolower(trim((string) ($args['clave'] ?? '')));
                $clave = preg_replace('/[^a-z0-9_]+/', '_', $clave);
                $valor = trim((string) ($args['valor'] ?? ''));
                if ($clave === '' || $valor === '') {
                    return ['estado' => 'error', 'mensaje' => 'clave y valor son obligatorios'];
                }
                pref_set($clave, $valor);
                $touch('prefs');
                $act("Preferencia: {$clave} = {$valor}");
                ali_log_event($name, "pref {$clave}", $args);
                return ['estado' => 'ok', 'clave' => $clave, 'valor' => $valor];
            }

            case 'nota_guardar': {
                $texto = trim((string) ($args['texto'] ?? ''));
                if ($texto === '') {
                    return ['estado' => 'error', 'mensaje' => 'la nota está vacía'];
                }
                q_exec('INSERT INTO meal_memory (entry) VALUES (?)', [$texto]);
                $touch('memory');
                $act("Nota: {$texto}");
                ali_log_event($name, 'nota', $args);
                return ['estado' => 'ok', 'nota' => $texto];
            }

            case 'nutricion_registrar': {
                $today = date('Y-m-d');
                $row = q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]);
                if (!$row) {
                    q_exec('INSERT INTO nutrition_log (log_date) VALUES (?)', [$today]);
                    $row = q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]);
                }
                $fijar = ($args['modo'] ?? 'sumar') === 'fijar';
                $map = ['calorias' => 'calories', 'proteina' => 'protein', 'carbos' => 'carbs', 'grasa' => 'fat'];
                $sets = [];
                $params = [];
                foreach ($map as $es => $col) {
                    if (isset($args[$es]) && is_numeric($args[$es])) {
                        $sets[] = "{$col} = " . ($fijar ? '?' : "{$col} + ?");
                        $params[] = (int) $args[$es];
                    }
                }
                if (!$sets) {
                    return ['estado' => 'error', 'mensaje' => 'no diste ningún macro'];
                }
                $params[] = $today;
                q_exec('UPDATE nutrition_log SET ' . implode(', ', $sets) . ' WHERE log_date = ?', $params);
                $new = q_one('SELECT * FROM nutrition_log WHERE log_date = ?', [$today]);
                $touch('nutrition');
                $act("Nutrición hoy: {$new['calories']} kcal · {$new['protein']} g proteína");
                ali_log_event($name, 'nutrición', $args);
                return ['estado' => 'ok', 'hoy' => ['calorias' => (int) $new['calories'], 'proteina' => (int) $new['protein'], 'carbos' => (int) $new['carbs'], 'grasa' => (int) $new['fat']]];
            }

            case 'descubrimiento_agregar': {
                $titulo = trim((string) ($args['titulo'] ?? ''));
                if ($titulo === '') {
                    return ['estado' => 'error', 'mensaje' => 'falta el título'];
                }
                $yaFui = !empty($args['ya_fui']) ? 1 : 0;
                $quePedir = trim((string) ($args['que_pedir'] ?? ''));
                q_exec('INSERT INTO discoveries (title, source, link, meta, gradient, visited, dish_note) VALUES (?,?,?,?,?,?,?)',
                    [$titulo, (string) ($args['fuente'] ?? ''), (string) ($args['link'] ?? ''), (string) ($args['meta'] ?? ''),
                        'linear-gradient(135deg,#DAE8D4,#4A7856)', $yaFui, $quePedir]);
                $touch('discoveries');
                $donde = $yaFui ? 'Mis lugares' : 'Por probar';
                $act("Guardé el lugar ({$donde}): {$titulo}" . ($quePedir !== '' ? " — pedir: {$quePedir}" : ''));
                ali_log_event($name, "lugar {$titulo}", $args);
                return ['estado' => 'ok', 'guardado' => $titulo, 'seccion' => $donde];
            }

            case 'descubrimiento_actualizar': {
                $ref = trim((string) ($args['lugar'] ?? ''));
                if ($ref === '') {
                    return ['estado' => 'error', 'mensaje' => 'falta el nombre del lugar'];
                }
                $row = ctype_digit($ref)
                    ? q_one('SELECT * FROM discoveries WHERE id = ?', [(int) $ref])
                    : q_one('SELECT * FROM discoveries WHERE LOWER(title) LIKE ? ORDER BY id LIMIT 1', ['%' . mb_strtolower($ref) . '%']);
                if (!$row) {
                    return ['estado' => 'no_encontrado', 'mensaje' => "«{$ref}» no está en la bitácora. Agrégalo con descubrimiento_agregar."];
                }
                $visited = array_key_exists('ya_fui', $args) ? (!empty($args['ya_fui']) ? 1 : 0) : 1;
                $dishNote = array_key_exists('que_pedir', $args) ? trim((string) $args['que_pedir']) : (string) $row['dish_note'];
                $rating = (isset($args['rating']) && is_numeric($args['rating']))
                    ? max(0, min(5, (int) $args['rating']))
                    : (int) $row['rating'];
                q_exec('UPDATE discoveries SET visited = ?, dish_note = ?, rating = ? WHERE id = ?',
                    [$visited, $dishNote, $rating, (int) $row['id']]);
                $touch('discoveries');
                $act("Lugar: {$row['title']} → " . ($visited ? 'ya fui' : 'por probar') . ($dishNote !== '' ? " · pedir: {$dishNote}" : ''));
                ali_log_event($name, "actualizó lugar {$row['title']}", $args);
                return ['estado' => 'ok', 'lugar' => $row['title'], 'visitado' => (bool) $visited, 'que_pedir' => $dishNote];
            }

            case 'ocasion_guardar': {
                $titulo = trim((string) ($args['titulo'] ?? ''));
                if ($titulo === '') {
                    return ['estado' => 'error', 'mensaje' => 'falta el título'];
                }
                $occ = ali_occasion_resolve($titulo);
                if ($occ) {
                    $sets = [];
                    $params = [];
                    if (array_key_exists('emoji', $args)) { $sets[] = 'emoji = ?'; $params[] = (string) $args['emoji']; }
                    if (array_key_exists('subtitulo', $args)) { $sets[] = 'subtitle = ?'; $params[] = (string) $args['subtitulo']; }
                    if ($sets) {
                        $params[] = (int) $occ['id'];
                        q_exec('UPDATE occasions SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
                    }
                    $touch('occasions');
                    $act("Ocasión actualizada: {$titulo}");
                    ali_log_event($name, "ocasión {$titulo}", $args);
                    return ['estado' => 'ok', 'accion' => 'actualizada', 'ocasion' => $titulo, 'id' => (int) $occ['id']];
                }
                $slug = ali_slugify($titulo);
                if (q_one('SELECT id FROM occasions WHERE slug = ?', [$slug])) {
                    $slug .= '-' . substr((string) time(), -4);
                }
                $max = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM occasions')['m']);
                q_exec('INSERT INTO occasions (slug, emoji, title, subtitle, sort_order) VALUES (?,?,?,?,?)',
                    [$slug, (string) ($args['emoji'] ?? ''), $titulo, (string) ($args['subtitulo'] ?? ''), $max + 1]);
                $touch('occasions');
                $act("Nueva ocasión: {$titulo}");
                ali_log_event($name, "ocasión {$titulo}", $args);
                return ['estado' => 'ok', 'accion' => 'creada', 'ocasion' => $titulo, 'id' => last_id()];
            }

            case 'ocasion_idea_agregar': {
                $ref = trim((string) ($args['ocasion'] ?? ''));
                $ideas = is_array($args['ideas'] ?? null) ? $args['ideas'] : [];
                if ($ref === '' || !$ideas) {
                    return ['estado' => 'error', 'mensaje' => 'indica la ocasión y al menos una idea'];
                }
                $occ = ali_occasion_resolve($ref);
                if (!$occ) {
                    $slug = ali_slugify($ref);
                    if (q_one('SELECT id FROM occasions WHERE slug = ?', [$slug])) {
                        $slug .= '-' . substr((string) time(), -4);
                    }
                    $max = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM occasions')['m']);
                    q_exec('INSERT INTO occasions (slug, emoji, title, subtitle, sort_order) VALUES (?,?,?,?,?)',
                        [$slug, '', $ref, '', $max + 1]);
                    $occ = q_one('SELECT * FROM occasions WHERE id = ?', [last_id()]);
                }
                $added = [];
                $sort = (int) (q_one('SELECT COALESCE(MAX(sort_order), -1) AS m FROM occasion_items WHERE occasion_id = ?', [(int) $occ['id']])['m']);
                foreach ($ideas as $idea) {
                    $label = trim((string) ($idea['label'] ?? ''));
                    if ($label === '') continue;
                    $lugar = ($idea['lugar'] ?? 'casa') === 'fuera' ? 'fuera' : 'casa';
                    q_exec('INSERT INTO occasion_items (occasion_id, label, detail, place, price, sort_order) VALUES (?,?,?,?,?,?)',
                        [(int) $occ['id'], $label, (string) ($idea['detalle'] ?? ''), $lugar, (string) ($idea['precio'] ?? ''), ++$sort]);
                    $added[] = $label;
                }
                if (!$added) {
                    return ['estado' => 'error', 'mensaje' => 'ninguna idea válida'];
                }
                $touch('occasions');
                $act("«{$occ['title']}»: + " . implode(', ', $added));
                ali_log_event($name, "ideas en {$occ['title']}", $args);
                return ['estado' => 'ok', 'ocasion' => $occ['title'], 'agregadas' => $added];
            }

            default:
                return ['estado' => 'error', 'mensaje' => "herramienta desconocida: {$name}"];
        }
    } catch (Throwable $e) {
        error_log('[ali] tool ' . $name . ': ' . $e->getMessage());
        return ['estado' => 'error', 'mensaje' => 'Falló la herramienta ' . $name . ': ' . $e->getMessage()];
    }
}

// -------------------------------------------------------------------------
//  Llamada a DeepSeek y bucle del agente
// -------------------------------------------------------------------------

function deepseek_call(array $messages, ?array $tools): array
{
    $payload = ['model' => ALI_MODEL, 'max_tokens' => 900, 'temperature' => 0.3, 'messages' => $messages];
    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $ch = curl_init(DEEPSEEK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => ALI_HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . env('DEEPSEEK_API_KEY'),
        ],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        throw new RuntimeException('DeepSeek sin respuesta: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("DeepSeek respondió {$code}: " . substr((string) $res, 0, 300));
    }
    $data = json_decode((string) $res, true);
    $msg = $data['choices'][0]['message'] ?? null;
    if (!is_array($msg)) {
        throw new RuntimeException('DeepSeek: respuesta sin message');
    }
    // Solo los campos que la API acepta de vuelta como entrada.
    $clean = ['role' => $msg['role'] ?? 'assistant', 'content' => $msg['content'] ?? null];
    if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
        $clean['tool_calls'] = array_map(fn($tc) => [
            'id' => $tc['id'] ?? '',
            'type' => 'function',
            'function' => [
                'name' => $tc['function']['name'] ?? '',
                'arguments' => $tc['function']['arguments'] ?? '{}',
            ],
        ], $msg['tool_calls']);
        if ($clean['content'] === null) {
            $clean['content'] = '';
        }
    }
    return $clean;
}

/**
 * Ejecuta a Ali: prompt + herramientas + bucle.
 * @param array<int,array{role:string,content:string}> $history
 * @return array{reply:string, simulated:bool, actions:array, changed:array}
 */
function assistant_reply(string $message, array $history): array
{
    if (!chat_is_configured()) {
        return assistant_simulated($message);
    }

    $messages = [['role' => 'system', 'content' => assistant_system_prompt()]];
    foreach (array_slice($history, -10) as $h) {
        $role = $h['role'] ?? '';
        $content = trim((string) ($h['content'] ?? ''));
        if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $tools = assistant_tools();
    $changed = [];
    $actions = [];
    $issues = [];
    $final = '';

    for ($round = 0; $round < ALI_MAX_ROUNDS; $round++) {
        $m = deepseek_call($messages, $tools);
        $messages[] = $m;
        $calls = $m['tool_calls'] ?? null;
        if (!is_array($calls) || !$calls) {
            $final = trim((string) ($m['content'] ?? ''));
            break;
        }
        foreach ($calls as $tc) {
            $fn = (string) ($tc['function']['name'] ?? '');
            $rawArgs = $tc['function']['arguments'] ?? '{}';
            $parsed = json_decode(is_string($rawArgs) ? $rawArgs : '{}', true);
            $result = assistant_dispatch($fn, is_array($parsed) ? $parsed : [], $changed, $actions);
            if (in_array($result['estado'] ?? '', ['error', 'no_encontrado'], true)) {
                $issues[] = (string) ($result['mensaje'] ?? ('no se pudo ejecutar ' . $fn));
            }
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($tc['id'] ?? ''),
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    if ($final === '') {
        // Se acabaron las rondas o vino sin texto: forzar respuesta sin herramientas.
        try {
            $m = deepseek_call($messages, null);
            $final = trim((string) ($m['content'] ?? ''));
        } catch (Throwable $e) {
            error_log('[ali] cierre forzado: ' . $e->getMessage());
        }
    }
    if ($final === '') {
        if ($actions) {
            $final = 'Listo: ' . implode('. ', array_column($actions, 'resumen')) . '.';
        } elseif ($issues) {
            $final = 'No pude completarlo: ' . implode(' ', array_values(array_unique($issues)));
        } else {
            $final = 'No pude completar eso. ¿Lo intentamos de otra forma?';
        }
    }

    return [
        'reply' => $final,
        'simulated' => false,
        'actions' => $actions,
        'changed' => array_values(array_unique($changed)),
    ];
}

/**
 * Modo sin API key: intenta lo esencial (registrar comida + descontar) con
 * heurística local; para lo demás, responde que necesita la key.
 */
function assistant_simulated(string $message): array
{
    $changed = [];
    $actions = [];
    $lm = mb_strtolower($message);

    $comio = preg_match('/\b(com[ií]|almor[cz]|almorc|cen[eé]|desayun|me com[ií]|comimos)/u', $lm);
    $fuera = preg_match('/\b(fuera|afuera|en la calle|restauran|domicilio|pedimos|delivery)/u', $lm);

    if ($comio) {
        $tipo = str_contains($lm, 'desayun') ? 'desayuno' : (preg_match('/\bcen/u', $lm) ? 'cena' : (str_contains($lm, 'merien') ? 'merienda' : 'almuerzo'));
        try {
            $r = assistant_dispatch('comida_registrar', [
                'tipo' => $tipo,
                'nombre' => trim($message),
                'lugar' => $fuera ? 'fuera' : 'casa',
                'estado' => 'consumida',
            ], $changed, $actions);
            $reply = $fuera ? "Anoté tu {$tipo} fuera de casa; no toqué la despensa." : "Anoté tu {$tipo}.";
            if (!$fuera) {
                $ok = array_filter($r['descuentos'] ?? [], fn($d) => ($d['estado'] ?? '') === 'ok');
                $reply .= $ok
                    ? ' Descché ' . implode(', ', array_map(fn($d) => "{$d['item']} (quedan {$d['ahora']})", $ok)) . '.'
                    : ' No identifiqué ingredientes de la despensa para descontar; dime cuáles.';
            }
            $reply .= ' (modo simulado, sin IA)';
            return ['reply' => $reply, 'simulated' => true, 'actions' => $actions, 'changed' => array_values(array_unique($changed))];
        } catch (Throwable $e) {
            error_log('[ali] simulado: ' . $e->getMessage());
        }
    }

    return [
        'reply' => 'Estoy en modo simulado (sin DEEPSEEK_API_KEY). Puedo anotar comidas que ya comiste y descontarlas, pero para el resto necesito la API key configurada en el servidor.',
        'simulated' => true,
        'actions' => $actions,
        'changed' => array_values(array_unique($changed)),
    ];
}
