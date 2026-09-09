import { useEffect, useRef, useState } from 'react';
import { api } from '../api.js';

const GREETING = { role: 'ai', content: 'Hola Gus. Puedo cambiar tu plan, anotar lo que comiste, sugerir qué comer con lo que tienes, o generar la lista de compras. ¿En qué te ayudo?' };

export default function ChatPanel({ open, onClose, autoSend, configured, onMemorySaved, onListUpdated }) {
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
      const { reply, savedMemories, addedToList } = await api.chat.send(text, historyRef.current.slice(-12));
      setMessages((m) => [...m, { role: 'ai', content: reply }]);
      historyRef.current = [...historyRef.current, { role: 'assistant', content: reply }];
      if (savedMemories?.length && onMemorySaved) onMemorySaved();
      if (addedToList?.length && onListUpdated) onListUpdated(addedToList);
    } catch (err) {
      setMessages((m) => [...m, { role: 'ai', content: 'Error de conexión con el servidor. Intenta de nuevo.' }]);
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

  return (
    <div className={'chat-panel' + (open ? ' active' : '')}>
      <div className="cp-head">
        <div>
          <div className="cp-title">Chat IA</div>
          <div className={'cp-sub' + (configured ? '' : ' sim')}>
            ● DeepSeek · {configured ? 'conectado' : 'modo simulado'}
          </div>
        </div>
        <button className="cp-close" onClick={onClose}>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6L6 18M6 6l12 12" /></svg>
        </button>
      </div>
      <div className="cp-body" ref={bodyRef}>
        {messages.map((m, i) => (
          <div key={i} className={'bubble ' + m.role}>{m.content}</div>
        ))}
        {sending && <div className="bubble loading">Pensando...</div>}
      </div>
      <div className="cp-input">
        <input
          type="text"
          placeholder="Escríbeme..."
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
