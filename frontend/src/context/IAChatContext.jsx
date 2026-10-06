import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { mensajeError } from '../api/client';
import { iaApi } from '../api/services';

const IAChatContext = createContext(null);
const CLAVE = 'sigesta.chat';
const MAX_HISTORIAL = 10; // turnos enviados como contexto
const MAX_CARACTERES = 4000; // límite por mensaje que valida FastAPI

function nuevoId() {
  return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
}

function leerConversacion() {
  try {
    return JSON.parse(sessionStorage.getItem(CLAVE)) ?? [];
  } catch {
    return [];
  }
}

/**
 * Conversación compartida entre el panel flotante y la página del asistente.
 * Se guarda en sessionStorage: se pierde al cerrar la pestaña.
 */
export function IAChatProvider({ children }) {
  const [mensajes, setMensajes] = useState(leerConversacion);
  const [enviando, setEnviando] = useState(false);
  const [panelAbierto, setPanelAbierto] = useState(false);

  useEffect(() => {
    sessionStorage.setItem(CLAVE, JSON.stringify(mensajes.slice(-40)));
  }, [mensajes]);

  const enviar = useCallback(
    async (texto) => {
      const pregunta = texto.trim();
      if (!pregunta || enviando) return;

      const historial = mensajes
        .filter((m) => !m.error)
        .slice(-MAX_HISTORIAL)
        .map((m) => ({ role: m.rol === 'usuario' ? 'user' : 'assistant', content: m.contenido.slice(0, MAX_CARACTERES) }));

      setMensajes((lista) => [...lista, { id: nuevoId(), rol: 'usuario', contenido: pregunta }]);
      setEnviando(true);

      try {
        const respuesta = await iaApi.chat({ mensaje: pregunta.slice(0, 2000), historial });
        setMensajes((lista) => [
          ...lista,
          {
            id: nuevoId(),
            rol: 'asistente',
            contenido: respuesta.data.respuesta || 'El asistente no devolvió contenido.',
            herramientas: respuesta.data.herramientas_usadas ?? [],
          },
        ]);
      } catch (error) {
        setMensajes((lista) => [
          ...lista,
          {
            id: nuevoId(),
            rol: 'asistente',
            error: true,
            contenido: mensajeError(error, 'El asistente no está disponible en este momento.'),
          },
        ]);
      } finally {
        setEnviando(false);
      }
    },
    [mensajes, enviando],
  );

  const preguntar = useCallback(
    (texto) => {
      setPanelAbierto(true);
      enviar(texto);
    },
    [enviar],
  );

  const limpiar = useCallback(() => setMensajes([]), []);

  const valor = useMemo(
    () => ({ mensajes, enviando, enviar, preguntar, limpiar, panelAbierto, setPanelAbierto }),
    [mensajes, enviando, enviar, preguntar, limpiar, panelAbierto],
  );

  return <IAChatContext.Provider value={valor}>{children}</IAChatContext.Provider>;
}

export function useIAChat() {
  const contexto = useContext(IAChatContext);
  if (!contexto) throw new Error('useIAChat debe usarse dentro de <IAChatProvider>.');
  return contexto;
}
