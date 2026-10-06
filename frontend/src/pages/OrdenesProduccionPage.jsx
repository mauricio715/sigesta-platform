import { Fragment, useState } from 'react';
import { ChevronDown, ChevronRight, Filter, RotateCcw } from 'lucide-react';
import { catalogoApi, historialApi } from '../api/services';
import { useCargar } from '../hooks/useCargar';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import Boton from '../components/ui/Boton';
import Paginacion from '../components/ui/Paginacion';
import LoteChip from '../components/ui/LoteChip';
import { Campo, Entrada, Selector } from '../components/ui/Campo';
import { AvisoError, Cargando, Vacio } from '../components/ui/Estados';
import { formatoCantidad, formatoFechaHora, hoyISO } from '../utils/format';

const FILTROS_INICIALES = { fecha_inicio: hoyISO(-30), fecha_fin: hoyISO(), producto_id: '', buscar: '' };

function limpiar(filtros) {
  return Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== ''));
}

export default function OrdenesProduccionPage() {
  const [form, setForm] = useState(FILTROS_INICIALES);
  const [consulta, setConsulta] = useState({ params: limpiar(FILTROS_INICIALES), pagina: 1 });
  const [abiertos, setAbiertos] = useState([]);

  const productos = useCargar(() => catalogoApi.productos(), []);
  const lista = useCargar(() => historialApi.ordenes({ ...consulta.params, page: consulta.pagina, per_page: 20 }), [consulta]);

  const cambiar = (campo) => (e) => setForm((f) => ({ ...f, [campo]: e.target.value }));
  const alternar = (id) => setAbiertos((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
  const ordenes = lista.datos?.data ?? [];

  return (
    <>
      <Encabezado
        titulo="Órdenes de producción"
        descripcion="Cada orden muestra la receta usada, el lote que generó y los lotes de insumo que consumió por FEFO."
      />

      <Panel className="mb-6">
        <form
          className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4"
          onSubmit={(e) => {
            e.preventDefault();
            setConsulta({ params: limpiar(form), pagina: 1 });
          }}
        >
          <Campo id="o-inicio" etiqueta="Desde">
            <Entrada id="o-inicio" type="date" value={form.fecha_inicio} onChange={cambiar('fecha_inicio')} max={form.fecha_fin || undefined} />
          </Campo>
          <Campo id="o-fin" etiqueta="Hasta">
            <Entrada id="o-fin" type="date" value={form.fecha_fin} onChange={cambiar('fecha_fin')} min={form.fecha_inicio || undefined} />
          </Campo>
          <Campo id="o-producto" etiqueta="Producto">
            <Selector id="o-producto" value={form.producto_id} onChange={cambiar('producto_id')}>
              <option value="">Todos</option>
              {(productos.datos?.data ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.nombre}
                </option>
              ))}
            </Selector>
          </Campo>
          <Campo id="o-buscar" etiqueta="Código de orden">
            <Entrada id="o-buscar" value={form.buscar} onChange={cambiar('buscar')} placeholder="OP-…" autoComplete="off" />
          </Campo>
          <div className="flex gap-2 sm:col-span-2 lg:col-span-4">
            <Boton type="submit" icono={Filter}>
              Aplicar filtros
            </Boton>
            <Boton
              variante="secundario"
              icono={RotateCcw}
              onClick={() => {
                setForm(FILTROS_INICIALES);
                setConsulta({ params: limpiar(FILTROS_INICIALES), pagina: 1 });
              }}
            >
              Restablecer
            </Boton>
          </div>
        </form>
      </Panel>

      <Panel>
        {lista.cargando && !lista.datos ? (
          <Cargando />
        ) : lista.error ? (
          <div className="p-4">
            <AvisoError mensaje={lista.error} alReintentar={lista.recargar} />
          </div>
        ) : ordenes.length === 0 ? (
          <Vacio titulo="No hay órdenes con esos filtros" />
        ) : (
          <div className={`overflow-x-auto ${lista.cargando ? 'opacity-60' : ''}`}>
            <table className="w-full min-w-[52rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  <th scope="col" className="w-10 px-2 py-3"><span className="sr-only">Detalle</span></th>
                  <th scope="col" className="px-4 py-3 font-medium">Orden</th>
                  <th scope="col" className="px-4 py-3 font-medium">Producto y receta</th>
                  <th scope="col" className="px-4 py-3 text-right font-medium">Producido</th>
                  <th scope="col" className="px-4 py-3 font-medium">Lote generado</th>
                  <th scope="col" className="px-4 py-3 font-medium">Responsable</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {ordenes.map((o) => {
                  const abierto = abiertos.includes(o.id);
                  return (
                    <Fragment key={o.id}>
                      <tr className="align-top">
                        <td className="px-2 py-3">
                          <button
                            type="button"
                            onClick={() => alternar(o.id)}
                            aria-expanded={abierto}
                            aria-label={`${abierto ? 'Ocultar' : 'Ver'} insumos consumidos en ${o.codigo_orden}`}
                            className="rounded p-1.5 hover:bg-concreto"
                          >
                            {abierto ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                          </button>
                        </td>
                        <td className="px-4 py-3">
                          <p className="cifra whitespace-nowrap font-semibold">{o.codigo_orden}</p>
                          <p className="text-sm text-acero">{formatoFechaHora(o.fecha_produccion)}</p>
                        </td>
                        <td className="px-4 py-3">
                          <p>{o.producto?.nombre}</p>
                          <p className="text-sm text-acero">{o.receta?.nombre_receta}</p>
                        </td>
                        <td className="cifra px-4 py-3 text-right font-semibold">{formatoCantidad(o.cantidad_producida, o.producto?.unidad_medida)}</td>
                        <td className="px-4 py-3">{o.lote_producto && <LoteChip lote={o.lote_producto} unidad={o.producto?.unidad_medida} />}</td>
                        <td className="px-4 py-3 text-sm">{o.responsable?.name}</td>
                      </tr>
                      {abierto && (
                        <tr className="bg-concreto/40">
                          <td />
                          <td colSpan={5} className="px-4 py-3">
                            <p className="mb-2 text-sm font-medium">Insumos consumidos</p>
                            <ul className="space-y-2">
                              {o.consumos.map((c) => (
                                <li key={c.lote.id} className="flex flex-wrap items-center gap-3">
                                  <span className="w-40 text-sm">{c.insumo.nombre}</span>
                                  <LoteChip lote={c.lote} mostrarCantidad={false} />
                                  <span className="cifra text-sm">{formatoCantidad(c.cantidad_consumida, c.insumo.unidad_medida)}</span>
                                </li>
                              ))}
                            </ul>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <Paginacion meta={lista.datos?.meta} alCambiar={(pagina) => setConsulta((c) => ({ ...c, pagina }))} />
      </Panel>
    </>
  );
}
