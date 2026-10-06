import { useState } from 'react';
import { ArrowDownLeft, ArrowUpRight, Filter, RotateCcw, Undo2 } from 'lucide-react';
import { anulacionesApi, catalogoApi, lotesApi, movimientosApi } from '../api/services';
import { erroresDeCampo, mensajeError } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import ConfirmarConMotivo from '../components/ui/ConfirmarConMotivo';
import BotonDescarga from '../components/reportes/BotonDescarga';
import { useCargar } from '../hooks/useCargar';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import Boton from '../components/ui/Boton';
import Paginacion from '../components/ui/Paginacion';
import { Campo, Entrada, Selector } from '../components/ui/Campo';
import { AvisoError, Cargando, Vacio } from '../components/ui/Estados';
import { HORAS_ANULACION, TIPOS_MOVIMIENTO, UNIDADES, dentroDePlazo, formatoCantidad, formatoFechaHora, hoyISO } from '../utils/format';

const FILTROS_INICIALES = {
  fecha_inicio: hoyISO(-30),
  fecha_fin: hoyISO(),
  tipo_movimiento: '',
  tipo_item: '',
  item_id: '',
  codigo_lote: '',
};

/** Convierte el código de lote escrito por el usuario en lote_id */
async function resolverLote(codigo) {
  const respuesta = await lotesApi.buscar({ buscar: codigo, per_page: 20 });
  const exacto = respuesta.data.find((l) => l.codigo_lote.toLowerCase() === codigo.toLowerCase());
  if (exacto) return exacto;
  if (respuesta.data.length === 1) return respuesta.data[0];
  throw new Error(
    respuesta.data.length === 0
      ? `No existe un lote con el código "${codigo}".`
      : `"${codigo}" coincide con ${respuesta.data.length} lotes. Escriba el código completo.`,
  );
}

