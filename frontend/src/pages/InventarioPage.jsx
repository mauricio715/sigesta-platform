import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Search, Trash2 } from 'lucide-react';
import { inventarioApi, lotesApi } from '../api/services';
import { useCargar } from '../hooks/useCargar';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import LoteChip, { LeyendaFefo } from '../components/ui/LoteChip';
import { AvisoError, Cargando, Vacio } from '../components/ui/Estados';
import BotonDescarga from '../components/reportes/BotonDescarga';
import { claseControl } from '../components/ui/Campo';
import { formatoCantidad } from '../utils/format';

const FILTROS = [
  { valor: 'TODOS', texto: 'Todos' },
  { valor: 'INSUMO', texto: 'Insumos' },
  { valor: 'PRODUCTO', texto: 'Productos terminados' },
];

function BarraStock({ actual, minimo }) {
  const a = Number(actual);
  const m = Number(minimo);
  if (!m) return null;
  const porcentaje = Math.min(100, (a / (m * 2)) * 100);
  const bajo = a < m;
  return (
    <div className="mt-1.5 h-1.5 w-full max-w-40 overflow-hidden rounded-full bg-concreto" aria-hidden>
      <div className={`h-full ${bajo ? 'bg-vencido' : 'bg-vigente'}`} style={{ width: `${porcentaje}%` }} />
    </div>
  );
}

export default function InventarioPage() {
  const [tipo, setTipo] = useState('TODOS');
  const [soloBajo, setSoloBajo] = useState(false);
  const [busqueda, setBusqueda] = useState('');

  const resumen = useCargar(() => inventarioApi.resumen({ tipo, solo_bajo_stock: soloBajo ? 1 : 0 }), [tipo, soloBajo]);
  const vencidos = useCargar(() => lotesApi.buscar({ estado: 'VENCIDO', per_page: 50 }), []);

  const items = useMemo(() => {
    if (!resumen.datos) return [];
    const todos = [...resumen.datos.data.insumos, ...resumen.datos.data.productos];
    const texto = busqueda.trim().toLowerCase();
    return texto ? todos.filter((i) => `${i.nombre} ${i.codigo}`.toLowerCase().includes(texto)) : todos;
  }, [resumen.datos, busqueda]);

  const lotesVencidos = (vencidos.datos?.data ?? []).filter((l) => Number(l.cantidad_actual) > 0);

  return (
    <>
      <Encabezado
        titulo="Inventario"
        descripcion="Stock utilizable por ítem y lotes vigentes en el orden en que deben salir (FEFO: primero el que vence antes)."
        acciones={<BotonDescarga ruta="reportes/inventario" formato="pdf" />}
      />

      {lotesVencidos.length > 0 && (
        <Panel titulo="Lotes vencidos con saldo" className="mb-6 ring-vencido/40">
          <p className="px-4 pt-3 text-sm text-acero">Están bloqueados para producción y despacho. Retenga el producto y registre la baja.</p>
          <ul className="flex flex-wrap gap-3 p-4">
            {lotesVencidos.map((lote) => (
              <li key={lote.id} className="flex items-center gap-2">
                <LoteChip lote={lote} unidad={(lote.insumo ?? lote.producto)?.unidad_medida} />
                <span className="text-sm text-acero">{(lote.insumo ?? lote.producto)?.nombre}</span>
                <Link
                  to={`/operaciones?tab=mermas&lote=${encodeURIComponent(lote.codigo_lote)}`}
                  className="inline-flex min-h-9 items-center gap-1 rounded px-2 text-sm font-medium text-vencido hover:bg-vencido-fondo"
                >
                  <Trash2 className="size-4" aria-hidden /> Dar de baja
                </Link>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      <Panel>
        <div className="flex flex-wrap items-center gap-3 border-b border-linea p-4">
          <div role="radiogroup" aria-label="Tipo de ítem" className="inline-flex rounded-md bg-concreto p-1">
            {FILTROS.map((f) => (
              <button
                key={f.valor}
                type="button"
                role="radio"
                aria-checked={tipo === f.valor}
                onClick={() => setTipo(f.valor)}
                className={`min-h-10 rounded px-3 text-sm font-medium ${tipo === f.valor ? 'bg-white shadow-sm ring-1 ring-linea' : 'text-acero hover:text-tinta'}`}
              >
                {f.texto}
              </button>
            ))}
          </div>

          <label className="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm">
            <input type="checkbox" className="size-4 accent-petroleo" checked={soloBajo} onChange={(e) => setSoloBajo(e.target.checked)} />
            Solo bajo stock mínimo
          </label>

          <div className="relative ml-auto w-full sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-acero" aria-hidden />
            <input
              type="search"
              className={`${claseControl} pl-9`}
              placeholder="Buscar por nombre o código"
              aria-label="Buscar ítem"
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
            />
          </div>
        </div>

        <div className="border-b border-linea px-4 py-2">
          <LeyendaFefo />
        </div>

        {resumen.cargando && !resumen.datos ? (
          <Cargando />
        ) : resumen.error ? (
          <div className="p-4">
            <AvisoError mensaje={resumen.error} alReintentar={resumen.recargar} />
          </div>
        ) : items.length === 0 ? (
          <Vacio titulo="No hay ítems con esos filtros" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[46rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  <th scope="col" className="px-4 py-3 font-medium">Ítem</th>
                  <th scope="col" className="px-4 py-3 font-medium">Stock utilizable</th>
                  <th scope="col" className="px-4 py-3 font-medium">Lotes en orden de salida</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {items.map((item) => (
                  <tr key={`${item.tipo_item}-${item.id}`} className="align-top">
                    <td className="px-4 py-4">
                      <p className="font-medium">{item.nombre}</p>
                      <p className="text-sm text-acero">
                        <span className="cifra">{item.codigo}</span> · {item.tipo_item === 'INSUMO' ? 'Insumo' : 'Producto terminado'}
                      </p>
                    </td>
                    <td className="px-4 py-4">
                      <p className={`cifra text-xl font-semibold ${item.bajo_stock_minimo ? 'text-vencido' : ''}`}>
                        {formatoCantidad(item.stock_actual, item.unidad_medida)}
                      </p>
                      <p className="text-sm text-acero">
                        Mínimo {formatoCantidad(item.stock_minimo, item.unidad_medida)}
                        {item.bajo_stock_minimo && <span className="font-medium text-vencido"> · bajo el mínimo</span>}
                      </p>
                      <BarraStock actual={item.stock_actual} minimo={item.stock_minimo} />
                    </td>
                    <td className="px-4 py-4">
                      {item.lotes_fefo.length === 0 ? (
                        <span className="text-sm text-acero">Sin lotes vigentes</span>
                      ) : (
                        <ol className="flex flex-wrap gap-2">
                          {item.lotes_fefo.map((lote, i) => (
                            <li key={lote.id}>
                              <LoteChip lote={lote} unidad={item.unidad_medida} destacado={i === 0} />
                            </li>
                          ))}
                          {item.lotes_vigentes > item.lotes_fefo.length && (
                            <li className="self-center text-sm text-acero">y {item.lotes_vigentes - item.lotes_fefo.length} más</li>
                          )}
                        </ol>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </>
  );
}
