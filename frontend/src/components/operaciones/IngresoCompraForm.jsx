import { useState } from 'react';
import { PackagePlus } from 'lucide-react';
import { catalogoApi, operacionesApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useCargar } from '../../hooks/useCargar';
import { useToast } from '../../context/ToastContext';
import Boton from '../ui/Boton';
import LoteChip from '../ui/LoteChip';
import { AreaTexto, Campo, Entrada, Selector } from '../ui/Campo';
import { AvisoError, Cargando } from '../ui/Estados';
import Resultado from './Resultado';
import { UNIDADES, formatoCantidad, hoyISO } from '../../utils/format';

const VACIO = {
  insumo_id: '',
  proveedor_id: '',
  cantidad: '',
  fecha_fabricacion: hoyISO(),
  fecha_vencimiento: '',
  codigo_lote_proveedor: '',
  documento_referencia: '',
  observaciones: '',
};

export default function IngresoCompraForm({ insumoInicial = '' }) {
  const { notificar } = useToast();
  const [form, setForm] = useState({ ...VACIO, insumo_id: insumoInicial });
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState(null);

  const catalogo = useCargar(async () => {
    const [insumos, proveedores] = await Promise.all([catalogoApi.insumos(), catalogoApi.proveedores()]);
    return { insumos: insumos.data, proveedores: proveedores.data };
  }, []);

  const cambiar = (campo) => (e) => setForm((f) => ({ ...f, [campo]: e.target.value }));
  const insumo = catalogo.datos?.insumos.find((i) => String(i.id) === String(form.insumo_id));

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setErrores({});

    const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));

    try {
      const respuesta = await operacionesApi.ingresoCompra(payload);
      setResultado(respuesta.data);
      notificar(respuesta.message);
    } catch (e) {
      setErrores(erroresDeCampo(e));
      notificar(mensajeError(e), 'error');
    } finally {
      setEnviando(false);
    }
  };

  if (catalogo.cargando) return <Cargando texto="Cargando insumos y proveedores…" />;
  if (catalogo.error) return <div className="p-4"><AvisoError mensaje={catalogo.error} alReintentar={catalogo.recargar} /></div>;

  if (resultado) {
    const lote = resultado.lote;
    return (
      <Resultado
        titulo="Compra recepcionada"
        alContinuar={() => {
          setResultado(null);
          setForm(VACIO);
        }}
      >
        <p>
          Se creó el lote de <strong>{lote.insumo?.nombre}</strong> proveniente de {lote.proveedor?.razon_social}:
        </p>
        <LoteChip lote={lote} unidad={lote.insumo?.unidad_medida} destacado />
        {lote.estado === 'PROXIMO_A_VENCER' && (
          <p className="text-sm text-proximo">El lote llegó dentro de su ventana de alerta y se generó un aviso preventivo.</p>
        )}
        <p className="text-sm text-acero">
          Stock utilizable actual: <span className="cifra">{formatoCantidad(lote.insumo?.stock_actual, lote.insumo?.unidad_medida)}</span>
        </p>
      </Resultado>
    );
  }

  return (
    <form onSubmit={enviar} className="grid gap-4 p-4 sm:grid-cols-2 sm:p-6" noValidate>
      <Campo id="insumo_id" etiqueta="Insumo recibido" error={errores.insumo_id} requerido>
        <Selector id="insumo_id" value={form.insumo_id} onChange={cambiar('insumo_id')} error={errores.insumo_id}>
          <option value="">Seleccione…</option>
          {catalogo.datos.insumos.map((i) => (
            <option key={i.id} value={i.id}>
              {i.nombre} ({i.codigo})
            </option>
          ))}
        </Selector>
      </Campo>

      <Campo id="proveedor_id" etiqueta="Proveedor" error={errores.proveedor_id} requerido>
        <Selector id="proveedor_id" value={form.proveedor_id} onChange={cambiar('proveedor_id')} error={errores.proveedor_id}>
          <option value="">Seleccione…</option>
          {catalogo.datos.proveedores.map((p) => (
            <option key={p.id} value={p.id}>
              {p.razon_social}
            </option>
          ))}
        </Selector>
      </Campo>

      <Campo id="cantidad" etiqueta={`Cantidad recibida${insumo ? ` (${UNIDADES[insumo.unidad_medida]})` : ''}`} error={errores.cantidad} requerido>
        <Entrada id="cantidad" type="number" inputMode="decimal" min="0" step="0.001" value={form.cantidad} onChange={cambiar('cantidad')} error={errores.cantidad} />
      </Campo>

      <Campo id="codigo_lote_proveedor" etiqueta="Lote del proveedor" ayuda="El código impreso en el empaque" error={errores.codigo_lote_proveedor}>
        <Entrada id="codigo_lote_proveedor" value={form.codigo_lote_proveedor} onChange={cambiar('codigo_lote_proveedor')} error={errores.codigo_lote_proveedor} />
      </Campo>

      <Campo id="fecha_fabricacion" etiqueta="Fecha de fabricación" error={errores.fecha_fabricacion}>
        <Entrada id="fecha_fabricacion" type="date" max={hoyISO()} value={form.fecha_fabricacion} onChange={cambiar('fecha_fabricacion')} error={errores.fecha_fabricacion} />
      </Campo>

      <Campo id="fecha_vencimiento" etiqueta="Fecha de vencimiento" error={errores.fecha_vencimiento} ayuda="Un lote vencido debe rechazarse en recepción" requerido>
        <Entrada id="fecha_vencimiento" type="date" min={hoyISO()} value={form.fecha_vencimiento} onChange={cambiar('fecha_vencimiento')} error={errores.fecha_vencimiento} />
      </Campo>

      <Campo id="documento_referencia" etiqueta="Factura o guía de remisión" error={errores.documento_referencia}>
        <Entrada id="documento_referencia" value={form.documento_referencia} onChange={cambiar('documento_referencia')} error={errores.documento_referencia} />
      </Campo>

      <Campo id="observaciones" etiqueta="Observaciones de recepción" error={errores.observaciones} className="sm:col-span-2">
        <AreaTexto id="observaciones" value={form.observaciones} onChange={cambiar('observaciones')} error={errores.observaciones} placeholder="Temperatura de llegada, estado del empaque…" />
      </Campo>

      <div className="sm:col-span-2">
        <Boton type="submit" icono={PackagePlus} cargando={enviando}>
          Registrar compra
        </Boton>
      </div>
    </form>
  );
}
