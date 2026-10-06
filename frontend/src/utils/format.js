// ---------------------------------------------------------------------
//  Textos y formatos compartidos (valores tal como los devuelve la API)
// ---------------------------------------------------------------------

export const ROLES = {
  admin: 'Administrador',
  operador: 'Operador',
};

export const TIPOS_MOVIMIENTO = {
  ENTRADA_COMPRA: { etiqueta: 'Compra', entrada: true },
  CONSUMO_PRODUCCION: { etiqueta: 'Consumo en producción', entrada: false },
  INGRESO_PRODUCCION: { etiqueta: 'Ingreso de producción', entrada: true },
  SALIDA_VENTA: { etiqueta: 'Despacho a cliente', entrada: false },
  MERMA_DESECHO: { etiqueta: 'Merma', entrada: false },
  BAJA_VENCIMIENTO: { etiqueta: 'Baja por vencimiento', entrada: false },
  DEVOLUCION_CLIENTE: { etiqueta: 'Anulación de despacho', entrada: true },
  ANULACION_COMPRA: { etiqueta: 'Anulación de compra', entrada: false },
};

/** Horas en que un registro puede anularse (config sigesta.anulacion_horas) */
export const HORAS_ANULACION = 24;

/** true si el registro ('AAAA-MM-DD HH:MM:SS', hora local del servidor) está dentro del plazo */
export function dentroDePlazo(fechaHora, horas = HORAS_ANULACION) {
  if (!fechaHora) return false;
  const registrado = new Date(String(fechaHora).replace(' ', 'T'));
  return Date.now() - registrado.getTime() < horas * 3_600_000;
}

export const MOTIVOS_MERMA = {
  VENCIMIENTO: 'Vencimiento',
  DETERIORO: 'Deterioro',
  CONTAMINACION: 'Contaminación',
  DANO_EMPAQUE: 'Daño de empaque',
  ERROR_PROCESO: 'Error de proceso',
  CONTROL_CALIDAD: 'Rechazo de control de calidad',
  OTRO: 'Otro (detallar)',
};

export const TIPOS_ALERTA = {
  PREVENTIVA_VENCIMIENTO: 'Próximo a vencer',
  VENCIMIENTO_CRITICO: 'Vencido',
  STOCK_MINIMO: 'Stock bajo el mínimo',
};

export const UNIDADES = {
  kg: 'kg',
  gr: 'g',
  lt: 'L',
  ml: 'mL',
  unidad: 'u.',
};

const numero = new Intl.NumberFormat('es-BO', { maximumFractionDigits: 3 });
const dinero = new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function formatoCantidad(valor, unidad) {
  if (valor === null || valor === undefined || valor === '') return '—';
  const texto = numero.format(Number(valor));
  return unidad ? `${texto} ${UNIDADES[unidad] ?? unidad}` : texto;
}

export function formatoBs(valor) {
  if (valor === null || valor === undefined) return '—';
  return `Bs ${dinero.format(Number(valor))}`;
}

/** '2026-10-02' -> '02/10/2026' sin conversión de zona horaria */
export function formatoFecha(iso) {
  if (!iso) return '—';
  const [a, m, d] = String(iso).slice(0, 10).split('-');
  return `${d}/${m}/${a}`;
}

/** '2026-10-02 14:30:00' -> '02/10/2026 14:30' */
export function formatoFechaHora(texto) {
  if (!texto) return '—';
  const [fecha, hora = ''] = String(texto).replace('T', ' ').split(' ');
  return `${formatoFecha(fecha)} ${hora.slice(0, 5)}`.trim();
}

/** Fecha local de hoy en AAAA-MM-DD (no UTC) */
export function hoyISO(desplazamientoDias = 0) {
  const fecha = new Date();
  fecha.setDate(fecha.getDate() + desplazamientoDias);
  const m = String(fecha.getMonth() + 1).padStart(2, '0');
  const d = String(fecha.getDate()).padStart(2, '0');
  return `${fecha.getFullYear()}-${m}-${d}`;
}

/** Días hasta una fecha AAAA-MM-DD (negativo si ya pasó) */
export function diasHasta(iso) {
  if (!iso) return null;
  const [a, m, d] = iso.slice(0, 10).split('-').map(Number);
  const objetivo = new Date(a, m - 1, d);
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  return Math.round((objetivo - hoy) / 86_400_000);
}

// ---------------------------------------------------------------------
//  Semáforo FEFO
// ---------------------------------------------------------------------

export const FEFO = {
  vigente: { etiqueta: 'Vigente', barra: 'bg-vigente', texto: 'text-vigente', fondo: 'bg-vigente-fondo' },
  proximo: { etiqueta: 'Próximo a vencer', barra: 'bg-proximo', texto: 'text-proximo', fondo: 'bg-proximo-fondo' },
  vencido: { etiqueta: 'Vencido', barra: 'bg-vencido', texto: 'text-vencido', fondo: 'bg-vencido-fondo' },
  agotado: { etiqueta: 'Agotado', barra: 'bg-agotado', texto: 'text-agotado', fondo: 'bg-agotado-fondo' },
  anulado: { etiqueta: 'Anulado', barra: 'bg-tinta', texto: 'text-tinta', fondo: 'bg-agotado-fondo' },
};

export function estadoFefo(lote) {
  if (!lote) return 'vigente';
  const dias = lote.dias_para_vencer ?? diasHasta(lote.fecha_vencimiento);
  if (lote.estado === 'ANULADO') return 'anulado';
  if (lote.estado === 'AGOTADO') return 'agotado';
  if (lote.estado === 'VENCIDO' || (dias !== null && dias < 0)) return 'vencido';
  if (lote.estado === 'PROXIMO_A_VENCER') return 'proximo';
  return 'vigente';
}

export function textoDias(dias) {
  if (dias === null || dias === undefined) return '';
  if (dias < 0) return `venció hace ${Math.abs(dias)} días`;
  if (dias === 0) return 'vence hoy';
  if (dias === 1) return 'vence mañana';
  return `${dias} días`;
}
