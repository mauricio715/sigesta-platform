const ESTILOS = {
  ALTA: 'bg-vencido text-white',
  MEDIA: 'bg-proximo-fondo text-proximo ring-1 ring-inset ring-proximo/30',
  BAJA: 'bg-agotado-fondo text-acero',
};

const TEXTOS = { ALTA: 'Alta', MEDIA: 'Media', BAJA: 'Baja' };

export default function Prioridad({ nivel }) {
  return (
    <span className={`inline-flex rounded px-2 py-0.5 text-sm font-semibold ${ESTILOS[nivel] ?? ESTILOS.BAJA}`}>
      {TEXTOS[nivel] ?? nivel}
    </span>
  );
}
