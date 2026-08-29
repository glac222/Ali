import { useState } from 'react';

export default function ChatBar({ onSend }) {
  const [text, setText] = useState('');

  function submit() {
    const t = text.trim();
    if (!t) return;
    setText('');
    onSend(t);
  }

  return (
    <div className="chat-bar">
      <input
        type="text"
        placeholder="Dime qué comiste, qué quieres cambiar..."
        value={text}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={(e) => e.key === 'Enter' && submit()}
      />
      <button className="chat-send-btn" onClick={submit}>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z" /></svg>
      </button>
    </div>
  );
}
