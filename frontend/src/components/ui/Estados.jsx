import { CircleAlert, Inbox, LoaderCircle } from 'lucide-react';
import Boton from './Boton';

export function Cargando({ texto = 'Cargando datos…' }) {
  return (
    <div role="status" className="flex items-center gap-3 px-4 py-10 text-acero">
      <LoaderCircle className="size-5 animate-spin" aria-hidden />
      {texto}
    </div>
  );
}

export function AvisoError({ mensaje, alReintentar }) {
  return (
    <div role="alert" className="flex flex-wrap items-center gap-3 rounded-md border border-vencido/30 bg-vencido-fondo px-4 py-3 text-vencido">
      <CircleAlert className="size-5 shrink-0" aria-hidden />
      <p className="flex-1">{mensaje}</p>
      {alReintentar && (
        <Boton variante="secundario" onClick={alReintentar}>
          Reintentar
        </Boton>
      )}
    </div>
  );
}

export function Vacio({ titulo, children }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-12 text-center">
      <Inbox className="size-8 text-acero/60" aria-hidden />
      <p className="font-medium">{titulo}</p>
      {children && <div className="max-w-md text-sm text-acero">{children}</div>}
    </div>
  );
}
