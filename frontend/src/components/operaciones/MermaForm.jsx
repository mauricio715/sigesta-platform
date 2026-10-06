import { useState } from 'react';
import { Trash2, TriangleAlert } from 'lucide-react';
import { operacionesApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import Boton from '../ui/Boton';
import LoteChip from '../ui/LoteChip';
import { AreaTexto, Campo, Entrada, Selector } from '../ui/Campo';
import BuscadorLote from './BuscadorLote';
import Resultado from './Resultado';
import { MOTIVOS_MERMA, TIPOS_MOVIMIENTO, UNIDADES, estadoFefo, formatoCantidad, formatoFecha } from '../../utils/format';

export default function MermaForm({ codigoInicial = '' }) {
  const { notificar } = useToast();
  const [lote, setLote] = useState(null);
  const [cantidad, setCantidad] = useState('');
  const [motivo, setMotivo] = useState('');
  const [observacion, setObservacion] = useState('');
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);
  const [movimiento, setMovimiento] = useState(null);

  const item = lote ? lote.insumo ?? lote.producto : null;
  const vencido = lote && estadoFefo(lote) === 'vencido';
  const agotado = lote && Number(lote.cantidad_actual) <= 0;

  const seleccionar = (l) => {
    setLote(l);
    setErrores({});
    setMotivo(estadoFefo(l) === 'vencido' ? 'VENCIMIENTO' : '');
  };

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setErrores({});

    try {
      const respuesta = await operacionesApi.merma({
        lote_id: lote?.id,
        cantidad,
        motivo,
        ...(observacion.trim() ? { observacion: observacion.trim() } : {}),
      });
      setMovimiento(respuesta.data);
      notificar(respuesta.message);
    } catch (e) {
      setErrores(erroresDeCampo(e));
      notificar(mensajeError(e), 'error');
    } finally {
      setEnviando(false);
    }
  };

  if (movimiento) {
    const loteFinal = movimiento.lote;
    const itemFinal = loteFinal.insumo ?? loteFinal.producto;
    return (
      <Resultado
        titulo="Baja registrada"
        alContinuar={() => {
          setMovimiento(null);
          setLote(null);
          setCantidad('');
          setMotivo('');
          setObservacion('');
        }}
      >
        <p>
          {TIPOS_MOVIMIENTO[movimiento.tipo_movimiento]?.etiqueta}: <strong className="cifra">{formatoCantidad(movimiento.cantidad, itemFinal?.unidad_medida)}</strong>{' '}
          de {itemFinal?.nombre} ({MOTIVOS_MERMA[movimiento.categoria_merma]?.toLowerCase()}).
        </p>
        <LoteChip lote={loteFinal} unidad={itemFinal?.unidad_medida} destacado />
        <p className="text-sm text-acero">
          Stock utilizable de {itemFinal?.nombre}: <span className="cifra">{formatoCantidad(itemFinal?.stock_actual, itemFinal?.unidad_medida)}</span>
        </p>
      </Resultado>
    );
  }

  return (
    <form onSubmit={enviar} className="p-4 sm:p-6" noValidate>
      <div className="max-w-xl">
        <BuscadorLote codigoInicial={codigoInicial} alSeleccionar={seleccionar} etiqueta="Lote a dar de baja" />
        {errores.lote_id && <p className="mt-1 text-sm text-vencido">{errores.lote_id}</p>}
      </div>

      {lote && (
        <>
          <div className="mt-6 rounded-md bg-concreto/60 p-4">
            <p className="font-medium">{item?.nombre}</p>
            <div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-acero">
              <LoteChip lote={lote} unidad={item?.unidad_medida} destacado />
              <span>Vence el {formatoFecha(lote.fecha_vencimiento)}</span>
              {lote.proveedor && <span>Proveedor: {lote.proveedor.razon_social}</span>}
            </div>
            {agotado && <p className="mt-2 text-sm text-vencido">Este lote no tiene saldo: no se puede dar de baja.</p>}
          </div>

          <div className="mt-6 grid gap-4 sm:grid-cols-2">
            <Campo
              id="cantidad"
              etiqueta={`Cantidad a dar de baja${item ? ` (${UNIDADES[item.unidad_medida]})` : ''}`}
              error={errores.cantidad}
              ayuda={`Saldo del lote: ${formatoCantidad(lote.cantidad_actual, item?.unidad_medida)}`}
              requerido
            >
              <div className="flex gap-2">
                <Entrada
                  id="cantidad"
                  type="number"
                  inputMode="decimal"
                  min="0"
                  max={lote.cantidad_actual}
                  step="0.001"
                  value={cantidad}
                  onChange={(e) => setCantidad(e.target.value)}
                  error={errores.cantidad}
                />
                <Boton variante="secundario" onClick={() => setCantidad(String(Number(lote.cantidad_actual)))} disabled={agotado}>
                  Todo
                </Boton>
              </div>
            </Campo>

            <Campo id="motivo" etiqueta="Motivo" error={errores.motivo} requerido>
              <Selector id="motivo" value={motivo} onChange={(e) => setMotivo(e.target.value)} error={errores.motivo}>
                <option value="">Seleccione…</option>
                {Object.entries(MOTIVOS_MERMA).map(([valor, texto]) => (
                  <option key={valor} value={valor} disabled={valor === 'VENCIMIENTO' && !vencido}>
                    {texto}
                    {valor === 'VENCIMIENTO' && !vencido ? ' (el lote aún no vence)' : ''}
                  </option>
                ))}
              </Selector>
            </Campo>

            <Campo
              id="observacion"
              etiqueta="Observación"
              error={errores.observacion}
              requerido={motivo === 'OTRO'}
              ayuda="Describa lo que se observó; queda registrado en el Kardex"
              className="sm:col-span-2"
            >
              <AreaTexto id="observacion" value={observacion} onChange={(e) => setObservacion(e.target.value)} error={errores.observacion} />
            </Campo>
          </div>

          {motivo === 'CONTAMINACION' && (
            <p className="mt-4 flex max-w-2xl items-start gap-2 rounded-md bg-proximo-fondo px-4 py-3 text-sm text-proximo">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
              Ante una contaminación, revise también la trazabilidad del lote: los productos ya fabricados con él pueden requerir retención o retiro.
            </p>
          )}

          <Boton type="submit" variante="peligro" icono={Trash2} cargando={enviando} className="mt-6" disabled={agotado}>
            Registrar baja
          </Boton>
        </>
      )}
    </form>
  );
}
