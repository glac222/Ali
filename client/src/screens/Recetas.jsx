import { useEffect, useState } from 'react';
import { api } from '../api.js';

const TABS = ['Lo mío', 'Con lo que hay', 'Descubrir'];

function missingCount(r) {
  return (r.ingredients || []).filter((i) => i.missing).length;
}

export default function Recetas({ onOpenChat }) {
  const [recipes, setRecipes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState(TABS[0]);
  const [openRecipe, setOpenRecipe] = useState(null);

  useEffect(() => {
    api.recipes.list().then(setRecipes).finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="screen active"><div className="loading-msg">Cargando…</div></div>;

  // "Con lo que hay" = se puede cocinar ya (0 ingredientes faltantes).
  // "Descubrir" = falta comprar algo para poder hacerla.
  const shown = recipes.filter((r) => {
    if (tab === 'Con lo que hay') return missingCount(r) === 0;
    if (tab === 'Descubrir') return missingCount(r) > 0;
    return true;
  });

  return (
    <div className="screen active">
      <div className="top-row"><div className="top-title">Recetario</div><div className="avatar">{recipes.length}</div></div>

      <div className="rtabs">
        {TABS.map((t) => (
          <div key={t} className={'rtab' + (tab === t ? ' active' : '')} onClick={() => setTab(t)}>{t}</div>
        ))}
      </div>

      {shown.map((r) => {
        const miss = missingCount(r);
        return (
          <div className="recipe-card" key={r.id} onClick={() => setOpenRecipe(r)}>
            <div className="rsw" style={{ background: r.gradient }} />
            <div className="rb">
              <div className="rtitle">{r.title}</div>
              <div className="rdesc">{r.description}</div>
              <div className="rtags">
                {r.tags.map((t, i) => <span key={i} className={'pill' + (i === 1 ? ' am' : '')}>{t}</span>)}
                {miss > 0 && <span className="pill am">Falta{miss > 1 ? `n ${miss}` : ' 1'}</span>}
              </div>
            </div>
          </div>
        );
      })}
      {shown.length === 0 && (
        <div className="loading-msg">
          {tab === 'Con lo que hay'
            ? 'Ninguna receta guardada se puede hacer solo con lo que hay ahora.'
            : tab === 'Descubrir'
              ? 'Tienes lo necesario para todas tus recetas guardadas. Pídele ideas nuevas a Ali.'
              : 'Sin recetas todavía.'}
        </div>
      )}

      <div className={'overlay' + (openRecipe ? ' active' : '')}>
        {openRecipe && (
          <>
            <div className="ov-hero" style={{ background: openRecipe.gradient }}>
              <button className="ov-close" onClick={() => setOpenRecipe(null)}>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6L6 18M6 6l12 12" /></svg>
              </button>
            </div>
            <div className="ov-body">
              <h2>{openRecipe.title}</h2>
              <div className="ov-tags">
                {openRecipe.tags.map((t, i) => <span key={i} className={'pill' + (i === 1 ? ' am' : '')}>{t}</span>)}
              </div>
              <p className="ov-desc">{openRecipe.description}</p>
              <div className="cat-label" style={{ marginTop: 0 }}>Ingredientes</div>
              <div>
                {openRecipe.ingredients.map((ing, i) => (
                  <div key={i} className={'ing-row' + (ing.missing ? ' miss' : '')}>
                    <div className={'ing-dot' + (ing.missing ? ' miss' : '')} />
                    <span>{ing.text}</span>
                  </div>
                ))}
              </div>
              <div className="cat-label">Paso a paso</div>
              <div>
                {openRecipe.steps.map((s, i) => (
                  <div key={i} className="step-row">
                    <div className="step-n">{i + 1}</div>
                    <p>{s}</p>
                  </div>
                ))}
              </div>
              <button className="ai-btn" onClick={() => onOpenChat('Edita esta receta: ' + openRecipe.title)}>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
                Editar con IA
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
