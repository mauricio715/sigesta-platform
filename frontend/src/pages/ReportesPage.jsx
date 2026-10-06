import { useState } from 'react';
import { BadgeCheck, Boxes, ClipboardList, GitFork, SearchCheck, ShieldX, Trash2, Truck } from 'lucide-react';
import { catalogoApi } from '../api/services';
import { verificarReporte } from '../api/descargas';
import { mensajeError } from '../api/client';
import { useCargar } from '../hooks/useCargar';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import Boton from '../components/ui/Boton';
import LoteChip from '../components/ui/LoteChip';
import { Campo, Entrada, Selector } from '../components/ui/Campo';
import BuscadorLote from '../components/operaciones/BuscadorLote';
import BotonDescarga from '../components/reportes/BotonDescarga';
import { TIPOS_MOVIMIENTO, formatoFechaHora, hoyISO } from '../utils/format';

const REPORTES = [
  { clave: 'trazabilidad', titulo: 'Trazabilidad de un lote', icono: GitFork, descripcion: 'Informe firmable para un retiro de producto, un reclamo o una inspección de SENASAG.' },
  { clave: 'inventario', titulo: 'Estado de inventario', icono: Boxes, descripcion: 'Stock, lotes en orden FEFO y lotes vencidos retenidos.' },
  { clave: 'kardex', titulo: 'Kardex', icono: ClipboardList, descripcion: 'Movimientos con saldo acumulado cuando se elige un ítem o un lote.' },
  { clave: 'despachos', titulo: 'Despachos', icono: Truck, descripcion: 'Entregas por cliente con importes; los anulados se listan aparte.' },
  { clave: 'mermas', titulo: 'Mermas y bajas', icono: Trash2, descripcion: 'Registro de no conformidades agrupado por motivo.' },
  { clave: 'verificar', titulo: 'Verificar un documento', icono: SearchCheck, descripcion: 'Comprueba si un reporte impreso fue emitido por SI-GESTA.' },
];

