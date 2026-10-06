import { useCallback, useEffect, useRef, useState } from 'react';
import { mensajeError } from '../api/client';

/**
 * Carga datos de la API y expone { datos, cargando, error, recargar }.
 * Ignora respuestas de peticiones antiguas si las dependencias cambian.
 */
export function useCargar(cargador, dependencias = []) {
  const [estado, setEstado] = useState({ datos: null, cargando: true, error: null });
  const ultimaPeticion = useRef(0);

  const ejecutar = useCallback(async () => {
    const id = ++ultimaPeticion.current;
    setEstado((previo) => ({ ...previo, cargando: true, error: null }));

    try {
      const datos = await cargador();
      if (id === ultimaPeticion.current) setEstado({ datos, cargando: false, error: null });
    } catch (error) {
      if (id === ultimaPeticion.current) {
        setEstado((previo) => ({ ...previo, cargando: false, error: mensajeError(error) }));
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, dependencias);

  useEffect(() => {
    ejecutar();
  }, [ejecutar]);

  return { ...estado, recargar: ejecutar };
}
