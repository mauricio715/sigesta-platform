export default function Encabezado({ titulo, descripcion, acciones }) {
  return (
    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="max-w-2xl">
        <h1 className="font-cond text-3xl font-semibold leading-tight">{titulo}</h1>
        {descripcion && <p className="mt-1 text-acero">{descripcion}</p>}
      </div>
      {acciones && <div className="flex flex-wrap gap-2">{acciones}</div>}
    </header>
  );
}
