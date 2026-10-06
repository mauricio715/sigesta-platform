import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, PackagePlus, RefreshCw, Trash2 } from 'lucide-react';
import { alertasApi, inventarioApi, movimientosApi } from '../api/services';
import { mensajeError } from '../api/client';
import { useCargar } from '../hooks/useCargar';
import { useToast } from '../context/ToastContext';
import { useAuth } from '../context/AuthContext';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import Boton from '../components/ui/Boton';
import Prioridad from '../components/ui/Prioridad';
import LoteChip from '../components/ui/LoteChip';
import { AvisoError, Cargando, Vacio } from '../components/ui/Estados';
import { TIPOS_ALERTA, formatoFechaHora, hoyISO } from '../utils/format';

const PATRON_DESPACHO = /DSP-\d{8}-\d{5}/;

async function cargarTablero() {
  const hoy = hoyISO();
  const [resumen, alertas, ventasHoy] = await Promise.all([
    inventarioApi.resumen(),
    alertasApi.listar({ estado: 'pendientes', per_page: 10 }),
    movimientosApi.listar({ tipo_movimiento: 'SALIDA_VENTA', fecha_inicio: hoy, fecha_fin: hoy, per_page: 100 }),
  ]);

  // Un despacho genera un movimiento por lote entregado: se cuentan códigos únicos
  const despachos = new Set(
    ventasHoy.data.map((m) => m.motivo_observacion?.match(PATRON_DESPACHO)?.[0]).filter(Boolean),
  );

  return { resumen: resumen.data, alertas: alertas.data, totalAlertas: alertas.meta.total, despachosHoy: despachos.size };
}

function Indicador({ valor, etiqueta, detalle, tono = 'neutro' }) {
  const color = { neutro: 'text-tinta', alerta: 'text-proximo', critico: 'text-vencido', ok: 'text-vigente' }[tono];
  return (
    <div className="px-5 py-4">
      <p className={`cifra text-4xl font-semibold leading-none ${color}`}>{valor}</p>
      <p className="mt-2 font-medium">{etiqueta}</p>
      {detalle && <p className="text-sm text-acero">{detalle}</p>}
    </div>
  );
}

