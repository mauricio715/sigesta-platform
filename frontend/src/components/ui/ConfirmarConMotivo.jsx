import { useEffect, useState } from 'react';
import { TriangleAlert } from 'lucide-react';
import Boton from './Boton';
import { AreaTexto, Campo } from './Campo';

const MINIMO = 10;

/** Confirmación que exige un motivo escrito (queda registrado en el Kardex) */
export default function ConfirmarConMotivo({ abierto, titulo, children, textoConfirmar, alConfirmar, alCancelar, cargando = false, error }) {
  const [motivo, setMotivo] = useState('');

  useEffect(() => {
    if (abierto) setMotivo('');
  }, [abierto]);

  if (!abierto) return null;

  const valido = motivo.trim().length >= MINIMO;

  return (
    <div className="fixed inset-0 z-[60] grid place-items-center bg-tinta/40 p-4" role="alertdialog" aria-modal="true" aria-labelledby="motivo-titulo">
      <form
        className="w-full max-w-lg rounded-lg bg-white p-6 shadow-2xl"
        onSubmit={(e) => {
          e.preventDefault();
          if (valido) alConfirmar(motivo.trim());
        }}
      >
        <div className="flex gap-3">
          <TriangleAlert className="mt-1 size-6 shrink-0 text-vencido" aria-hidden />
          <div className="min-w-0">
            <h2 id="motivo-titulo" className="font-cond text-xl font-semibold">
              {titulo}
            </h2>
            <div className="mt-2 space-y-2 text-acero">{children}</div>
          </div>
        </div>

        <Campo
          id="motivo-anulacion"
          etiqueta="Motivo"
          requerido
          error={error}
          ayuda={`Mínimo ${MINIMO} caracteres. Queda registrado con su usuario y no se puede editar.`}
          className="mt-5"
        >
          <AreaTexto id="motivo-anulacion" value={motivo} onChange={(e) => setMotivo(e.target.value)} maxLength={500} autoFocus error={error} />
        </Campo>

        <div className="mt-6 flex justify-end gap-2">
          <Boton variante="secundario" onClick={alCancelar} disabled={cargando}>
            Cancelar
          </Boton>
          <Boton type="submit" variante="peligro" cargando={cargando} disabled={!valido}>
            {textoConfirmar}
          </Boton>
        </div>
      </form>
    </div>
  );
}
