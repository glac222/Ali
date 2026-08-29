const DEEPSEEK_URL = 'https://api.deepseek.com/chat/completions';

const SIMULATED_REPLIES = [
  'Listo, lo anoté. ¿Quieres que ajuste el plan de mañana también?',
  'Con lo que tienes puedes hacer eso sin problema. ¿Cambio algo en el plan?',
  'Perfecto. Tienes proteína para varios días — ¿genero la lista de compras de la semana?',
  'Actualizado. El hígado úsalo mañana antes de que se pase de los 2 días.',
];

export function isConfigured() {
  return Boolean(process.env.DEEPSEEK_API_KEY && process.env.DEEPSEEK_API_KEY.length > 10);
}

export async function getChatReply(systemPrompt, history) {
  if (!isConfigured()) {
    await new Promise((r) => setTimeout(r, 500));
    return { reply: SIMULATED_REPLIES[Math.floor(Math.random() * SIMULATED_REPLIES.length)], simulated: true };
  }

  const res = await fetch(DEEPSEEK_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: 'Bearer ' + process.env.DEEPSEEK_API_KEY,
    },
    body: JSON.stringify({
      model: 'deepseek-chat',
      max_tokens: 300,
      messages: [{ role: 'system', content: systemPrompt }, ...history.slice(-12)],
    }),
  });

  if (!res.ok) {
    throw new Error(`DeepSeek respondió ${res.status}`);
  }

  const data = await res.json();
  const reply = data.choices?.[0]?.message?.content || 'No pude procesar eso.';
  return { reply, simulated: false };
}
