import { useEffect, useState } from 'react';
import { api } from '../api.js';

const EMPTY_IDEA = { label: '', detail: '', place: 'casa', price: '' };
const EMPTY_OCC = { emoji: '', title: '', subtitle: '' };

// Tarjetas colapsables de "ideas para compartir" (noche de pelis, desayuno con
// alguien, cuando viene gente...). Los datos viven en la BD (tabla occasions);
// tanto Gus como Ali pueden agregar ideas. Se muestra al fondo de la pantalla Plan.
export default function Ocasiones({ onOpenChat, onToast }) {
  const [list, setList] = useState([]);
  const [loading, setLoading] = useState(true);
  const [openId, setOpenId] = useState(null);
  const [addTo, setAddTo] = useState(null); // id de la ocasión con el form de idea abierto
  const [idea, setIdea] = useState(EMPTY_IDEA);
  const [showNew, setShowNew] = useState(false);
  const [occ, setOcc] = useState(EMPTY_OCC);

  function reload() {
    return api.occasions.list().then(setList);
  }

  useEffect(() => {
    reload().finally(() => setLoading(false));
  }, []);

  async function addIdea(occasionId) {
    if (!idea.label.trim()) return;
    await api.occasions.addItem(occasionId, idea);
    setIdea(EMPTY_IDEA);
    setAddTo(null);
    await reload();
    onToast?.('✓ Idea agregada');
  }

  async function removeIdea(occasionId, itemId) {
    await api.occasions.removeItem(occasionId, itemId);
    await reload();
  }

  async function createOccasion() {
    if (!occ.title.trim()) return;
    const created = await api.occasions.create(occ);
    setOcc(EMPTY_OCC);
    setShowNew(false);
    await reload();
    setOpenId(created.id);
    onToast?.('✓ Ocasión creada');
  }

  if (loading) return null;

  return (
    <div className="oc-wrap">
      <div style={{ fontFamily: "'IBM Plex Mono',monospace", fontSize: 8.5, color: 'var(--faint)', textTransform: 'uppercase', letterSpacing: '.06em', margin: '18px 0 8px' }}>
        Para compartir
      </div>

      {list.map((o) => {
        const isOpen = openId === o.id;
        const casa = o.items.filter((i) => i.place !== 'fuera');
        const fuera = o.items.filter((i) => i.place === 'fuera');
        return (
          <div className="movie-card" key={o.id}>
            <div className="mv-head mv-toggle" onClick={() => setOpenId(isOpen ? null : o.id)}>
              <span style={{ fontSize: 17 }}>{o.emoji || '🍽️'}</span>
              <div>
                <div className="mv-title">{o.title}</div>
                {o.subtitle && <div className="mv-sub">{o.subtitle}</div>}
              </div>
              <span className={'mv-chev' + (isOpen ? ' open' : '')}>›</span>
            </div>

            {isOpen && (
              <>
                <div className="mv-items">
                  {[...casa, ...fuera].map((i) => (
                    <div className={i.place === 'fuera' ? 'mv-ext' : 'mv-item'} key={i.id}>
                      <button className="mv-x" title="Quitar" onClick={() => removeIdea(o.id, i.id)}>×</button>
                      <div className="mv-iname">{i.place === 'fuera' ? '🛵 ' : '🏠 '}{i.label}</div>
                      {i.detail && <div className="mv-idesc">{i.detail}</div>}
                      {i.price && <div className="mv-price">{i.price}</div>}
                    </div>
                  ))}
                  {o.items.length === 0 && <div className="mv-idesc" style={{ opacity: 0.5 }}>Sin ideas todavía.</div>}
                </div>

                {addTo === o.id ? (
                  <div className="mv-add">
                    <input
                      placeholder="Idea, ej. Nachos con guacamole"
                      value={idea.label}
                      onChange={(e) => setIdea({ ...idea, label: e.target.value })}
                    />
                    <input
                      placeholder="Nota corta (opcional)"
                      value={idea.detail}
                      onChange={(e) => setIdea({ ...idea, detail: e.target.value })}
                    />
                    <div className="mv-add-row">
                      <select value={idea.place} onChange={(e) => setIdea({ ...idea, place: e.target.value })}>
                        <option value="casa">En casa</option>
                        <option value="fuera">Salir / pedir</option>
                      </select>
                      <input
                        placeholder="Precio (opcional)"
                        value={idea.price}
                        onChange={(e) => setIdea({ ...idea, price: e.target.value })}
                      />
                    </div>
                    <div className="mv-add-actions">
                      <button className="mv-add-cancel" onClick={() => { setAddTo(null); setIdea(EMPTY_IDEA); }}>Cancelar</button>
                      <button className="mv-add-save" onClick={() => addIdea(o.id)}>Guardar idea</button>
                    </div>
                  </div>
                ) : (
                  <button className="mv-addbtn" onClick={() => { setAddTo(o.id); setIdea(EMPTY_IDEA); }}>＋ agregar idea</button>
                )}

                <button
                  className="mv-ask"
                  onClick={() => onOpenChat?.(`Ideas para "${o.title}": dime qué puedo hacer en casa con lo que tengo y también opciones de afuera con precio.`)}
                >
                  Pedirle ideas a Ali →
                </button>
              </>
            )}
          </div>
        );
      })}

      <button className="mv-newbtn" onClick={() => setShowNew(true)}>＋ Nueva ocasión</button>

      <div className={'modal' + (showNew ? ' active' : '')} onClick={(e) => e.target.classList.contains('modal') && setShowNew(false)}>
        <div className="modal-box">
          <h4>Nueva ocasión</h4>
          <div className="field-row">
            <div className="field" style={{ flex: '0 0 68px' }}>
              <label>Emoji</label>
              <input type="text" placeholder="🎉" value={occ.emoji} onChange={(e) => setOcc({ ...occ, emoji: e.target.value })} />
            </div>
            <div className="field">
              <label>Título</label>
              <input type="text" placeholder="ej. Cumpleaños en casa" value={occ.title} onChange={(e) => setOcc({ ...occ, title: e.target.value })} />
            </div>
          </div>
          <div className="field">
            <label>Descripción corta</label>
            <input type="text" placeholder="ej. Algo rápido para atender" value={occ.subtitle} onChange={(e) => setOcc({ ...occ, subtitle: e.target.value })} />
          </div>
          <div className="modal-actions">
            <button className="btn-s" onClick={() => setShowNew(false)}>Cancelar</button>
            <button className="btn-p" onClick={createOccasion}>Crear</button>
          </div>
        </div>
      </div>
    </div>
  );
}
