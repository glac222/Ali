import { useEffect, useState } from 'react';
import { api } from './api.js';
import Toast from './components/Toast.jsx';
import BottomNav from './components/BottomNav.jsx';
import ChatBar from './components/ChatBar.jsx';
import ChatPanel from './components/ChatPanel.jsx';
import Inicio from './screens/Inicio.jsx';
import Despensa from './screens/Despensa.jsx';
import Lista from './screens/Lista.jsx';
import Recetas from './screens/Recetas.jsx';
import Plan from './screens/Plan.jsx';
import Precios from './screens/Precios.jsx';

export default function App() {
  const [screen, setScreen] = useState('inicio');
  const [chatOpen, setChatOpen] = useState(false);
  const [autoSend, setAutoSend] = useState(null);
  const [configured, setConfigured] = useState(false);
  const [toast, setToast] = useState('');
  const [dataVersion, setDataVersion] = useState(0);

  useEffect(() => {
    api.chat.status().then((s) => setConfigured(s.configured)).catch(() => {});
  }, []);

  function showToast(msg) {
    setToast(msg);
    setTimeout(() => setToast(''), 2500);
  }

  function openChat(prefillText) {
    setChatOpen(true);
    if (prefillText) setAutoSend({ text: prefillText, nonce: Date.now() });
  }

  function changeScreen(s) {
    setScreen(s);
    setChatOpen(false);
  }

  // Ali cambió datos por su cuenta: refresca la pantalla activa y avisa.
  function handleAliActions(actions) {
    if (!actions || !actions.length) return;
    setDataVersion((v) => v + 1);
    showToast(actions.length === 1 ? `✓ ${actions[0].resumen}` : `✓ Ali hizo ${actions.length} cambios`);
  }

  return (
    <>
      <div className="device">
        <Toast message={toast} />

        <div key={dataVersion} style={{ display: 'contents' }}>
          {screen === 'inicio' && (
            <Inicio onGoToPantry={() => changeScreen('despensa')} onGoToPlan={() => changeScreen('plan')} onOpenChat={openChat} />
          )}
          {screen === 'despensa' && <Despensa onToast={showToast} />}
          {screen === 'lista' && <Lista onGoToPrecios={() => changeScreen('precios')} />}
          {screen === 'precios' && <Precios />}
          {screen === 'recetas' && <Recetas onOpenChat={openChat} />}
          {screen === 'plan' && <Plan onToast={showToast} onGoToList={() => changeScreen('lista')} onOpenChat={openChat} />}
        </div>

        <ChatPanel
          open={chatOpen}
          onClose={() => setChatOpen(false)}
          autoSend={autoSend}
          configured={configured}
          onActions={handleAliActions}
        />

        <div className="bottom-zone">
          <ChatBar onSend={openChat} />
          <BottomNav active={screen} onChange={changeScreen} />
        </div>
      </div>
    </>
  );
}
