<?php
declare(strict_types=1);

/**
 * Cliente de chat IA (DeepSeek) + armado del system prompt.
 * Portado desde server/src/services/{deepseek,systemPrompt}.js
 */

const DEEPSEEK_URL = 'https://api.deepseek.com/chat/completions';

const SIMULATED_REPLIES = [
    'Listo, lo anoté. ¿Quieres que ajuste el plan de mañana también?',
    'Con lo que tienes puedes hacer eso sin problema. ¿Cambio algo en el plan?',
    'Perfecto. Tienes proteína para varios días — ¿genero la lista de compras de la semana?',
    'Actualizado. El hígado úsalo mañana antes de que se pase de los 2 días.',
];

const FIXED_RULES = <<<'TXT'
REGLAS FIJAS (NUNCA ROMPER):
1. Solo freír/hervir/abrir latas — sin sopas, sin hornear, sin recetas complicadas
2. Porciones triples — no cuestionar
3. Arroz NO obligatorio — puede ser proteína+ensalada+otro carbohidrato (choclo, puré)
4. Encebollado: solo domingos desayuno, comprado hecho
5. Puré siempre con arroz, nunca solo
6. Menestra y ensalada no van juntos en el mismo plato
7. Sardinas nunca en desayuno
8. Hígado sin cebolla encima
9. Sin patacones en casa (tiempo)
10. Desayuno es OPCIONAL — batido+sándwiches es opción rápida, no obligatoria
11. Cuando registres comidas que Gus menciona, responde confirmando y añade [MEMORIA:descripción corta] al final para que el sistema la guarde
12. Cuando Gus pida añadir algo a la lista de compras, responde confirmando y añade [LISTA:nombre del producto] al final (uno por producto) para que el sistema lo guarde. No inventes productos que no pidió.
TXT;

const DEFAULT_LIST_PERIOD = '1 semana';

function chat_is_configured(): bool
{
    $key = env('DEEPSEEK_API_KEY', '');
    return $key !== null && strlen($key) > 10;
}

function build_system_prompt(): string
{
    $pantry = q_all('SELECT name, quantity, expires_label FROM pantry_items ORDER BY category, id');
    $pantryStr = implode(', ', array_map(function ($p) {
        $extra = $p['expires_label'] !== '' ? ', ' . $p['expires_label'] : '';
        return "{$p['name']} ({$p['quantity']}{$extra})";
    }, $pantry));

    $listRows = q_all('SELECT name, qty, checked FROM shopping_list_items WHERE period = ? ORDER BY sort_order', [DEFAULT_LIST_PERIOD]);
    $pending = array_values(array_filter($listRows, fn($i) => !((bool) $i['checked'])));
    $listStr = $pending
        ? implode(', ', array_map(fn($i) => $i['name'] . ((int) $i['qty'] > 1 ? " (x{$i['qty']})" : ''), $pending))
        : 'vacía';

    $planRows = q_all('SELECT weekday, meal_type, title FROM meal_plan ORDER BY sort_order');
    $byDay = [];
    foreach ($planRows as $r) {
        $byDay[$r['weekday']][] = "{$r['meal_type']}: {$r['title']}";
    }
    $dayLabels = [
        'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
        'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
    ];
    $planParts = [];
    foreach ($byDay as $day => $meals) {
        $label = $dayLabels[$day] ?? $day;
        $planParts[] = $label . ': ' . implode(' | ', $meals);
    }
    $planStr = implode('. ', $planParts);

    $memory = q_all('SELECT entry FROM meal_memory ORDER BY id DESC LIMIT 20');
    $memStr = '';
    if ($memory) {
        $lines = implode("\n", array_map(fn($m) => '- ' . $m['entry'], $memory));
        $memStr = "\n\nMEMORIA DE COMIDAS RECIENTES (esta semana):\n" . $lines;
    }

    $rules = FIXED_RULES;

    return <<<TXT
Eres el asistente de cocina y nutrición personal de Gus en Guayaquil, Ecuador.{$memStr}

DESPENSA ACTUAL: {$pantryStr}.

LISTA DE COMPRAS ACTUAL (esta semana, pendientes): {$listStr}.

PLAN SEMANAL: {$planStr}.

{$rules}

CÓMO RESPONDER:
- Español, directo, máximo 3-4 líneas
- Si dice "hoy comí X", confirma y añade [MEMORIA:Lunes almuerzo=X]
- Si pide lista de compras, genera según período
- Si pide añadir algo a la lista de compras, confirma y añade [LISTA:producto]
- Si pide ideas para comer/peli, sugiere con despensa + opciones externas con precios
- Si pide cambio en plan, ajusta y confirma
TXT;
}

/**
 * @param array<int,array{role:string,content:string}> $history
 * @return array{reply:string,simulated:bool}
 */
function get_chat_reply(string $systemPrompt, array $history): array
{
    if (!chat_is_configured()) {
        usleep(400_000);
        return ['reply' => SIMULATED_REPLIES[array_rand(SIMULATED_REPLIES)], 'simulated' => true];
    }

    $trimmed = array_slice($history, -12);
    $messages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        array_map(fn($m) => ['role' => $m['role'], 'content' => (string) $m['content']], $trimmed)
    );

    $payload = json_encode([
        'model' => 'deepseek-chat',
        'max_tokens' => 300,
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(DEEPSEEK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
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
        throw new RuntimeException("DeepSeek respondió {$code}");
    }

    $data = json_decode((string) $res, true);
    $reply = $data['choices'][0]['message']['content'] ?? 'No pude procesar eso.';
    return ['reply' => (string) $reply, 'simulated' => false];
}
