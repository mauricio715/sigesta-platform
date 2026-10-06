import { useState } from 'react';
import { Bot, Building2, Factory, Truck, Users } from 'lucide-react';
import { trazabilidadApi } from '../api/services';
import { mensajeError } from '../api/client';
import { useIAChat } from '../context/IAChatContext';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import Boton from '../components/ui/Boton';
import LoteChip from '../components/ui/LoteChip';
import { AvisoError, Cargando, Vacio } from '../components/ui/Estados';
import BuscadorLote from '../components/operaciones/BuscadorLote';
import BotonDescarga from '../components/reportes/BotonDescarga';
import { formatoCantidad, formatoFecha, formatoFechaHora } from '../utils/format';

function HaciaAdelante({ reporte }) {
  const { lote_insumo: origen, producciones, clientes_afectados: clientes, resumen } = reporte;
  const unidad = origen.insumo?.unidad_medida;

  return (
    <div className="space-y-6">
      <section aria-label="Alcance" className="grid grid-cols-2 divide-x divide-linea rounded-lg bg-white ring-1 ring-linea sm:grid-cols-4">
        {[
          ['Órdenes de producción', resumen.ordenes_produccion],
          ['Lotes de producto', resumen.lotes_producto],
          ['Despachos', resumen.despachos],
          ['Clientes alcanzados', resumen.clientes_afectados],
        ].map(([texto, valor], i) => (
          <div key={texto} className={`px-5 py-4 ${i >= 2 ? 'border-t border-linea sm:border-t-0' : ''}`}>
            <p className={`cifra text-3xl font-semibold ${i === 3 && valor > 0 ? 'text-vencido' : ''}`}>{valor}</p>
            <p className="text-sm text-acero">{texto}</p>
          </div>
        ))}
      </section>

      <Panel titulo="Clientes que recibieron producto con este insumo">
        {clientes.length === 0 ? (
          <Vacio titulo="Ningún producto elaborado con este lote fue despachado">Si hay un problema con el insumo, basta con retener el stock en planta.</Vacio>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  <th scope="col" className="px-4 py-3 font-medium">Cliente</th>
                  <th scope="col" className="px-4 py-3 font-medium">Contacto</th>
                  <th scope="col" className="px-4 py-3 font-medium">Entregas</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {clientes.map((c) => (
                  <tr key={c.id} className="align-top">
                    <td className="px-4 py-3">
                      <p className="font-medium">{c.razon_social}</p>
                      <p className="text-sm text-acero">{c.nit_ci ? `NIT/CI ${c.nit_ci}` : c.codigo_cliente}</p>
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {c.telefono && <p>{c.telefono}</p>}
                      {c.email && <p>{c.email}</p>}
                      {!c.telefono && !c.email && <p className="text-acero">Sin datos de contacto</p>}
                    </td>
                    <td className="px-4 py-3">
                      <ul className="space-y-1 text-sm">
                        {c.entregas.map((e) => (
                          <li key={`${e.codigo_despacho}-${e.lote_producto}`}>
                            <span className="cifra font-semibold">{formatoCantidad(e.cantidad)}</span> de {e.producto}, lote{' '}
                            <span className="cifra">{e.lote_producto}</span> · {e.codigo_despacho} · {formatoFechaHora(e.fecha_despacho)}
                          </li>
                        ))}
                      </ul>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Panel titulo="Producción donde se usó">
        {producciones.length === 0 ? (
          <Vacio titulo="Este lote todavía no se usó en producción" />
        ) : (
          <ul className="divide-y divide-linea">
            {producciones.map((p) => (
              <li key={p.orden_produccion.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <span className="cifra font-semibold">{p.orden_produccion.codigo_orden}</span>
                <span className="text-sm text-acero">{formatoFechaHora(p.orden_produccion.fecha_produccion)}</span>
                <span className="text-sm">
                  Consumió <span className="cifra font-semibold">{formatoCantidad(p.cantidad_insumo_consumida, unidad)}</span> para {p.producto.nombre}
                </span>
                {p.lote_producto && <LoteChip lote={p.lote_producto} unidad={p.producto.unidad_medida} />}
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}

function HaciaAtras({ reporte }) {
  const { orden_produccion: orden, receta, insumos_origen: origen } = reporte;

  if (!orden) {
    return <Vacio titulo="Este lote no proviene de una orden de producción registrada" />;
  }

  return (
    <div className="space-y-6">
      <Panel titulo="Orden de producción">
        <dl className="grid gap-4 p-4 sm:grid-cols-4">
          <div>
            <dt className="text-sm text-acero">Orden</dt>
            <dd className="cifra text-lg font-semibold">{orden.codigo_orden}</dd>
          </div>
          <div>
            <dt className="text-sm text-acero">Fecha</dt>
            <dd>{formatoFechaHora(orden.fecha_produccion)}</dd>
          </div>
          <div>
            <dt className="text-sm text-acero">Responsable</dt>
            <dd>{orden.responsable}</dd>
          </div>
          <div>
            <dt className="text-sm text-acero">Receta</dt>
            <dd>{receta?.nombre_receta}</dd>
          </div>
        </dl>
      </Panel>

      <Panel titulo="Insumos de origen">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[44rem] text-left">
            <thead className="bg-concreto/60 text-sm text-acero">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Insumo</th>
                <th scope="col" className="px-4 py-3 font-medium">Lote</th>
                <th scope="col" className="px-4 py-3 text-right font-medium">Consumido</th>
                <th scope="col" className="px-4 py-3 font-medium">Proveedor</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-linea">
              {origen.map((o) => (
                <tr key={o.lote.id}>
                  <td className="px-4 py-3">{o.insumo.nombre}</td>
                  <td className="px-4 py-3">
                    <LoteChip lote={o.lote} mostrarCantidad={false} />
                    <p className="mt-1 text-sm text-acero">
                      Fabricado {formatoFecha(o.lote.fecha_fabricacion)} · vence {formatoFecha(o.lote.fecha_vencimiento)}
                    </p>
                  </td>
                  <td className="cifra px-4 py-3 text-right font-semibold">{formatoCantidad(o.cantidad_consumida, o.insumo.unidad_medida)}</td>
                  <td className="px-4 py-3 text-sm">{o.proveedor?.razon_social ?? <span className="text-acero">Sin proveedor registrado</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
}

export default function TrazabilidadPage() {
  const { preguntar } = useIAChat();
  const [lote, setLote] = useState(null);
  const [reporte, setReporte] = useState(null);
  const [cargando, setCargando] = useState(false);
  const [error, setError] = useState('');

  const rastrear = async (seleccionado) => {
    setLote(seleccionado);
    setReporte(null);
    setError('');
    setCargando(true);

    try {
      const respuesta =
        seleccionado.tipo_item === 'INSUMO'
          ? await trazabilidadApi.insumo(seleccionado.id)
          : await trazabilidadApi.producto(seleccionado.id);
      setReporte(respuesta.data);
    } catch (e) {
      setError(mensajeError(e));
    } finally {
      setCargando(false);
    }
  };

  const esInsumo = lote?.tipo_item === 'INSUMO';
  const item = lote ? lote.insumo ?? lote.producto : null;

  return (
    <>
      <Encabezado
        titulo="Trazabilidad"
        descripcion="Un lote de insumo se rastrea hacia adelante, hasta los clientes. Un lote de producto, hacia atrás, hasta sus insumos y proveedores."
      />

      <Panel className="mb-6">
        <div className="max-w-xl p-4">
          <BuscadorLote id="lote-trazabilidad" alSeleccionar={rastrear} etiqueta="Lote a rastrear" />
        </div>
      </Panel>

      {lote && (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
          <div className="flex flex-wrap items-center gap-3">
            {esInsumo ? <Truck className="size-5 text-petroleo" aria-hidden /> : <Factory className="size-5 text-petroleo" aria-hidden />}
            <p className="font-cond text-xl font-semibold">{esInsumo ? 'Hacia adelante' : 'Hacia atrás'}</p>
            <LoteChip lote={lote} unidad={item?.unidad_medida} destacado />
            <span className="text-acero">{item?.nombre}</span>
            {lote.proveedor && (
              <span className="inline-flex items-center gap-1 text-sm text-acero">
                <Building2 className="size-4" aria-hidden /> {lote.proveedor.razon_social}
              </span>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
          <BotonDescarga ruta={`reportes/trazabilidad/${lote.id}`} formato="pdf">
            Informe PDF
          </BotonDescarga>
          <Boton
            variante="secundario"
            icono={Bot}
            onClick={() =>
              preguntar(
                esInsumo
                  ? `Analiza el alcance del lote de insumo ${lote.codigo_lote}: a qué productos y clientes llegó y qué acciones de retención o retiro recomiendas.`
                  : `Resume el origen del lote de producto ${lote.codigo_lote}: insumos, proveedores y si alguno de esos lotes presenta alertas.`,
              )
            }
          >
            Analizar con el asistente
          </Boton>
          </div>
        </div>
      )}

      {cargando && <Cargando texto="Reconstruyendo la cadena del lote…" />}
      {error && <AvisoError mensaje={error} />}
      {reporte && (esInsumo ? <HaciaAdelante reporte={reporte} /> : <HaciaAtras reporte={reporte} />)}
      {!lote && (
        <Vacio titulo="Busque un lote para ver su cadena">
          <span className="inline-flex items-center gap-1">
            <Users className="size-4" aria-hidden /> Útil ante un reclamo de cliente, un aviso de proveedor o una inspección.
          </span>
        </Vacio>
      )}
    </>
  );
}
