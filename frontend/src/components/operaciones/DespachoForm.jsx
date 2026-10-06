import { useMemo, useState } from 'react';
import { Plus, Trash2, Truck } from 'lucide-react';
import { catalogoApi, inventarioApi, operacionesApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useCargar } from '../../hooks/useCargar';
import { useToast } from '../../context/ToastContext';
import Boton from '../ui/Boton';
import LoteChip from '../ui/LoteChip';
import { AreaTexto, Campo, Entrada, Selector } from '../ui/Campo';
import { AvisoError, Cargando } from '../ui/Estados';
import Resultado from './Resultado';
import { UNIDADES, formatoBs, formatoCantidad } from '../../utils/format';

let siguienteClave = 1;
const lineaVacia = () => ({ clave: siguienteClave++, producto_id: '', cantidad: '', precio_unitario: '' });

export default function DespachoForm() {
  const { notificar } = useToast();
  const [clienteId, setClienteId] = useState('');
  const [lineas, setLineas] = useState(() => [lineaVacia()]);
  const [observaciones, setObservaciones] = useState('');
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);
  const [despacho, setDespacho] = useState(null);

  const base = useCargar(async () => {
    const [clientes, resumen] = await Promise.all([catalogoApi.clientes(), inventarioApi.resumen({ tipo: 'PRODUCTO' })]);
    return { clientes: clientes.data, productos: resumen.data.productos };
  }, [despacho]);

  const productoPorId = useMemo(
    () => Object.fromEntries((base.datos?.productos ?? []).map((p) => [String(p.id), p])),
    [base.datos],
  );

  // Cantidad pedida por producto (las líneas repetidas se suman, como en el backend)
  const pedidoPorProducto = useMemo(() => {
    const totales = {};
    lineas.forEach((l) => {
      if (l.producto_id && Number(l.cantidad) > 0) totales[l.producto_id] = (totales[l.producto_id] ?? 0) + Number(l.cantidad);
    });
    return totales;
  }, [lineas]);

  const cambiarLinea = (clave, campo, valor) => setLineas((ls) => ls.map((l) => (l.clave === clave ? { ...l, [campo]: valor } : l)));

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setErrores({});

    const items = lineas
      .filter((l) => l.producto_id || l.cantidad)
      .map((l) => ({
        producto_id: l.producto_id ? Number(l.producto_id) : undefined,
        cantidad: l.cantidad,
        ...(l.precio_unitario !== '' ? { precio_unitario: l.precio_unitario } : {}),
      }));

    try {
      const respuesta = await operacionesApi.despacho({
        cliente_id: clienteId ? Number(clienteId) : undefined,
        items,
        ...(observaciones.trim() ? { observaciones: observaciones.trim() } : {}),
      });
      setDespacho(respuesta.data);
      notificar(respuesta.message);
    } catch (e) {
      setErrores(erroresDeCampo(e));
      notificar(mensajeError(e), 'error');
    } finally {
      setEnviando(false);
    }
  };

  if (base.cargando && !base.datos) return <Cargando texto="Cargando clientes y stock de productos…" />;
  if (base.error) return <div className="p-4"><AvisoError mensaje={base.error} alReintentar={base.recargar} /></div>;

  if (despacho) {
    const total = despacho.detalles.reduce((suma, d) => suma + (d.subtotal ? Number(d.subtotal) : 0), 0);
    return (
      <Resultado
        titulo={`Despacho ${despacho.codigo_despacho} registrado`}
        alContinuar={() => {
          setDespacho(null);
          setLineas([lineaVacia()]);
          setObservaciones('');
        }}
        textoContinuar="Registrar otro despacho"
      >
        <p>
          Cliente: <strong>{despacho.cliente?.razon_social}</strong>
          {despacho.cliente?.nit_ci && <span className="text-acero"> · NIT/CI {despacho.cliente.nit_ci}</span>}
        </p>
        <p className="font-medium">Lotes entregados (FEFO)</p>
        <ul className="space-y-2">
          {despacho.detalles.map((d) => (
            <li key={d.lote.id} className="flex flex-wrap items-center gap-3">
              <span className="w-40 text-sm">{d.producto.nombre}</span>
              <LoteChip lote={d.lote} mostrarCantidad={false} />
              <span className="cifra text-sm">{formatoCantidad(d.cantidad, d.producto.unidad_medida)}</span>
              {d.subtotal && <span className="cifra text-sm text-acero">{formatoBs(d.subtotal)}</span>}
            </li>
          ))}
        </ul>
        {total > 0 && <p className="cifra text-lg font-semibold">Total {formatoBs(total)}</p>}
      </Resultado>
    );
  }

  return (
    <form onSubmit={enviar} className="p-4 sm:p-6" noValidate>
      <Campo id="cliente_id" etiqueta="Cliente" error={errores.cliente_id} requerido className="max-w-md">
        <Selector id="cliente_id" value={clienteId} onChange={(e) => setClienteId(e.target.value)} error={errores.cliente_id}>
          <option value="">Seleccione…</option>
          {base.datos.clientes.map((c) => (
            <option key={c.id} value={c.id}>
              {c.razon_social}
              {c.nit_ci ? ` (${c.nit_ci})` : ''}
            </option>
          ))}
        </Selector>
      </Campo>

      <fieldset className="mt-6">
        <legend className="mb-2 font-medium">Productos a despachar</legend>
        {errores.items && <p className="mb-2 text-sm text-vencido">{errores.items}</p>}

        <div className="space-y-3">
          {lineas.map((linea, i) => {
            const producto = productoPorId[linea.producto_id];
            const pedido = pedidoPorProducto[linea.producto_id] ?? 0;
            const disponible = producto ? Number(producto.stock_actual) : null;
            const excede = producto && pedido > disponible + 1e-9;
            return (
              <div key={linea.clave} className="grid gap-3 rounded-md p-3 ring-1 ring-linea sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-start">
                <Campo id={`producto-${linea.clave}`} etiqueta="Producto" error={errores[`items.${i}.producto_id`]}>
                  <Selector
                    id={`producto-${linea.clave}`}
                    value={linea.producto_id}
                    onChange={(e) => cambiarLinea(linea.clave, 'producto_id', e.target.value)}
                    error={errores[`items.${i}.producto_id`]}
                  >
                    <option value="">Seleccione…</option>
                    {base.datos.productos.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.nombre} · {formatoCantidad(p.stock_actual, p.unidad_medida)} disp.
                      </option>
                    ))}
                  </Selector>
                </Campo>
                <Campo
                  id={`cantidad-${linea.clave}`}
                  etiqueta={`Cantidad${producto ? ` (${UNIDADES[producto.unidad_medida]})` : ''}`}
                  error={errores[`items.${i}.cantidad`] ?? (excede ? `Solo hay ${formatoCantidad(disponible, producto.unidad_medida)} vigentes` : undefined)}
                >
                  <Entrada
                    id={`cantidad-${linea.clave}`}
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="0.001"
                    value={linea.cantidad}
                    onChange={(e) => cambiarLinea(linea.clave, 'cantidad', e.target.value)}
                    error={errores[`items.${i}.cantidad`] || excede}
                  />
                </Campo>
                <Campo id={`precio-${linea.clave}`} etiqueta="Precio unitario (Bs)" error={errores[`items.${i}.precio_unitario`]}>
                  <Entrada
                    id={`precio-${linea.clave}`}
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="0.01"
                    placeholder="Opcional"
                    value={linea.precio_unitario}
                    onChange={(e) => cambiarLinea(linea.clave, 'precio_unitario', e.target.value)}
                  />
                </Campo>
                <button
                  type="button"
                  onClick={() => setLineas((ls) => ls.filter((l) => l.clave !== linea.clave))}
                  disabled={lineas.length === 1}
                  className="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-acero hover:bg-vencido-fondo hover:text-vencido disabled:opacity-30 sm:mt-7"
                  aria-label={`Quitar producto ${i + 1}`}
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            );
          })}
        </div>

        <Boton variante="sutil" icono={Plus} className="mt-3" onClick={() => setLineas((ls) => [...ls, lineaVacia()])}>
          Agregar producto
        </Boton>
      </fieldset>

      <Campo id="observaciones" etiqueta="Observaciones" error={errores.observaciones} className="mt-4 max-w-2xl">
        <AreaTexto id="observaciones" value={observaciones} onChange={(e) => setObservaciones(e.target.value)} placeholder="Vehículo, chofer, guía de despacho…" />
      </Campo>

      <p className="mt-4 max-w-2xl text-sm text-acero">
        El sistema elige los lotes automáticamente: siempre sale primero el que vence antes. Los lotes vencidos nunca se despachan.
      </p>

      <Boton type="submit" icono={Truck} cargando={enviando} className="mt-4">
        Registrar despacho
      </Boton>
    </form>
  );
}