export default function KardexPage() {
  const [form, setForm] = useState(FILTROS_INICIALES);
  const [consulta, setConsulta] = useState({ params: { fecha_inicio: FILTROS_INICIALES.fecha_inicio, fecha_fin: FILTROS_INICIALES.fecha_fin }, pagina: 1 });
  const [errorFiltro, setErrorFiltro] = useState('');
  const [aplicando, setAplicando] = useState(false);
  const { esAdmin } = useAuth();
  const { notificar } = useToast();
  const [aAnular, setAAnular] = useState(null);
  const [anulando, setAnulando] = useState(false);
  const [errorMotivo, setErrorMotivo] = useState('');

  const catalogo = useCargar(async () => {
    const [insumos, productos] = await Promise.all([catalogoApi.insumos(), catalogoApi.productos()]);
    return { INSUMO: insumos.data, PRODUCTO: productos.data };
  }, []);

  const kardex = useCargar(() => movimientosApi.listar({ ...consulta.params, page: consulta.pagina, per_page: 25 }), [consulta]);

  const cambiar = (campo) => (e) => {
    const valor = e.target.value;
    setForm((f) => ({ ...f, [campo]: valor, ...(campo === 'tipo_item' ? { item_id: '' } : {}) }));
  };

  const aplicar = async (evento) => {
    evento.preventDefault();
    setErrorFiltro('');
    setAplicando(true);

    try {
      const params = {};
      ['fecha_inicio', 'fecha_fin', 'tipo_movimiento', 'tipo_item', 'item_id'].forEach((clave) => {
        if (form[clave]) params[clave] = form[clave];
      });
      if (form.codigo_lote.trim()) {
        params.lote_id = (await resolverLote(form.codigo_lote.trim())).id;
      }
      setConsulta({ params, pagina: 1 });
    } catch (e) {
      setErrorFiltro(e instanceof Error && !e.response ? e.message : mensajeError(e));
    } finally {
      setAplicando(false);
    }
  };

  const anularIngreso = async (motivo) => {
    setAnulando(true);
    setErrorMotivo('');
    try {
      const respuesta = await anulacionesApi.ingresoCompra(aAnular.lote.id, motivo);
      notificar(respuesta.message);
      setAAnular(null);
      kardex.recargar();
    } catch (e) {
      setErrorMotivo(erroresDeCampo(e).motivo ?? '');
      notificar(mensajeError(e), 'error');
    } finally {
      setAnulando(false);
    }
  };

  const limpiar = () => {
    setForm(FILTROS_INICIALES);
    setErrorFiltro('');
    setConsulta({ params: { fecha_inicio: FILTROS_INICIALES.fecha_inicio, fecha_fin: FILTROS_INICIALES.fecha_fin }, pagina: 1 });
  };

  const items = form.tipo_item ? catalogo.datos?.[form.tipo_item] ?? [] : [];
  const movimientos = kardex.datos?.data ?? [];
  const resumen = kardex.datos?.resumen;
  const unidadResumen = movimientos[0] ? (movimientos[0].lote?.insumo ?? movimientos[0].lote?.producto)?.unidad_medida : null;

  return (
    <>
      <Encabezado
        titulo="Kardex de inventario"
        descripcion="Registro inmutable de cada entrada y salida, con su lote y responsable. Los errores se corrigen con un movimiento nuevo, nunca editando."
      />

      <Panel className="mb-6">
        <form onSubmit={aplicar} className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-6">
          <Campo id="fecha_inicio" etiqueta="Desde">
            <Entrada id="fecha_inicio" type="date" value={form.fecha_inicio} onChange={cambiar('fecha_inicio')} max={form.fecha_fin || undefined} />
          </Campo>
          <Campo id="fecha_fin" etiqueta="Hasta">
            <Entrada id="fecha_fin" type="date" value={form.fecha_fin} onChange={cambiar('fecha_fin')} min={form.fecha_inicio || undefined} />
          </Campo>
          <Campo id="tipo_movimiento" etiqueta="Movimiento">
            <Selector id="tipo_movimiento" value={form.tipo_movimiento} onChange={cambiar('tipo_movimiento')}>
              <option value="">Todos</option>
              {Object.entries(TIPOS_MOVIMIENTO).map(([valor, { etiqueta }]) => (
                <option key={valor} value={valor}>
                  {etiqueta}
                </option>
              ))}
            </Selector>
          </Campo>
          <Campo id="tipo_item" etiqueta="Tipo de ítem">
            <Selector id="tipo_item" value={form.tipo_item} onChange={cambiar('tipo_item')}>
              <option value="">Todos</option>
              <option value="INSUMO">Insumos</option>
              <option value="PRODUCTO">Productos terminados</option>
            </Selector>
          </Campo>
          <Campo id="item_id" etiqueta="Ítem">
            <Selector id="item_id" value={form.item_id} onChange={cambiar('item_id')} disabled={!form.tipo_item}>
              <option value="">{form.tipo_item ? 'Todos' : 'Elija el tipo primero'}</option>
              {items.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.nombre}
                </option>
              ))}
            </Selector>
          </Campo>
          <Campo id="codigo_lote" etiqueta="Código de lote">
            <Entrada id="codigo_lote" value={form.codigo_lote} onChange={cambiar('codigo_lote')} placeholder="Ej. LEC-A" autoComplete="off" />
          </Campo>

          <div className="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-6">
            <Boton type="submit" icono={Filter} cargando={aplicando}>
              Aplicar filtros
            </Boton>
            <Boton variante="secundario" icono={RotateCcw} onClick={limpiar}>
              Restablecer
            </Boton>
            <BotonDescarga ruta="reportes/kardex" formato="xlsx" params={consulta.params} />
            {errorFiltro && (
              <p role="alert" className="text-sm text-vencido">
                {errorFiltro}
              </p>
            )}
          </div>
        </form>
      </Panel>

      {resumen && (
        <section aria-label="Totales del período" className="mb-6 grid grid-cols-2 divide-x divide-linea rounded-lg bg-white ring-1 ring-linea sm:grid-cols-4">
          {[
            ['Movimientos', resumen.movimientos, null],
            ['Entradas', resumen.total_entradas, 'text-vigente'],
            ['Salidas', resumen.total_salidas, 'text-vencido'],
            ['Saldo neto', resumen.saldo_neto, null],
          ].map(([etiqueta, valor, color], i) => (
            <div key={etiqueta} className={`px-5 py-4 ${i >= 2 ? 'border-t border-linea sm:border-t-0' : ''}`}>
              <p className={`cifra text-2xl font-semibold ${color ?? ''}`}>{i === 0 ? valor : formatoCantidad(valor, unidadResumen)}</p>
              <p className="text-sm text-acero">{etiqueta}</p>
            </div>
          ))}
        </section>
      )}

      <Panel>
        {kardex.cargando && !kardex.datos ? (
          <Cargando />
        ) : kardex.error ? (
          <div className="p-4">
            <AvisoError mensaje={kardex.error} alReintentar={kardex.recargar} />
          </div>
        ) : movimientos.length === 0 ? (
          <Vacio titulo="No hay movimientos con esos filtros">Amplíe el rango de fechas o quite algún filtro.</Vacio>
        ) : (
          <div className={`overflow-x-auto ${kardex.cargando ? 'opacity-60' : ''}`}>
            <table className="w-full min-w-[56rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  <th scope="col" className="px-4 py-3 font-medium">Fecha</th>
                  <th scope="col" className="px-4 py-3 font-medium">Movimiento</th>
                  <th scope="col" className="px-4 py-3 font-medium">Ítem y lote</th>
                  <th scope="col" className="px-4 py-3 text-right font-medium">Cantidad</th>
                  <th scope="col" className="px-4 py-3 font-medium">Motivo</th>
                  <th scope="col" className="px-4 py-3 font-medium">Responsable</th>
                  <th scope="col" className="px-4 py-3"><span className="sr-only">Acciones</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {movimientos.map((m) => {
                  const tipo = TIPOS_MOVIMIENTO[m.tipo_movimiento] ?? { etiqueta: m.tipo_movimiento, entrada: m.es_entrada };
                  const item = m.lote?.insumo ?? m.lote?.producto;
                  const Flecha = tipo.entrada ? ArrowDownLeft : ArrowUpRight;
                  return (
                    <tr key={m.id} className="align-top">
                      <td className="whitespace-nowrap px-4 py-3 cifra">{formatoFechaHora(m.fecha)}</td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex items-center gap-1.5 font-medium ${tipo.entrada ? 'text-vigente' : 'text-tinta'}`}>
                          <Flecha className="size-4" aria-hidden />
                          {tipo.etiqueta}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <p>{item?.nombre ?? '—'}</p>
                        <p className="cifra text-sm text-acero">{m.lote?.codigo_lote}</p>
                      </td>
                      <td className={`cifra whitespace-nowrap px-4 py-3 text-right text-lg font-semibold ${tipo.entrada ? 'text-vigente' : ''}`}>
                        {tipo.entrada ? '+' : '−'}
                        {formatoCantidad(m.cantidad)} <span className="text-sm font-normal text-acero">{UNIDADES[item?.unidad_medida] ?? ''}</span>
                      </td>
                      <td className="max-w-xs px-4 py-3 text-sm">{m.motivo_observacion ?? '—'}</td>
                      <td className="px-4 py-3 text-sm">{m.responsable?.name ?? '—'}</td>
                      <td className="px-2 py-2 text-right">
                        {esAdmin && m.tipo_movimiento === 'ENTRADA_COMPRA' && m.lote?.estado !== 'ANULADO' && dentroDePlazo(m.fecha) && (
                          <button
                            type="button"
                            onClick={() => {
                              setErrorMotivo('');
                              setAAnular(m);
                            }}
                            className="inline-flex min-h-10 items-center gap-1.5 whitespace-nowrap rounded-md px-3 text-sm font-medium text-vencido hover:bg-vencido-fondo"
                          >
                            <Undo2 className="size-4" aria-hidden /> Anular ingreso
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <Paginacion meta={kardex.datos?.meta} alCambiar={(pagina) => setConsulta((c) => ({ ...c, pagina }))} />
      </Panel>

      <ConfirmarConMotivo
        abierto={Boolean(aAnular)}
        titulo={`Anular el ingreso del lote ${aAnular?.lote?.codigo_lote ?? ''}`}
        textoConfirmar="Anular ingreso"
        cargando={anulando}
        error={errorMotivo}
        alConfirmar={anularIngreso}
        alCancelar={() => setAAnular(null)}
      >
        <p>
          Solo para compras registradas por error (cantidad, insumo o proveedor equivocados) y que todavía no se usaron. El lote quedará anulado y su
          saldo saldrá del stock con un movimiento compensatorio.
        </p>
        <p>Si el lote ya tuvo consumos o bajas, o pasaron más de {HORAS_ANULACION} horas, el sistema lo rechazará: corríjalo con una merma.</p>
      </ConfirmarConMotivo>
    </>
  );
}
