import { Fragment, useState } from 'react';
import { ChevronDown, ChevronRight, Filter, RotateCcw, Undo2 } from 'lucide-react';
import { anulacionesApi, catalogoApi, historialApi } from '../api/services';
import { erroresDeCampo, mensajeError } from '../api/client';
import { useCargar } from '../hooks/useCargar';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import Boton from '../components/ui/Boton';
import Paginacion from '../components/ui/Paginacion';
import LoteChip from '../components/ui/LoteChip';
import ConfirmarConMotivo from '../components/ui/ConfirmarConMotivo';
import BotonDescarga from '../components/reportes/BotonDescarga';
import { Campo, Entrada, Selector } from '../components/ui/Campo';
import { AvisoError, Cargando, Vacio } from '../components/ui/Estados';
import { HORAS_ANULACION, dentroDePlazo, formatoBs, formatoCantidad, formatoFechaHora, hoyISO } from '../utils/format';

const FILTROS_INICIALES = { fecha_inicio: hoyISO(-30), fecha_fin: hoyISO(), cliente_id: '', estado: '', buscar: '' };

function limpiar(filtros) {
  return Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== ''));
}

export default function DespachosPage() {
  const { esAdmin } = useAuth();
  const { notificar } = useToast();
  const [form, setForm] = useState(FILTROS_INICIALES);
  const [consulta, setConsulta] = useState({ params: limpiar(FILTROS_INICIALES), pagina: 1 });
  const [abiertos, setAbiertos] = useState([]);
  const [aAnular, setAAnular] = useState(null);
  const [anulando, setAnulando] = useState(false);
  const [errorMotivo, setErrorMotivo] = useState('');

  const clientes = useCargar(() => catalogoApi.clientes(), []);
  const lista = useCargar(() => historialApi.despachos({ ...consulta.params, page: consulta.pagina, per_page: 20 }), [consulta]);

  const cambiar = (campo) => (e) => setForm((f) => ({ ...f, [campo]: e.target.value }));
  const alternar = (id) => setAbiertos((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));

  const anular = async (motivo) => {
    setAnulando(true);
    setErrorMotivo('');
    try {
      const respuesta = await anulacionesApi.despacho(aAnular.id, motivo);
      notificar(respuesta.message);
      setAAnular(null);
      lista.recargar();
    } catch (e) {
      setErrorMotivo(erroresDeCampo(e).motivo ?? '');
      notificar(mensajeError(e), 'error');
    } finally {
      setAnulando(false);
    }
  };

  const despachos = lista.datos?.data ?? [];

  return (
    <>
      <Encabezado titulo="Historial de despachos" descripcion="Qué se entregó, a quién, cuándo y de qué lote. Los despachos anulados se conservan con su motivo." />

      <Panel className="mb-6">
        <form
          className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-5"
          onSubmit={(e) => {
            e.preventDefault();
            setConsulta({ params: limpiar(form), pagina: 1 });
          }}
        >
          <Campo id="d-inicio" etiqueta="Desde">
            <Entrada id="d-inicio" type="date" value={form.fecha_inicio} onChange={cambiar('fecha_inicio')} max={form.fecha_fin || undefined} />
          </Campo>
          <Campo id="d-fin" etiqueta="Hasta">
            <Entrada id="d-fin" type="date" value={form.fecha_fin} onChange={cambiar('fecha_fin')} min={form.fecha_inicio || undefined} />
          </Campo>
          <Campo id="d-cliente" etiqueta="Cliente">
            <Selector id="d-cliente" value={form.cliente_id} onChange={cambiar('cliente_id')}>
              <option value="">Todos</option>
              {(clientes.datos?.data ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.razon_social}
                </option>
              ))}
            </Selector>
          </Campo>
          <Campo id="d-estado" etiqueta="Estado">
            <Selector id="d-estado" value={form.estado} onChange={cambiar('estado')}>
              <option value="">Todos</option>
              <option value="COMPLETADO">Completados</option>
              <option value="ANULADO">Anulados</option>
            </Selector>
          </Campo>
          <Campo id="d-buscar" etiqueta="Código">
            <Entrada id="d-buscar" value={form.buscar} onChange={cambiar('buscar')} placeholder="DSP-…" autoComplete="off" />
          </Campo>
          <div className="flex gap-2 sm:col-span-2 lg:col-span-5">
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
            <BotonDescarga
              ruta="reportes/despachos"
              formato="xlsx"
              params={Object.fromEntries(Object.entries(consulta.params).filter(([clave]) => clave !== 'buscar'))}
            />
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
        ) : despachos.length === 0 ? (
          <Vacio titulo="No hay despachos con esos filtros" />
        ) : (
          <div className={`overflow-x-auto ${lista.cargando ? 'opacity-60' : ''}`}>
            <table className="w-full min-w-[52rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  <th scope="col" className="w-10 px-2 py-3"><span className="sr-only">Detalle</span></th>
                  <th scope="col" className="px-4 py-3 font-medium">Despacho</th>
                  <th scope="col" className="px-4 py-3 font-medium">Cliente</th>
                  <th scope="col" className="px-4 py-3 font-medium">Productos</th>
                  <th scope="col" className="px-4 py-3 text-right font-medium">Total</th>
                  <th scope="col" className="px-4 py-3 font-medium">Estado</th>
                  <th scope="col" className="px-4 py-3"><span className="sr-only">Acciones</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {despachos.map((d) => {
                  const abierto = abiertos.includes(d.id);
                  const anulado = d.estado === 'ANULADO';
                  const productos = [...new Set(d.detalles.map((x) => x.producto.nombre))];
                  const puedeAnular = esAdmin && !anulado && dentroDePlazo(d.registrado_at);
                  return (
                    <Fragment key={d.id}>
                      <tr className={`align-top ${anulado ? 'text-acero' : ''}`}>
                        <td className="px-2 py-3">
                          <button
                            type="button"
                            onClick={() => alternar(d.id)}
                            aria-expanded={abierto}
                            aria-label={`${abierto ? 'Ocultar' : 'Ver'} lotes de ${d.codigo_despacho}`}
                            className="rounded p-1.5 hover:bg-concreto"
                          >
                            {abierto ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                          </button>
                        </td>
                        <td className="px-4 py-3">
                          <p className={`cifra whitespace-nowrap font-semibold ${anulado ? 'line-through' : ''}`}>{d.codigo_despacho}</p>
                          <p className="text-sm text-acero">{formatoFechaHora(d.fecha_despacho)}</p>
                        </td>
                        <td className="px-4 py-3">
                          <p>{d.cliente?.razon_social}</p>
                          <p className="text-sm text-acero">{d.responsable?.name}</p>
                        </td>
                        <td className="px-4 py-3 text-sm">{productos.join(', ')}</td>
                        <td className="cifra whitespace-nowrap px-4 py-3 text-right font-semibold">{d.total ? formatoBs(d.total) : '—'}</td>
                        <td className="px-4 py-3">
                          <span
                            className={`rounded px-2 py-0.5 text-sm font-semibold ${
                              anulado ? 'bg-vencido-fondo text-vencido' : 'bg-vigente-fondo text-vigente'
                            }`}
                          >
                            {anulado ? 'Anulado' : 'Completado'}
                          </span>
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 text-right">
                          {puedeAnular && (
                            <button
                              type="button"
                              onClick={() => {
                                setErrorMotivo('');
                                setAAnular(d);
                              }}
                              className="inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-vencido hover:bg-vencido-fondo"
                            >
                              <Undo2 className="size-4" aria-hidden /> Anular
                            </button>
                          )}
                        </td>
                      </tr>
                      {abierto && (
                        <tr className="bg-concreto/40">
                          <td />
                          <td colSpan={6} className="px-4 py-3">
                            <ul className="space-y-2">
                              {d.detalles.map((x) => (
                                <li key={x.lote.id} className="flex flex-wrap items-center gap-3">
                                  <span className="w-44 text-sm">{x.producto.nombre}</span>
                                  <LoteChip lote={x.lote} mostrarCantidad={false} />
                                  <span className="cifra text-sm">{formatoCantidad(x.cantidad, x.producto.unidad_medida)}</span>
                                  {x.subtotal && <span className="cifra text-sm text-acero">{formatoBs(x.subtotal)}</span>}
                                </li>
                              ))}
                            </ul>
                            {d.observaciones && <p className="mt-3 text-sm text-acero">Observaciones: {d.observaciones}</p>}
                            {anulado && d.anulacion && (
                              <p className="mt-3 rounded-md bg-vencido-fondo px-3 py-2 text-sm text-vencido">
                                Anulado el {formatoFechaHora(d.anulacion.fecha)} por {d.anulacion.responsable}: {d.anulacion.motivo}
                              </p>
                            )}
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

      <ConfirmarConMotivo
        abierto={Boolean(aAnular)}
        titulo={`Anular el despacho ${aAnular?.codigo_despacho ?? ''}`}
        textoConfirmar="Anular despacho"
        cargando={anulando}
        error={errorMotivo}
        alConfirmar={anular}
        alCancelar={() => setAAnular(null)}
      >
        <p>
          Use esta opción solo si el despacho se registró por error y <strong className="text-tinta">el producto nunca salió de la planta</strong>. Las cantidades
          volverán a sus lotes de origen y se agregará un movimiento compensatorio en el Kardex.
        </p>
        <p>
          Si el producto regresó de un cliente, no lo reingrese al stock: regístrelo como merma. Solo se puede anular dentro de las {HORAS_ANULACION} horas
          siguientes al registro.
        </p>
      </ConfirmarConMotivo>
    </>
  );
}
