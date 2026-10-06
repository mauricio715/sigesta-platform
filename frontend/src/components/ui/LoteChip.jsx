import { FEFO, estadoFefo, formatoCantidad, formatoFecha, textoDias } from '../../utils/format';

/**
 * Lote con forma de etiqueta impresa: franja de color FEFO a la izquierda,
 * código en letra condensada y estado en texto (el color nunca va solo).
 */
export default function LoteChip({ lote, unidad, mostrarCantidad = true, destacado = false }) {
  if (!lote) return null;

  const estado = estadoFefo(lote);
  const estilo = FEFO[estado];
  const dias = lote.dias_para_vencer;
  const detalle = estado === 'agotado' || estado === 'anulado' ? estado : dias !== undefined && dias !== null ? textoDias(dias) : formatoFecha(lote.fecha_vencimiento);

  return (
    <span
      className={`inline-flex items-stretch overflow-hidden rounded border bg-white text-sm leading-none ${
        destacado ? 'border-tinta/40 shadow-sm' : 'border-linea'
      }`}
      title={`${estilo.etiqueta} · vence ${formatoFecha(lote.fecha_vencimiento)}`}
    >
      <span className={`w-1.5 shrink-0 ${estilo.barra}`} aria-hidden />
      <span className="cifra px-2 py-1.5 font-semibold tracking-wide">{lote.codigo_lote}</span>
      <span className={`cifra px-2 py-1.5 font-medium ${estilo.fondo} ${estilo.texto}`}>
        <span className="sr-only">{estilo.etiqueta}, </span>
        {detalle}
      </span>
      {mostrarCantidad && lote.cantidad_actual !== undefined && (
        <span className="cifra border-l border-linea px-2 py-1.5 text-acero">{formatoCantidad(lote.cantidad_actual, unidad)}</span>
      )}
    </span>
  );
}

export function LeyendaFefo() {
  return (
    <ul className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-acero" aria-label="Significado de colores">
      {['vigente', 'proximo', 'vencido', 'agotado'].map((clave) => (
        <li key={clave} className="flex items-center gap-2">
          <span className={`h-3 w-1.5 rounded-sm ${FEFO[clave].barra}`} aria-hidden />
          {FEFO[clave].etiqueta}
        </li>
      ))}
    </ul>
  );
}
