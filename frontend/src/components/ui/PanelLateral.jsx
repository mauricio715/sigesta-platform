import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';

/** Panel deslizable desde la derecha para formularios de alta y edición */
export default function PanelLateral({ abierto, titulo, descripcion, alCerrar, children, ancho = 'sm:w-[34rem]' }) {
  const panelRef = useRef(null);
  const cerrarRef = useRef(alCerrar);
  cerrarRef.current = alCerrar;

  // Depende solo de "abierto": el foco no debe saltar al primer campo en cada render
  useEffect(() => {
    if (!abierto) return undefined;
    const cerrarConEsc = (e) => e.key === 'Escape' && cerrarRef.current();
    window.addEventListener('keydown', cerrarConEsc);
    // Foco en el primer campo para empezar a escribir de inmediato
    const primero = panelRef.current?.querySelector('input, select, textarea');
    primero?.focus();
    return () => window.removeEventListener('keydown', cerrarConEsc);
  }, [abierto]);

  if (!abierto) return null;

  return (
    <>
      <div className="fixed inset-0 z-40 bg-tinta/40" onClick={alCerrar} aria-hidden />
      <aside
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="panel-lateral-titulo"
        className={`fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl ${ancho}`}
      >
        <header className="flex items-start gap-3 border-b border-linea px-5 py-4">
          <div className="flex-1">
            <h2 id="panel-lateral-titulo" className="font-cond text-2xl font-semibold">
              {titulo}
            </h2>
            {descripcion && <p className="mt-0.5 text-sm text-acero">{descripcion}</p>}
          </div>
          <button type="button" onClick={alCerrar} className="rounded p-2 text-acero hover:bg-concreto hover:text-tinta" aria-label="Cerrar">
            <X className="size-5" />
          </button>
        </header>
        <div className="flex-1 overflow-y-auto">{children}</div>
      </aside>
    </>
  );
}
