import { useEffect, useRef, useState } from 'react';
import { api } from '../api.js';

const GREETING = {
  role: 'ai',
  content:
    'Hola, soy Ali. Manejo tu despensa, comidas, plan y lista de compras. Dime qué comiste y lo descuento, pregúntame qué cocinar con lo que tienes, o pídeme la lista de la semana.',
};

const QUICK = [
  '¿Qué cocino hoy con lo que tengo?',
  '¿Qué se está por vencer?',
  'Arma la lista de compras de la semana',
  'Muéstrame la despensa',
];

function fmtWhen(ts) {
  if (!ts) return '';
  const d = new Date(String(ts).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('es-EC', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export default function ChatPanel({ open, onClose, autoSend, configured, onActions }) {
  const [messages, setMessages] = useState([GREETING]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [view, setView] = useState('chat'); // 'chat' | 'log'
  const [events, setEvents] = useState(null);
  const [loadingEvents, setLoadingEvents] = useState(false);
  const bodyRef = useRef(null);
  const historyRef = useRef([]);
  const lastAutoSendRef = useRef(null);
  const hydratedRef = useRef(false);
  const startedRef = useRef(false);

  useEffect(() => {
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [messages, sending, view]);

  // Rehidrata la conversación guardada la primera vez que se abre el panel.
  useEffect(() => {
    if (!open || hydratedRef.current) return;
    hydratedRef.current = true;
    api.chat
      .history()
      .then((rows) => {
        if (startedRef.current) return; // el usuario ya empezó a chatear; no pisar
        const clean = (rows || []).filter(
          (r) => (r.role === 'user' || r.role === 'assistant') && r.content && !String(r.content).startsWith('[error')
        );
        if (!clean.length) return;
        historyRef.current = clean.map((r) => ({ role: r.role, content: r.content }));
        setMessages([
          GREETING,
          ...clean.map((r) => ({ role: r.role === 'assistant' ? 'ai' : 'user', content: r.content })),
        ]);
      })
      .catch(() => {});
  }, [open]);

  useEffect(() => {
    if (autoSend && autoSend.nonce !== lastAutoSendRef.current) {
      lastAutoSendRef.current = autoSend.nonce;
      setView('chat');
      sendMessage(autoSend.text);
    }
  }, [autoSend]);

  async function loadEvents() {
    setLoadingEvents(true);
    try {
      setEvents(await api.chat.events());
    } catch {
      setEvents([]);
    } finally {
      setLoadingEvents(false);
    }
  }

  function toggleLog() {
    const next = view === 'log' ? 'chat' : 'log';
    setView(next);
    if (next === 'log') loadEvents();
  }

  async function sendMessage(text) {
    if (!text || !text.trim() || sending) return;
    startedRef.current = true;
    setView('chat');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    historyRef.current = [...historyRef.current, { role: 'user', content: text }];
    setSending(true);
    try {
      const { reply, actions } = await api.chat.send(text, historyRef.current.slice(-12));
      setMessages((m) => [...m, { role: 'ai', content: reply, actions: actions || [] }]);
      historyRef.current = [...historyRef.current, { role: 'assistant', content: reply }];
      if (actions && actions.length && onActions) onActions(actions);
    } catch (err) {
      const msg = err && err.message ? err.message : 'No pude conectar con el servidor. Intenta de nuevo.';
      setMessages((m) => [...m, { role: 'ai', content: msg, error: true }]);
    } finally {
      setSending(false);
    }
  }

  function submit() {
    const t = input.trim();
    if (!t || sending) return;
    setInput('');
    sendMessage(t);
  }

  const showQuick = view === 'chat' && messages.length <= 1 && !sending;

  return (
    <div className={'chat-panel' + (open ? ' active' : '')}>
      <div className="cp-head">
        <div>
          <div className="cp-title">Ali</div>
          <div className={'cp-sub' + (configured ? '' : ' sim')}>
            ● {configured ? 'asistente activo' : 'modo simulado (sin IA)'}
          </div>
        </div>
        <div className="cp-head-actions">
          <button
            className={'cp-log-btn' + (view === 'log' ? ' on' : '')}
            onClick={toggleLog}
            title="Actividad de Ali"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 8v4l3 3" /><circle cx="12" cy="12" r="9" /></svg>
          </button>
          <button className="cp-close" onClick={onClose}>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6L6 18M6 6l12 12" /></svg>
          </button>
        </div>
      </div>

      {view === 'log' ? (
        <div className="cp-body" ref={bodyRef}>
          <div className="cp-log-title">Lo que Ali ha cambiado</div>
          {loadingEvents && <div className="bubble loading">Cargando…</div>}
          {!loadingEvents && events && events.length === 0 && (
            <div className="plan-detail">Ali todavía no ha hecho cambios.</div>
          )}
          {!loadingEvents &&
            (events || []).map((e) => (
              <div key={e.id} className="cp-log-row">
                <div className="cp-log-sum">{e.summary || e.tool}</div>
                <div className="cp-log-meta">{e.tool} · {fmtWhen(e.created_at)}</div>
              </div>
            ))}
        </div>
      ) : (
        <div className="cp-body" ref={bodyRef}>
          {messages.map((m, i) => (
            <div key={i} className={'cp-msg ' + m.role}>
              <div className={'bubble ' + m.role + (m.error ? ' error' : '')}>{m.content}</div>
              {m.actions && m.actions.length > 0 && (
                <div className="cp-actions">
                  {m.actions.map((a, j) => (
                    <div key={j} className="cp-action">✓ {a.resumen}</div>
                  ))}
                </div>
              )}
            </div>
          ))}
          {sending && <div className="bubble loading">Ali está trabajando…</div>}
          {showQuick && (
            <div className="cp-quick">
              {QUICK.map((q) => (
                <button key={q} className="chip" onClick={() => sendMessage(q)}>{q}</button>
              ))}
            </div>
          )}
        </div>
      )}

      <div className="cp-input">
        <input
          type="text"
          placeholder={sending ? 'Ali está trabajando…' : 'Dime qué comiste, qué compraste, qué cambiar...'}
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submit()}
          disabled={sending}
        />
        <button className="cp-send" onClick={submit} disabled={sending}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z" /></svg>
        </button>
      </div>
    </div>
  );
}
