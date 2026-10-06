import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Bot, Maximize2, X } from 'lucide-react';
import { useIAChat } from '../../context/IAChatContext';
import IAChatPanel from './IAChatPanel';

/** Botón flotante + panel lateral deslizable con el asistente */
export default function IAChatWidget() {
  const { panelAbierto, setPanelAbierto, enviando } = useIAChat();

  useEffect(() => {
    if (!panelAbierto) return undefined;
    const cerrarConEsc = (e) => e.key === 'Escape' && setPanelAbierto(false);
    window.addEventListener('keydown', cerrarConEsc);
    return () => window.removeEventListener('keydown', cerrarConEsc);
  }, [panelAbierto, setPanelAbierto]);

  return (
    <>
      {!panelAbierto && (
        <button
          type="button"
          onClick={() => setPanelAbierto(true)}
          className="fixed bottom-5 right-5 z-40 inline-flex min-h-12 items-center gap-2 rounded-full bg-petroleo px-5 font-medium text-white shadow-lg ring-4 ring-white hover:bg-petroleo-oscuro"
        >
          <Bot className="size-5" aria-hidden />
          Asistente
          {enviando && <span className="size-2 rounded-full bg-proximo motion-safe:animate-pulse" aria-label="respondiendo" />}
        </button>
      )}

      {panelAbierto && <div className="fixed inset-0 z-40 bg-tinta/30" onClick={() => setPanelAbierto(false)} aria-hidden />}

      <aside
        role="dialog"
        aria-modal="true"
        aria-label="Asistente de auditoría"
        className={`fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-concreto shadow-2xl transition-transform duration-200 sm:w-[28rem] ${
          panelAbierto ? 'translate-x-0' : 'pointer-events-none translate-x-full'
        }`}
      >
        <header className="flex h-16 items-center gap-3 border-b border-linea bg-white px-4">
          <Bot className="size-5 text-petroleo" aria-hidden />
          <p className="flex-1 font-cond text-lg font-semibold">Asistente de auditoría</p>
          <Link
            to="/asistente"
            onClick={() => setPanelAbierto(false)}
            className="rounded p-2 text-acero hover:bg-concreto hover:text-tinta"
            aria-label="Abrir en pantalla completa"
          >
            <Maximize2 className="size-4" />
          </Link>
          <button type="button" onClick={() => setPanelAbierto(false)} className="rounded p-2 text-acero hover:bg-concreto hover:text-tinta" aria-label="Cerrar asistente">
            <X className="size-5" />
          </button>
        </header>
        {panelAbierto && <IAChatPanel autoFoco />}
      </aside>
    </>
  );
}
