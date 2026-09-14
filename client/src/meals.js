// Combina la plantilla semanal (filas de meal_plan) con las comidas fechadas
// (filas de meals: lo que Gus realmente comió o dejó planificado para un día).
// Una comida registrada pisa la fila de la plantilla de su mismo tipo; las que
// no encajan en la plantilla (cena, snack…) se añaden al final.

const ORDER = { desayuno: 0, almuerzo: 1, merienda: 2, cena: 3, snack: 4 };
const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);

export function tagFor(m) {
  if (m.status === 'planificada') return 'Planificado';
  if (m.place === 'fuera') return 'Fuera';
  if (m.place === 'comprado') return 'Comprado';
  return 'Registrado';
}

export function mergeMeals(template, dated) {
  const byType = {};
  for (const r of dated || []) {
    const k = (r.meal_type || '').toLowerCase();
    (byType[k] = byType[k] || []).push(r);
  }
  const out = (template || []).map((t) => {
    const k = (t.meal_type || '').toLowerCase();
    const reg = byType[k] && byType[k].shift();
    return reg
      ? { id: 'r' + reg.id, kind: t.meal_type, title: reg.name, detail: t.detail, optional: 0, tag: tagFor(reg) }
      : { id: 't' + t.id, kind: t.meal_type, title: t.title, detail: t.detail, optional: t.optional, tag: null };
  });
  for (const k of Object.keys(byType)) {
    for (const reg of byType[k]) {
      out.push({ id: 'r' + reg.id, kind: cap(k), title: reg.name, detail: '', optional: 0, tag: tagFor(reg) });
    }
  }
  return out.sort((a, b) => (ORDER[a.kind.toLowerCase()] ?? 9) - (ORDER[b.kind.toLowerCase()] ?? 9));
}

export function toISODate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
