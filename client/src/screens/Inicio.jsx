import { useEffect, useState } from 'react';
import { api } from '../api.js';
import NutritionRing from '../components/NutritionRing.jsx';

const WEEKDAY_KEYS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
const DAY_LABEL = { lunes: 'Lunes', martes: 'Martes', miercoles: 'Miércoles', jueves: 'Jueves', viernes: 'Viernes', sabado: 'Sábado', domingo: 'Domingo' };
const MEAL_TIME = { Desayuno: '6am', Almuerzo: '12pm', Merienda: '6pm' };

export default function Inicio({ onGoToPantry, onGoToPlan, onOpenChat }) {
  const [pantry, setPantry] = useState([]);
  const [nutrition, setNutrition] = useState(null);
  const [todayMeals, setTodayMeals] = useState([]);
  const [discoveries, setDiscoveries] = useState([]);
  const [loading, setLoading] = useState(true);
  const todayKey = WEEKDAY_KEYS[new Date().getDay()];

  useEffect(() => {
    Promise.all([api.pantry.list(), api.nutrition.today(), api.plan.all(), api.discoveries.list()])
      .then(([pantryData, nutritionData, planData, discData]) => {
        setPantry(pantryData);
        setNutrition(nutritionData);
        setTodayMeals(planData[todayKey] || []);
        setDiscoveries(discData);
      })
      .finally(() => setLoading(false));
  }, []);

  async function rate(id, n) {
    const updated = await api.discoveries.rate(id, n);
    setDiscoveries((d) => d.map((x) => (x.id === id ? updated : x)));
  }

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  const urgent = pantry.filter((p) => p.status === 'am' || p.status === 're');

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
        {todayMeals.map((m) => (
          <div className="mc" key={m.id}>
            <div className="msw" />
            <div className="mb">
              <div className="mk">{m.meal_type} · {MEAL_TIME[m.meal_type] || ''}</div>
              <div className="mt">{m.title}</div>
              <div className="mtime">
                {m.optional ? <span className="pill">Opcional</span> : null}
                {m.detail && <span style={{ fontSize: 9, color: 'var(--faint)' }}>{m.detail}</span>}
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="sec">
        <div className="sh"><h3>Para probar en Guayaquil</h3></div>
        <div className="hscroll">
          {discoveries.map((d) => (
            <div className="dc" key={d.id}>
              <div className="di" style={{ background: d.gradient }}>
                <span className="src">{d.source}</span>
                {d.link && (
                  <a className="go" href={d.link} target="_blank" rel="noreferrer">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#2C6348" strokeWidth="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3" /></svg>
                  </a>
                )}
              </div>
              <div className="dinfo">
                <div className="dtitle">{d.title}</div>
                <div className="stars">
                  {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} className={'star' + (n <= d.rating ? ' on' : '')} onClick={() => rate(d.id, n)}>★</button>
                  ))}
                </div>
                <div className="dmeta">{d.meta}</div>
              </div>
            </div>
          ))}
        </div>
      </div>

      <button className="ai-btn" onClick={() => onOpenChat('')}>💬 Hablar con el asistente</button>
    </div>
  );
}
