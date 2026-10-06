/** Superficie blanca para tablas y formularios */
export default function Panel({ titulo, acciones, children, className = '' }) {
  return (
    <section className={`rounded-lg bg-white ring-1 ring-linea ${className}`}>
      {(titulo || acciones) && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-linea px-4 py-3">
          {titulo && <h2 className="font-cond text-xl font-semibold">{titulo}</h2>}
          {acciones}
        </div>
      )}
      {children}
    </section>
  );
}
