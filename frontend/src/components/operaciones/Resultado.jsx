import { CircleCheck } from 'lucide-react';
import Boton from '../ui/Boton';

/** Confirmación de una operación registrada, con acción para registrar otra */
export default function Resultado({ titulo, children, alContinuar, textoContinuar = 'Registrar otra' }) {
  return (
    <div className="p-4 sm:p-6" role="status">
      <div className="flex items-start gap-3">
        <CircleCheck className="mt-0.5 size-6 shrink-0 text-vigente" aria-hidden />
        <div className="min-w-0 flex-1">
          <h3 className="font-cond text-2xl font-semibold">{titulo}</h3>
          <div className="mt-3 space-y-3">{children}</div>
          <Boton variante="secundario" className="mt-6" onClick={alContinuar}>
            {textoContinuar}
          </Boton>
        </div>
      </div>
    </div>
  );
}