function SelectorFormato({ valor, alCambiar }) {
  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-medium">Formato</legend>
      <div className="inline-flex rounded-md bg-concreto p-1">
        {[
          ['pdf', 'PDF (para imprimir y firmar)'],
          ['xlsx', 'Excel (para analizar)'],
        ].map(([clave, texto]) => (
          <label key={clave} className={`cursor-pointer rounded px-3 py-2 text-sm font-medium ${valor === clave ? 'bg-white shadow-sm ring-1 ring-linea' : 'text-acero'}`}>
            <input type="radio" name="formato" value={clave} checked={valor === clave} onChange={() => alCambiar(clave)} className="sr-only" />
            {texto}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function Periodo({ filtros, setFiltros }) {
  const cambiar = (campo) => (e) => setFiltros((f) => ({ ...f, [campo]: e.target.value }));
  return (
    <>
      <Campo id="r-desde" etiqueta="Desde">
        <Entrada id="r-desde" type="date" value={filtros.fecha_inicio} onChange={cambiar('fecha_inicio')} max={filtros.fecha_fin || undefined} />
      </Campo>
      <Campo id="r-hasta" etiqueta="Hasta">
        <Entrada id="r-hasta" type="date" value={filtros.fecha_fin} onChange={cambiar('fecha_fin')} min={filtros.fecha_inicio || undefined} />
      </Campo>
    </>
  );
}

const sinVacios = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== null && v !== undefined));

export default function ReportesPage() {
  const [actual, setActual] = useState('trazabilidad');
  const [formato, setFormato] = useState('pdf');
  const [filtros, setFiltros] = useState({ fecha_inicio: hoyISO(-30), fecha_fin: hoyISO(), tipo_movimiento: '', tipo_item: '', item_id: '', cliente_id: '', estado: '' });
  const [lote, setLote] = useState(null);
  const [codigo, setCodigo] = useState('');
  const [verificacion, setVerificacion] = useState(null);
  const [verificando, setVerificando] = useState(false);

  const catalogo = useCargar(async () => {
    const [insumos, productos, clientes] = await Promise.all([catalogoApi.insumos(), catalogoApi.productos(), catalogoApi.clientes()]);
    return { INSUMO: insumos.data, PRODUCTO: productos.data, clientes: clientes.data };
  }, []);

  const cambiar = (campo) => (e) =>
    setFiltros((f) => ({ ...f, [campo]: e.target.value, ...(campo === 'tipo_item' ? { item_id: '' } : {}) }));

  const verificar = async (evento) => {
    evento.preventDefault();
    setVerificando(true);
    try {
      const respuesta = await verificarReporte(codigo.trim());
      setVerificacion({ valido: true, datos: respuesta.data });
    } catch (e) {
      setVerificacion({ valido: false, mensaje: mensajeError(e) });
    } finally {
      setVerificando(false);
    }
  };

  const reporte = REPORTES.find((r) => r.clave === actual);
  const periodo = { fecha_inicio: filtros.fecha_inicio, fecha_fin: filtros.fecha_fin };

  return (
    <>
      <Encabezado
        titulo="Reportes"
        descripcion="Cada documento lleva un código de verificación y queda registrado con su usuario y fecha de emisión."
      />

      <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <nav aria-label="Tipos de reporte">
          <ul className="space-y-1">
            {REPORTES.map(({ clave, titulo, icono: Icono }) => (
              <li key={clave}>
                <button
                  type="button"
                  onClick={() => {
                    setActual(clave);
                    setFormato(['kardex', 'despachos'].includes(clave) ? 'xlsx' : 'pdf');
                  }}
                  aria-current={actual === clave ? 'page' : undefined}
                  className={`flex min-h-11 w-full items-center gap-3 rounded-md px-3 text-left ${
                    actual === clave ? 'bg-white font-semibold text-petroleo ring-1 ring-linea' : 'text-acero hover:bg-white/60 hover:text-tinta'
                  }`}
                >
                  <Icono className="size-5 shrink-0" aria-hidden />
                  {titulo}
                </button>
              </li>
            ))}
          </ul>
        </nav>

        <Panel titulo={reporte.titulo}>
          <div className="space-y-5 p-5">
            <p className="text-acero">{reporte.descripcion}</p>

            {actual === 'trazabilidad' && (
              <>
                <div className="max-w-xl">
                  <BuscadorLote id="r-lote" alSeleccionar={setLote} etiqueta="Lote" />
                </div>
                {lote && (
                  <p className="flex flex-wrap items-center gap-3 text-sm">
                    <LoteChip lote={lote} unidad={(lote.insumo ?? lote.producto)?.unidad_medida} destacado />
                    {(lote.insumo ?? lote.producto)?.nombre} ·{' '}
                    {lote.tipo_item === 'INSUMO' ? 'se rastreará hacia adelante, hasta los clientes' : 'se rastreará hacia atrás, hasta los proveedores'}
                  </p>
                )}
                <SelectorFormato valor={formato} alCambiar={setFormato} />
                <BotonDescarga ruta={lote ? `reportes/trazabilidad/${lote.id}` : ''} formato={formato} variante="primario" className={lote ? '' : 'pointer-events-none opacity-50'}>
                  Generar informe
                </BotonDescarga>
              </>
            )}

            {actual === 'inventario' && (
              <>
                <SelectorFormato valor={formato} alCambiar={setFormato} />
                <BotonDescarga ruta="reportes/inventario" formato={formato} variante="primario">
                  Generar reporte
                </BotonDescarga>
              </>
            )}

            {actual === 'kardex' && (
              <>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                  <Periodo filtros={filtros} setFiltros={setFiltros} />
                  <Campo id="r-tipo-mov" etiqueta="Movimiento">
                    <Selector id="r-tipo-mov" value={filtros.tipo_movimiento} onChange={cambiar('tipo_movimiento')}>
                      <option value="">Todos</option>
                      {Object.entries(TIPOS_MOVIMIENTO).map(([valor, { etiqueta }]) => (
                        <option key={valor} value={valor}>
                          {etiqueta}
                        </option>
                      ))}
                    </Selector>
                  </Campo>
                  <Campo id="r-tipo-item" etiqueta="Tipo de ítem" ayuda="Elija un ítem para obtener el saldo acumulado">
                    <Selector id="r-tipo-item" value={filtros.tipo_item} onChange={cambiar('tipo_item')}>
                      <option value="">Todos</option>
                      <option value="INSUMO">Insumos</option>
                      <option value="PRODUCTO">Productos terminados</option>
                    </Selector>
                  </Campo>
                  <Campo id="r-item" etiqueta="Ítem">
                    <Selector id="r-item" value={filtros.item_id} onChange={cambiar('item_id')} disabled={!filtros.tipo_item}>
                      <option value="">{filtros.tipo_item ? 'Todos' : 'Elija el tipo primero'}</option>
                      {(filtros.tipo_item ? catalogo.datos?.[filtros.tipo_item] ?? [] : []).map((i) => (
                        <option key={i.id} value={i.id}>
                          {i.nombre}
                        </option>
                      ))}
                    </Selector>
                  </Campo>
                </div>
                <SelectorFormato valor={formato} alCambiar={setFormato} />
                <BotonDescarga
                  ruta="reportes/kardex"
                  formato={formato}
                  variante="primario"
                  params={sinVacios({ ...periodo, tipo_movimiento: filtros.tipo_movimiento, tipo_item: filtros.tipo_item, item_id: filtros.item_id })}
                >
                  Generar Kardex
                </BotonDescarga>
              </>
            )}

            {actual === 'despachos' && (
              <>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                  <Periodo filtros={filtros} setFiltros={setFiltros} />
                  <Campo id="r-cliente" etiqueta="Cliente">
                    <Selector id="r-cliente" value={filtros.cliente_id} onChange={cambiar('cliente_id')}>
                      <option value="">Todos</option>
                      {(catalogo.datos?.clientes ?? []).map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.razon_social}
                        </option>
                      ))}
                    </Selector>
                  </Campo>
                  <Campo id="r-estado" etiqueta="Estado">
                    <Selector id="r-estado" value={filtros.estado} onChange={cambiar('estado')}>
                      <option value="">Todos</option>
                      <option value="COMPLETADO">Entregados</option>
                      <option value="ANULADO">Anulados</option>
                    </Selector>
                  </Campo>
                </div>
                <SelectorFormato valor={formato} alCambiar={setFormato} />
                <BotonDescarga
                  ruta="reportes/despachos"
                  formato={formato}
                  variante="primario"
                  params={sinVacios({ ...periodo, cliente_id: filtros.cliente_id, estado: filtros.estado })}
                >
                  Generar reporte
                </BotonDescarga>
              </>
            )}

            {actual === 'mermas' && (
              <>
                <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                  <Periodo filtros={filtros} setFiltros={setFiltros} />
                </div>
                <SelectorFormato valor={formato} alCambiar={setFormato} />
                <BotonDescarga ruta="reportes/mermas" formato={formato} variante="primario" params={sinVacios(periodo)}>
                  Generar registro
                </BotonDescarga>
              </>
            )}

            {actual === 'verificar' && (
              <>
                <form onSubmit={verificar} className="flex max-w-xl flex-wrap items-end gap-2">
                  <Campo id="r-codigo" etiqueta="Código de verificación" ayuda="Está en el encabezado y el pie de cada página" className="flex-1">
                    <Entrada id="r-codigo" value={codigo} onChange={(e) => setCodigo(e.target.value)} placeholder="ABCD-1234-EF56" autoComplete="off" className="cifra uppercase" />
                  </Campo>
                  <Boton type="submit" icono={SearchCheck} cargando={verificando} disabled={!codigo.trim()} className="mb-6">
                    Verificar
                  </Boton>
                </form>

                {verificacion?.valido && (
                  <div role="status" className="max-w-xl rounded-md bg-vigente-fondo p-4">
                    <p className="flex items-center gap-2 font-semibold text-vigente">
                      <BadgeCheck className="size-5" aria-hidden /> Documento auténtico
                    </p>
                    <dl className="mt-2 grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1 text-sm">
                      <dt className="text-acero">Reporte</dt>
                      <dd>{verificacion.datos.titulo}</dd>
                      <dt className="text-acero">Emitido por</dt>
                      <dd>{verificacion.datos.emitido_por}</dd>
                      <dt className="text-acero">Fecha</dt>
                      <dd>{formatoFechaHora(verificacion.datos.emitido_at)}</dd>
                      <dt className="text-acero">Formato</dt>
                      <dd className="uppercase">{verificacion.datos.formato}</dd>
                    </dl>
                    <p className="mt-3 text-sm text-acero">
                      El código confirma que el sistema emitió un documento con esos datos. Si el contenido impreso no coincide con lo que se ve en SI-GESTA, el
                      papel pudo haber sido alterado.
                    </p>
                  </div>
                )}
                {verificacion && !verificacion.valido && (
                  <p role="alert" className="flex max-w-xl items-center gap-2 rounded-md bg-vencido-fondo p-4 text-vencido">
                    <ShieldX className="size-5 shrink-0" aria-hidden />
                    {verificacion.mensaje}
                  </p>
                )}
              </>
            )}
          </div>
        </Panel>
      </div>
    </>
  );
}
