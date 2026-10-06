import { useMemo, useState } from 'react';
import { Factory, TriangleAlert } from 'lucide-react';
import { catalogoApi, inventarioApi, operacionesApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useCargar } from '../../hooks/useCargar';
import { useToast } from '../../context/ToastContext';
import Boton from '../ui/Boton';
import LoteChip from '../ui/LoteChip';
import { Campo, Entrada, Selector } from '../ui/Campo';
import { AvisoError, Cargando } from '../ui/Estados';
import Resultado from './Resultado';
import { UNIDADES, formatoCantidad } from '../../utils/format';

export default function ProduccionForm() {
  const { notificar } = useToast();
  const [productoId, setProductoId] = useState('');
  const [cantidad, setCantidad] = useState('');
  const [codigoLote, setCodigoLote] = useState('');
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);
  const [orden, setOrden] = useState(null);

  const base = useCargar(async () => {
    const [productos, resumen] = await Promise.all([catalogoApi.productos(), inventarioApi.resumen({ tipo: 'INSUMO' })]);
    const stock = Object.fromEntries(resumen.data.insumos.map((i) => [i.id, Number(i.stock_actual)]));
    return { productos: productos.data, stock };
  }, [orden]);

  const receta = useCargar(async () => {
    if (!productoId) return null;
    const respuesta = await catalogoApi.recetasActivas(productoId);
    return respuesta.data[0] ?? null;
  }, [productoId]);

  const producto = base.datos?.productos.find((p) => String(p.id) === String(productoId));

  // Vista previa del consumo según BOM: cantidad_receta × (a producir / rendimiento)
  const requerimientos = useMemo(() => {
    const r = receta.datos;
    const n = Number(cantidad);
    if (!r || !n || n <= 0) return [];
    const factor = n / Number(r.rendimiento_base);
    return r.formula.map((linea) => {
      const requerido = Number(linea.cantidad_requerida) * factor;
      const disponible = base.datos?.stock[linea.insumo_id] ?? 0;
      return { ...linea, requerido, disponible, falta: requerido > disponible + 1e-9 };
    });
  }, [receta.datos, cantidad, base.datos]);

  const hayFaltantes = requerimientos.some((r) => r.falta);

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setErrores({});

    try {
      const respuesta = await operacionesApi.produccion({
        producto_id: productoId ? Number(productoId) : undefined,
        cantidad,
        ...(codigoLote.trim() ? { codigo_lote: codigoLote.trim() } : {}),
      });
      setOrden(respuesta.data);
      notificar(respuesta.message);
    } catch (e) {
      setErrores(erroresDeCampo(e));
      notificar(mensajeError(e), 'error');
    } finally {
      setEnviando(false);
    }
  };

  if (base.cargando && !base.datos) return <Cargando texto="Cargando productos y stock…" />;
  if (base.error) return <div className="p-4"><AvisoError mensaje={base.error} alReintentar={base.recargar} /></div>;

  if (orden) {
    const unidad = orden.producto?.unidad_medida;
    return (
      <Resultado
        titulo={`Orden ${orden.codigo_orden} completada`}
        alContinuar={() => {
          setOrden(null);
          setCantidad('');
          setCodigoLote('');
        }}
        textoContinuar="Registrar otra orden"
      >
        <p>
          Se fabricaron <strong className="cifra">{formatoCantidad(orden.cantidad_producida, unidad)}</strong> de {orden.producto?.nombre} con la receta “
          {orden.receta?.nombre_receta}”. Lote generado:
        </p>
        <LoteChip lote={orden.lote_producto} unidad={unidad} destacado />
        <div>
          <p className="mb-2 font-medium">Lotes de insumo consumidos (FEFO)</p>
          <ul className="space-y-2">
            {orden.consumos.map((c) => (
              <li key={`${c.lote.id}`} className="flex flex-wrap items-center gap-3">
                <span className="w-36 text-sm">{c.insumo.nombre}</span>
                <LoteChip lote={c.lote} mostrarCantidad={false} />
                <span className="cifra text-sm">{formatoCantidad(c.cantidad_consumida, c.insumo.unidad_medida)}</span>
              </li>
            ))}
          </ul>
        </div>
      </Resultado>
    );
  }

  return (
    <form onSubmit={enviar} className="p-4 sm:p-6" noValidate>
      <div className="grid gap-4 sm:grid-cols-3">
        <Campo id="producto_id" etiqueta="Producto a fabricar" error={errores.producto_id} requerido>
          <Selector id="producto_id" value={productoId} onChange={(e) => setProductoId(e.target.value)} error={errores.producto_id}>
            <option value="">Seleccione…</option>
            {base.datos.productos.map((p) => (
              <option key={p.id} value={p.id}>
                {p.nombre}
              </option>
            ))}
          </Selector>
        </Campo>

        <Campo id="cantidad" etiqueta={`Cantidad a producir${producto ? ` (${UNIDADES[producto.unidad_medida]})` : ''}`} error={errores.cantidad} requerido>
          <Entrada id="cantidad" type="number" inputMode="decimal" min="0" step="0.001" value={cantidad} onChange={(e) => setCantidad(e.target.value)} error={errores.cantidad} />
        </Campo>

        <Campo id="codigo_lote" etiqueta="Código de lote" ayuda="Opcional: si se deja vacío se genera solo" error={errores.codigo_lote}>
          <Entrada id="codigo_lote" value={codigoLote} onChange={(e) => setCodigoLote(e.target.value)} error={errores.codigo_lote} autoComplete="off" />
        </Campo>
      </div>

      {productoId && (
        <div className="mt-6 rounded-md ring-1 ring-linea">
          <div className="border-b border-linea bg-concreto/60 px-4 py-3">
            <p className="font-medium">
              {receta.cargando
                ? 'Cargando receta…'
                : receta.datos
                  ? `Receta activa: ${receta.datos.nombre_receta} (rinde ${formatoCantidad(receta.datos.rendimiento_base, producto?.unidad_medida)})`
                  : 'Este producto no tiene una receta activa. Pida al administrador que registre una.'}
            </p>
          </div>
          {requerimientos.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[30rem] text-left">
                <thead className="text-sm text-acero">
                  <tr>
                    <th scope="col" className="px-4 py-2 font-medium">Insumo</th>
                    <th scope="col" className="px-4 py-2 text-right font-medium">Se consumirá</th>
                    <th scope="col" className="px-4 py-2 text-right font-medium">Disponible</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-linea">
                  {requerimientos.map((r) => (
                    <tr key={r.insumo_id} className={r.falta ? 'bg-vencido-fondo' : ''}>
                      <td className="px-4 py-2">{r.nombre}</td>
                      <td className="cifra px-4 py-2 text-right font-semibold">{formatoCantidad(r.requerido.toFixed(3), r.unidad_medida)}</td>
                      <td className={`cifra px-4 py-2 text-right ${r.falta ? 'font-semibold text-vencido' : 'text-acero'}`}>
                        {formatoCantidad(r.disponible, r.unidad_medida)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {hayFaltantes && (
            <p className="flex items-center gap-2 border-t border-linea px-4 py-3 text-sm text-vencido">
              <TriangleAlert className="size-4 shrink-0" aria-hidden />
              No hay stock vigente suficiente de los insumos marcados. La orden será rechazada sin descontar nada.
            </p>
          )}
        </div>
      )}

      <Boton type="submit" icono={Factory} cargando={enviando} className="mt-6" disabled={!receta.datos}>
        Ejecutar orden de producción
      </Boton>
    </form>
  );
}
