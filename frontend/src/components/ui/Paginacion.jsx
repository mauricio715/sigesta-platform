import { ChevronLeft, ChevronRight } from 'lucide-react';

export default function Paginacion({ meta, alCambiar }) {
  if (!meta || meta.last_page <= 1) return null;

  return (
    <nav className="flex items-center justify-between gap-4 border-t border-linea px-4 py-3 text-sm" aria-label="Paginación">
      <p className="text-acero">
        Página <span className="cifra text-tinta">{meta.current_page}</span> de <span className="cifra text-tinta">{meta.last_page}</span>
        <span className="hidden sm:inline"> ({meta.total} registros)</span>
      </p>
      <div className="flex gap-2">
        <button
          type="button"
          className="inline-flex min-h-10 items-center gap-1 rounded-md px-3 ring-1 ring-linea hover:bg-concreto disabled:opacity-40"
          disabled={meta.current_page <= 1}
          onClick={() => alCambiar(meta.current_page - 1)}
        >
          <ChevronLeft className="size-4" aria-hidden /> Anterior
        </button>
        <button
          type="button"
          className="inline-flex min-h-10 items-center gap-1 rounded-md px-3 ring-1 ring-linea hover:bg-concreto disabled:opacity-40"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => alCambiar(meta.current_page + 1)}
        >
          Siguiente <ChevronRight className="size-4" aria-hidden />
        </button>
      </div>
    </nav>
  );
}
