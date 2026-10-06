import { useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import { lotesApi } from '../../api/services';
import { mensajeError } from '../../api/client';
import Boton from '../ui/Boton';
import LoteChip from '../ui/LoteChip';
import { claseControl } from '../ui/Campo';

/**
 * Busca lotes por código (interno o del proveedor) y permite elegir uno.
 * Si recibe codigoInicial (p. ej. desde una alerta), busca automáticamente.
 */
export default function BuscadorLote({ id = 'buscar-lote', codigoInicial = '', alSeleccionar, filtros = {}, etiqueta = 'Código de lote' }) {
  const [texto, setTexto] = useState(codigoInicial);
  const [resultados, setResultados] = useState(null);
  const [buscando, setBuscando] = useState(false);
  const [error, setError] = useState('');

  const buscar = async (valor = texto) => {
    const codigo = valor.trim();
    if (!codigo) return;
    setBuscando(true);
    setError('');

    try {
      const respuesta = await lotesApi.buscar({ buscar: codigo, per_page: 20, ...filtros });
      setResultados(respuesta.data);
      const exacto = respuesta.data.find((l) => l.codigo_lote.toLowerCase() === codigo.toLowerCase());
      if (exacto) alSeleccionar(exacto);
    } catch (e) {
      setError(mensajeError(e));
    } finally {
      setBuscando(false);
    }
  };

  useEffect(() => {
    if (codigoInicial) buscar(codigoInicial);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [codigoInicial]);

  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
        {etiqueta}
      </label>
      <div className="flex gap-2">
        <input
          id={id}
          className={claseControl}
          value={texto}
          onChange={(e) => setTexto(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              buscar();
            }
          }}
          placeholder="Escriba o escanee el código"
          autoComplete="off"
        />
        <Boton variante="secundario" icono={Search} cargando={buscando} onClick={() => buscar()}>
          Buscar
        </Boton>
      </div>

      {error && <p className="mt-2 text-sm text-vencido">{error}</p>}

      {resultados && resultados.length === 0 && <p className="mt-2 text-sm text-acero">No se encontraron lotes con ese código.</p>}

      {resultados && resultados.length > 1 && (
        <ul className="mt-3 space-y-2" aria-label="Lotes encontrados">
          {resultados.map((lote) => {
            const item = lote.insumo ?? lote.producto;
            return (
              <li key={lote.id}>
                <button
                  type="button"
                  onClick={() => alSeleccionar(lote)}
                  className="flex w-full flex-wrap items-center gap-3 rounded-md px-2 py-2 text-left hover:bg-concreto"
                >
                  <LoteChip lote={lote} unidad={item?.unidad_medida} />
                  <span className="text-sm text-acero">{item?.nombre}</span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
