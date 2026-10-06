import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';

const ToastContext = createContext(null);

export function ToastProvider({ children }) {
  const [avisos, setAvisos] = useState([]);

  const cerrar = useCallback((id) => setAvisos((lista) => lista.filter((a) => a.id !== id)), []);

  const notificar = useCallback(
    (texto, tipo = 'exito') => {
      const id = `${Date.now()}-${Math.random()}`;
      setAvisos((lista) => [...lista.slice(-3), { id, texto, tipo }]);
      setTimeout(() => cerrar(id), tipo === 'error' ? 8000 : 5000);
    },
    [cerrar],
  );

  const valor = useMemo(() => ({ notificar }), [notificar]);

  return (
    <ToastContext.Provider value={valor}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed bottom-4 left-4 z-[70] flex w-[min(26rem,calc(100vw-2rem))] flex-col gap-2">
        {avisos.map((aviso) => {
          const error = aviso.tipo === 'error';
          const Icono = error ? CircleAlert : CircleCheck;
          return (
            <div
              key={aviso.id}
              role={error ? 'alert' : 'status'}
              className={`pointer-events-auto flex items-start gap-3 rounded-md border-l-4 bg-white px-4 py-3 shadow-lg ring-1 ring-linea ${
                error ? 'border-vencido' : 'border-vigente'
              }`}
            >
              <Icono className={`mt-0.5 size-5 shrink-0 ${error ? 'text-vencido' : 'text-vigente'}`} aria-hidden />
              <p className="flex-1 text-sm">{aviso.texto}</p>
              <button type="button" onClick={() => cerrar(aviso.id)} className="text-acero hover:text-tinta" aria-label="Cerrar aviso">
                <X className="size-4" />
              </button>
            </div>
          );
        })}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const contexto = useContext(ToastContext);
  if (!contexto) throw new Error('useToast debe usarse dentro de <ToastProvider>.');
  return contexto;
}
