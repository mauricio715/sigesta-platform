import { LoaderCircle } from 'lucide-react';

const VARIANTES = {
  primario: 'bg-petroleo text-white hover:bg-petroleo-oscuro disabled:bg-petroleo/50',
  secundario: 'bg-white text-tinta ring-1 ring-inset ring-linea hover:bg-concreto disabled:text-acero',
  peligro: 'bg-vencido text-white hover:brightness-95 disabled:bg-vencido/50',
  sutil: 'text-petroleo hover:bg-petroleo-claro disabled:text-acero',
};

/** Altura mínima de 44 px: se usa en tablets y con guantes en planta */
export default function Boton({
  variante = 'primario',
  cargando = false,
  icono: Icono,
  children,
  className = '',
  type = 'button',
  ...props
}) {
  return (
    <button
      type={type}
      disabled={cargando || props.disabled}
      className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-md px-4 font-medium transition-colors disabled:cursor-not-allowed ${VARIANTES[variante]} ${className}`}
      {...props}
    >
      {cargando ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : Icono && <Icono className="size-4" aria-hidden />}
      {children}
    </button>
  );
}
