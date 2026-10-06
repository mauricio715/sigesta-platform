export const claseControl =
  'w-full min-h-11 rounded-md border border-linea bg-white px-3 text-base text-tinta placeholder:text-acero/70 ' +
  'focus:border-petroleo focus:outline-none focus:ring-2 focus:ring-petroleo/30 disabled:bg-concreto disabled:text-acero ' +
  'aria-[invalid=true]:border-vencido aria-[invalid=true]:ring-vencido/20';

/** Etiqueta + control + ayuda/error asociados por id (accesible) */
export function Campo({ id, etiqueta, error, ayuda, requerido = false, children, className = '' }) {
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-tinta">
        {etiqueta}
        {requerido && (
          <span className="ml-0.5 text-vencido" aria-hidden>
            *
          </span>
        )}
      </label>
      {children}
      {error ? (
        <p id={`${id}-error`} className="mt-1 text-sm text-vencido">
          {error}
        </p>
      ) : (
        ayuda && (
          <p id={`${id}-ayuda`} className="mt-1 text-sm text-acero">
            {ayuda}
          </p>
        )
      )}
    </div>
  );
}

export function Entrada({ id, error, className = '', ...props }) {
  return (
    <input
      id={id}
      aria-invalid={Boolean(error)}
      aria-describedby={error ? `${id}-error` : undefined}
      className={`${claseControl} ${className}`}
      {...props}
    />
  );
}

export function Selector({ id, error, children, className = '', ...props }) {
  return (
    <select
      id={id}
      aria-invalid={Boolean(error)}
      aria-describedby={error ? `${id}-error` : undefined}
      className={`${claseControl} pr-8 ${className}`}
      {...props}
    >
      {children}
    </select>
  );
}

export function AreaTexto({ id, error, className = '', ...props }) {
  return (
    <textarea
      id={id}
      aria-invalid={Boolean(error)}
      aria-describedby={error ? `${id}-error` : undefined}
      className={`${claseControl} min-h-20 py-2 ${className}`}
      {...props}
    />
  );
}
