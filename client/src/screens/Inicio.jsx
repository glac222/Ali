import { useEffect, useState } from 'react';
import { api } from '../api.js';
import { mergeMeals } from '../meals.js';
import NutritionRing from '../components/NutritionRing.jsx';

const WEEKDAY_KEYS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
const DAY_LABEL = { lunes: 'Lunes', martes: 'Martes', miercoles: 'Miércoles', jueves: 'Jueves', viernes: 'Viernes', sabado: 'Sábado', domingo: 'Domingo' };
const MEAL_TIME = { Desayuno: '6am', Almuerzo: '12pm', Merienda: '6pm', Cena: '8pm' };

export default function Inicio({ onGoToPantry, onGoToPlan, onOpenChat }) {
  const [pantry, setPantry] = useState([]);
  const [nutrition, setNutrition] = useState(null);
  const [todayMeals, setTodayMeals] = useState([]);
  const [regMeals, setRegMeals] = useState([]);
  const [discoveries, setDiscoveries] = useState([]);
  const [loading, setLoading] = useState(true);
  const todayKey = WEEKDAY_KEYS[new Date().getDay()];

  useEffect(() => {
    Promise.all([api.pantry.list(), api.nutrition.today(), api.plan.all(), api.discoveries.list(), api.meals.today()])
      .then(([pantryData, nutritionData, planData, discData, mealData]) => {
        setPantry(pantryData);
        setNutrition(nutritionData);
        setTodayMeals(planData[todayKey] || []);
        setRegMeals(mealData || []);
        setDiscoveries(discData);
      })
      .finally(() => setLoading(false));
  }, []);

  const [editing, setEditing] = useState(null); // lugar en edición, o {} para uno nuevo
  const [form, setForm] = useState({});

  async function rate(id, n) {
    const updated = await api.discoveries.rate(id, n);
    setDiscoveries((d) => d.map((x) => (x.id === id ? updated : x)));
  }

  function openEdit(d) {
    setForm(d ? { ...d } : { title: '', meta: '', dish_note: '', visited: true, rating: 0, source: 'Ya lo conozco' });
    setEditing(d || {});
  }

  async function savePlace() {
    if (!form.title?.trim()) return;
    if (form.id) {
      const updated = await api.discoveries.update(form.id, form);
      setDiscoveries((d) => d.map((x) => (x.id === form.id ? updated : x)));
    } else {
      const created = await api.discoveries.create(form);
      setDiscoveries((d) => [...d, created]);
    }
    setEditing(null);
  }

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  const misLugares = discoveries.filter((d) => d.visited);
  const porProbar = discoveries.filter((d) => !d.visited);

  const urgent = pantry.filter((p) => p.status === 'am' || p.status === 're');
  const mealsToday = mergeMeals(todayMeals, regMeals);

  return (
    <div className="screen active">
      <div className="top-row">
        <div>
          <div style={{ fontFamily: "'IBM Plex Mono',monospace", fontSize: 9.5, color: 'var(--faint)' }}>
            {DAY_LABEL[todayKey]} · {new Date().toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' })}
          </div>
          <div className="top-title" style={{ marginTop: 1 }}>Buenos días, Gus</div>
        </div>
        <div className="avatar">G</div>
      </div>

      <div className="sec">
        <div className="sh"><h3>⚠ Revisar ya</h3><a onClick={onGoToPantry}>Ver despensa</a></div>
        <div className="hscroll">
          {urgent.map((p) => (
            <div key={p.id} className={'uc' + (p.status === 'am' ? ' am' : '')}>
              <div className="un">{p.name}</div>
              <div className="uw">{p.expires_label}</div>
            </div>
          ))}
          {urgent.length === 0 && <div className="uc ok"><div className="un">Todo al día</div><div className="uw">Sin urgencias</div></div>}
        </div>
      </div>

      {nutrition && (
        <div className="sec">
          <div className="sh"><h3>Tu alimentación hoy</h3></div>
          <div className="hscroll">
            <NutritionRing value={nutrition.calories} target={nutrition.calories_target} label="Calorías" unit="" color="#2C6348" />
            <NutritionRing value={nutrition.protein} target={nutrition.protein_target} label="Proteína" unit="g" color="#4A8F68" />
            <NutritionRing value={nutrition.carbs} target={nutrition.carbs_target} label="Carbos" unit="g" color="#C98A3D" />
            <NutritionRing value={nutrition.fat} target={nutrition.fat_target} label="Grasas" unit="g" color="#BE5A45" />
          </div>
        </div>
      )}

      <div className="sec">
        <div className="sh"><h3>Comidas de hoy</h3><a onClick={onGoToPlan}>Ver plan</a></div>
        {mealsToday.map((m) => (
          <div className="mc" key={m.id}>
            <div className="msw" />
            <div className="mb">
              <div className="mk">{m.kind} · {MEAL_TIME[m.kind] || ''}</div>
              <div className="mt">{m.title}</div>
              <div className="mtime">
                {m.tag ? <span className="pill">{m.tag}</span> : null}
                {m.optional ? <span className="pill">Opcional</span> : null}
                {m.detail && <span style={{ fontSize: 9, color: 'var(--faint)' }}>{m.detail}</span>}
              </div>
            </div>
          </div>
        ))}
        {mealsToday.length === 0 && <div className="plan-detail">Sin comidas para hoy todavía.</div>}
      </div>

      <div className="sec">
        <div className="sh"><h3>Mis lugares</h3><a onClick={() => openEdit(null)}>＋ lugar</a></div>
        <div className="hscroll">
          {misLugares.map((d) => (
            <div className="dc" key={d.id}>
              <div className="di" style={{ background: d.gradient }} onClick={() => openEdit(d)}>
                <span className="src">Ya fui</span>
              </div>
              <div className="dinfo">
                <div className="dtitle" onClick={() => openEdit(d)}>{d.title}</div>
                {d.dish_note && <div className="dnote">Pedir: {d.dish_note}</div>}
                <div className="stars">
                  {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} className={'star' + (n <= d.rating ? ' on' : '')} onClick={() => rate(d.id, n)}>★</button>
                  ))}
                </div>
                {d.meta && <div className="dmeta">{d.meta}</div>}
              </div>
            </div>
          ))}
          {misLugares.length === 0 && (
            <div className="dc-empty">Aún no guardas lugares que ya conozcas. Toca "＋ lugar" o dile a Ali "fui a X, pídete Y".</div>
          )}
        </div>
      </div>

      <div className="sec">
        <div className="sh"><h3>Por probar en Guayaquil</h3></div>
        <div className="hscroll">
          {porProbar.map((d) => (
            <div className="dc" key={d.id}>
              <div className="di" style={{ background: d.gradient }} onClick={() => openEdit(d)}>
                <span className="src">{d.source || 'Por probar'}</span>
                {d.link && (
                  <a className="go" href={d.link} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}>
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#2C6348" strokeWidth="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3" /></svg>
                  </a>
                )}
              </div>
              <div className="dinfo">
                <div className="dtitle" onClick={() => openEdit(d)}>{d.title}</div>
                <div className="stars">
                  {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} className={'star' + (n <= d.rating ? ' on' : '')} onClick={() => rate(d.id, n)}>★</button>
                  ))}
                </div>
                {d.meta && <div className="dmeta">{d.meta}</div>}
              </div>
            </div>
          ))}
          {porProbar.length === 0 && <div className="dc-empty">Nada pendiente por probar.</div>}
        </div>
      </div>

      <button className="ai-btn" onClick={() => onOpenChat('')}>💬 Hablar con el asistente</button>

      <div className={'modal' + (editing ? ' active' : '')} onClick={(e) => e.target.classList.contains('modal') && setEditing(null)}>
        <div className="modal-box">
          <h4>{form.id ? 'Editar lugar' : 'Nuevo lugar'}</h4>
          <div className="field">
            <label>Nombre</label>
            <input type="text" placeholder="ej. Marisquería El Puerto" value={form.title || ''} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          </div>
          <div className="field">
            <label>Zona / horario</label>
            <input type="text" placeholder="ej. Urdesa · marisco fresco" value={form.meta || ''} onChange={(e) => setForm({ ...form, meta: e.target.value })} />
          </div>
          <div className="field">
            <label>Qué pedir (nota a futuro)</label>
            <input type="text" placeholder="ej. camarones apanados, no el arroz marinero" value={form.dish_note || ''} onChange={(e) => setForm({ ...form, dish_note: e.target.value })} />
          </div>
          <label className="chk-row">
            <input type="checkbox" checked={!!form.visited} onChange={(e) => setForm({ ...form, visited: e.target.checked })} />
            Ya fui a este lugar
          </label>
          <div className="modal-actions">
            {form.id && (
              <button className="btn-s" onClick={async () => { await api.discoveries.remove(form.id); setDiscoveries((d) => d.filter((x) => x.id !== form.id)); setEditing(null); }}>
                Borrar
              </button>
            )}
            <button className="btn-s" onClick={() => setEditing(null)}>Cancelar</button>
            <button className="btn-p" onClick={savePlace}>Guardar</button>
          </div>
        </div>
      </div>
    </div>
  );
}
