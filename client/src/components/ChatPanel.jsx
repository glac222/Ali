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

export default function ChatPanel({ open, onClose, autoSend, configured, onActions }) {
  const [messages, setMessages] = useState([GREETING]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const bodyRef = useRef(null);
  const historyRef = useRef([]);
  const lastAutoSendRef = useRef(null);

  useEffect(() => {
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [messages, sending]);

  useEffect(() => {
    if (autoSend && autoSend.nonce !== lastAutoSendRef.current) {
      lastAutoSendRef.current = autoSend.nonce;
      sendMessage(autoSend.text);
    }
  }, [autoSend]);

  async function sendMessage(text) {
    if (!text || !text.trim() || sending) return;
    setMessages((m) => [...m, { role: 'user', content: text }]);
    historyRef.current = [...historyRef.current, { role: 'user', content: text }];
    setSending(true);
    try {
      const { reply, actions } = await api.chat.send(text, historyRef.current.slice(-12));
      setMessages((m) => [...m, { role: 'ai', content: reply, actions: actions || [] }]);
      historyRef.current = [...historyRef.current, { role: 'assistant', content: reply }];
      if (actions && actions.length && onActions) onActions(actions);
    } catch (err) {
      setMessages((m) => [...m, { role: 'ai', content: 'No pude conectar con el servidor. Intenta de nuevo.' }]);
    } finally {
      setSending(false);
    }
  }

  function submit() {
    const t = input.trim();
    if (!t) return;
    setInput('');
    sendMessage(t);
  }

  const showQuick = messages.length <= 1 && !sending;

  return (
    <div className={'chat-panel' + (open ? ' active' : '')}>
      <div className="cp-head">
        <div>
          <div className="cp-title">Ali</div>
          <div className={'cp-sub' + (configured ? '' : ' sim')}>
            ● {configured ? 'asistente activo' : 'modo simulado (sin IA)'}
          </div>
        </div>
        <button className="cp-close" onClick={onClose}>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6L6 18M6 6l12 12" /></svg>
        </button>
      </div>
      <div className="cp-body" ref={bodyRef}>
        {messages.map((m, i) => (
          <div key={i} className={'cp-msg ' + m.role}>
            <div className={'bubble ' + m.role}>{m.content}</div>
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
      <div className="cp-input">
        <input
          type="text"
          placeholder="Dime qué comiste, qué compraste, qué cambiar..."
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submit()}
        />
        <button className="cp-send" onClick={submit}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z" /></svg>
        </button>
      </div>
    </div>
  );
}