export default function DashboardPage() {
  const { usuario } = useAuth();
  const { notificar } = useToast();
  const { datos, cargando, error, recargar } = useCargar(cargarTablero, []);
  const [marcando, setMarcando] = useState(null);
  const [leidas, setLeidas] = useState([]);

  const marcarLeida = async (alerta) => {
    setMarcando(alerta.id);
    try {
      await alertasApi.marcarLeida(alerta.id);
      setLeidas((ids) => [...ids, alerta.id]);
      notificar('Alerta marcada como leída.');
    } catch (e) {
      notificar(mensajeError(e), 'error');
    } finally {
      setMarcando(null);
    }
  };

  const fecha = new Date().toLocaleDateString('es-BO', { weekday: 'long', day: 'numeric', month: 'long' });

  if (cargando && !datos) return <Cargando texto="Preparando el panel de control…" />;
  if (error && !datos) return <AvisoError mensaje={error} alReintentar={recargar} />;

  const { resumen, alertas, totalAlertas, despachosHoy } = datos;
  const totales = resumen.totales;
  const pendientes = alertas.filter((a) => !leidas.includes(a.id));
  const totalItems = totales.insumos + totales.productos;

  return (
    <>
      <Encabezado
        titulo={`Buen turno, ${usuario?.name?.split(' ')[0] ?? ''}`}
        descripcion={`Estado de la planta al ${fecha}.`}
        acciones={
          <Boton variante="secundario" icono={RefreshCw} onClick={recargar} cargando={cargando}>
            Actualizar
          </Boton>
        }
      />

      {/* Tablero: una sola franja, no tarjetas sueltas */}
      <section aria-label="Indicadores" className="mb-8 grid grid-cols-2 divide-linea rounded-lg bg-white ring-1 ring-linea md:grid-cols-5 md:divide-x [&>*:nth-child(n+3)]:border-t [&>*:nth-child(n+3)]:border-linea md:[&>*:nth-child(n+3)]:border-t-0">
        <Indicador valor={totalAlertas - leidas.length} etiqueta="Alertas pendientes" tono={totalAlertas - leidas.length ? 'alerta' : 'ok'} />
        <Indicador
          valor={totales.lotes_vencidos_con_saldo}
          etiqueta="Lotes vencidos"
          detalle="con saldo por dar de baja"
          tono={totales.lotes_vencidos_con_saldo ? 'critico' : 'ok'}
        />
        <Indicador valor={totales.lotes_proximos_a_vencer} etiqueta="Por vencer" detalle="priorizar según FEFO" tono={totales.lotes_proximos_a_vencer ? 'alerta' : 'ok'} />
        <Indicador
          valor={totales.items_bajo_stock_minimo}
          etiqueta="Bajo stock mínimo"
          detalle={`de ${totalItems} ítems`}
          tono={totales.items_bajo_stock_minimo ? 'alerta' : 'ok'}
        />
        <Indicador valor={despachosHoy} etiqueta="Despachos de hoy" />
      </section>

      <Panel
        titulo="Alertas prioritarias"
        acciones={
          <Link to="/inventario" className="text-sm font-medium text-petroleo hover:underline">
            Ver inventario
          </Link>
        }
      >
        {pendientes.length === 0 ? (
          <Vacio titulo="Sin alertas pendientes">El motor de alertas revisa los vencimientos cada noche y con cada movimiento de stock.</Vacio>
        ) : (
          <ul className="divide-y divide-linea">
            {pendientes.map((alerta) => (
              <li key={alerta.id} className="grid gap-3 px-4 py-4 md:grid-cols-[7rem_1fr_auto] md:items-center">
                <div className="flex items-center gap-2 md:flex-col md:items-start">
                  <Prioridad nivel={alerta.nivel_prioridad} />
                  <span className="text-sm text-acero">{TIPOS_ALERTA[alerta.tipo_alerta]}</span>
                </div>

                <div className="min-w-0">
                  <p className="max-w-[75ch]">{alerta.mensaje}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-acero">
                    {alerta.lote && <LoteChip lote={alerta.lote} mostrarCantidad={false} />}
                    <span>Actualizada {formatoFechaHora(alerta.updated_at)}</span>
                  </div>
                </div>

                <div className="flex flex-wrap gap-2 md:justify-end">
                  {alerta.tipo_alerta === 'VENCIMIENTO_CRITICO' && alerta.lote && (
                    <Link
                      to={`/operaciones?tab=mermas&lote=${encodeURIComponent(alerta.lote.codigo_lote)}`}
                      className="inline-flex min-h-11 items-center gap-2 rounded-md bg-vencido px-4 font-medium text-white"
                    >
                      <Trash2 className="size-4" aria-hidden /> Dar de baja
                    </Link>
                  )}
                  {alerta.tipo_alerta === 'STOCK_MINIMO' && alerta.item?.tipo === 'INSUMO' && (
                    <Link
                      to={`/operaciones?tab=compra&insumo=${alerta.item.id}`}
                      className="inline-flex min-h-11 items-center gap-2 rounded-md px-4 font-medium text-petroleo ring-1 ring-inset ring-linea hover:bg-concreto"
                    >
                      <PackagePlus className="size-4" aria-hidden /> Registrar compra
                    </Link>
                  )}
                  <Boton variante="secundario" icono={Check} cargando={marcando === alerta.id} onClick={() => marcarLeida(alerta)}>
                    Marcar como leída
                  </Boton>
                </div>
              </li>
            ))}
          </ul>
        )}
        {totalAlertas > alertas.length && (
          <p className="border-t border-linea px-4 py-3 text-sm text-acero">
            Se muestran las {alertas.length} alertas más críticas de {totalAlertas}.
          </p>
        )}
      </Panel>
    </>
  );
}
