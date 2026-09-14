import db from '../db.js';

const FIXED_RULES = `REGLAS FIJAS (NUNCA ROMPER):
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
12. Cuando Gus pida añadir algo a la lista de compras, responde confirmando y añade [LISTA:nombre del producto] al final (uno por producto) para que el sistema lo guarde. No inventes productos que no pidió.`;

export const DEFAULT_LIST_PERIOD = '1 semana';

export function buildSystemPrompt() {
  const pantry = db.prepare('SELECT name, quantity, expires_label FROM pantry_items ORDER BY category, id').all();
  const pantryStr = pantry.map((p) => `${p.name} (${p.quantity}${p.expires_label ? ', ' + p.expires_label : ''})`).join(', ');

  const shoppingList = db.prepare('SELECT name, qty, checked FROM shopping_list_items WHERE period = ? ORDER BY sort_order').all(DEFAULT_LIST_PERIOD);
  const pending = shoppingList.filter((i) => !i.checked);
  const listStr = pending.length
    ? pending.map((i) => `${i.name}${i.qty > 1 ? ` (x${i.qty})` : ''}`).join(', ')
    : 'vacía';

  const planRows = db.prepare('SELECT weekday, meal_type, title FROM meal_plan ORDER BY sort_order').all();
  const byDay = {};
  for (const r of planRows) {
    byDay[r.weekday] = byDay[r.weekday] || [];
    byDay[r.weekday].push(`${r.meal_type}: ${r.title}`);
  }
  const dayLabels = { lunes: 'Lunes', martes: 'Martes', miercoles: 'Miércoles', jueves: 'Jueves', viernes: 'Viernes', sabado: 'Sábado', domingo: 'Domingo' };
  const planStr = Object.entries(byDay).map(([day, meals]) => `${dayLabels[day] || day}: ${meals.join(' | ')}`).join('. ');

  const memory = db.prepare('SELECT entry FROM meal_memory ORDER BY id DESC LIMIT 20').all();
  const memStr = memory.length
    ? '\n\nMEMORIA DE COMIDAS RECIENTES (esta semana):\n' + memory.map((m) => `- ${m.entry}`).join('\n')
    : '';

  return `Eres el asistente de cocina y nutrición personal de Gus en Guayaquil, Ecuador.${memStr}

DESPENSA ACTUAL: ${pantryStr}.

LISTA DE COMPRAS ACTUAL (${DEFAULT_LIST_PERIOD}, pendientes): ${listStr}.

PLAN SEMANAL: ${planStr}.

${FIXED_RULES}

CÓMO RESPONDER:
- Español, directo, máximo 3-4 líneas
- Si dice "hoy comí X", confirma y añade [MEMORIA:Lunes almuerzo=X]
- Si pide lista de compras, genera según período
- Si pide añadir algo a la lista de compras, confirma y añade [LISTA:producto]
- Si pide ideas para comer/peli, sugiere con despensa + opciones externas con precios
- Si pide cambio en plan, ajusta y confirma`;
}
