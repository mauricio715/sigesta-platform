import { TriangleAlert } from 'lucide-react';
import Boton from './Boton';

/** Diálogo de confirmación para acciones que no se pueden deshacer */
export default function Confirmar({ abierto, titulo, mensaje, textoConfirmar, alConfirmar, alCancelar, cargando = false, peligro = true }) {
  if (!abierto) return null;

  return (
    <div className="fixed inset-0 z-[60] grid place-items-center bg-tinta/40 p-4" role="alertdialog" aria-modal="true" aria-labelledby="confirmar-titulo">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-2xl">
        <div className="flex gap-3">
          <TriangleAlert className={`mt-1 size-6 shrink-0 ${peligro ? 'text-vencido' : 'text-proximo'}`} aria-hidden />
          <div>
            <h2 id="confirmar-titulo" className="font-cond text-xl font-semibold">
              {titulo}
            </h2>
            <p className="mt-1 text-acero">{mensaje}</p>
          </div>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <Boton variante="secundario" onClick={alCancelar} disabled={cargando} autoFocus>
            Cancelar
          </Boton>
          <Boton variante={peligro ? 'peligro' : 'primario'} onClick={alConfirmar} cargando={cargando}>
            {textoConfirmar}
          </Boton>
        </div>
      </div>
    </div>
  );
}
