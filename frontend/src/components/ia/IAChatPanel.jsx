import { useEffect, useRef, useState } from 'react';
import { CircleAlert, Database, SendHorizontal, Trash2 } from 'lucide-react';
import { useIAChat } from '../../context/IAChatContext';
import Markdown from './Markdown';

const HERRAMIENTAS = {
  consultar_resumen_inventario: 'Resumen de inventario',
  consultar_alertas_activas: 'Alertas activas',
  consultar_kardex: 'Kardex',
  buscar_lotes: 'Búsqueda de lotes',
  rastrear_insumo_hacia_adelante: 'Trazabilidad hacia adelante',
  rastrear_producto_hacia_atras: 'Trazabilidad hacia atrás',
};

const SUGERENCIAS = [
  '¿Qué lotes de lácteos debo consumir primero hoy según FEFO?',
  '¿Qué alertas críticas tengo pendientes y qué debo hacer?',
  '¿Qué insumos están bajo el stock mínimo?',
  '¿Qué mermas se registraron esta semana y por qué motivo?',
];

function Consultas({ herramientas }) {
  if (!herramientas?.length) return null;

  return (
    <details className="mt-2 text-sm text-acero">
      <summary className="inline-flex cursor-pointer items-center gap-1.5 hover:text-tinta">
        <Database className="size-3.5" aria-hidden />
        Consultó {herramientas.length === 1 ? '1 fuente' : `${herramientas.length} fuentes`} de SI-GESTA
      </summary>
      <ul className="mt-2 space-y-1 border-l-2 border-linea pl-3">
        {herramientas.map((h, i) => {
          const args = Object.entries(h.argumentos ?? {});
          return (
            <li key={i} className={h.exito ? '' : 'text-vencido'}>
              {HERRAMIENTAS[h.nombre] ?? h.nombre}
              {args.length > 0 && <span className="cifra"> ({args.map(([k, v]) => `${k}: ${v}`).join(', ')})</span>}
              {!h.exito && ' · sin resultado'}
            </li>
          );
        })}
      </ul>
    </details>
  );
}

export default function IAChatPanel({ alto = 'h-full', autoFoco = false }) {
  const { mensajes, enviando, enviar, limpiar } = useIAChat();
  const [texto, setTexto] = useState('');
  const finRef = useRef(null);
  const entradaRef = useRef(null);

  useEffect(() => {
    finRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [mensajes, enviando]);

  useEffect(() => {
    if (autoFoco) entradaRef.current?.focus();
  }, [autoFoco]);

  const mandar = (contenido = texto) => {
    if (!contenido.trim() || enviando) return;
    enviar(contenido);
    setTexto('');
  };

  return (
    <div className={`flex min-h-0 flex-col ${alto}`}>
      <div className="flex-1 overflow-y-auto px-4 py-4" aria-live="polite">
        {mensajes.length === 0 ? (
          <div className="mx-auto max-w-md py-6">
            <p className="font-cond text-xl font-semibold">Consulte el estado de la planta</p>
            <p className="mt-1 text-sm text-acero">
              El asistente responde solo con datos reales de SI-GESTA (inventario, alertas, Kardex y trazabilidad). No registra ni modifica nada.
            </p>
            <ul className="mt-4 space-y-2">
              {SUGERENCIAS.map((s) => (
                <li key={s}>
                  <button type="button" onClick={() => mandar(s)} className="w-full rounded-md px-3 py-2 text-left text-sm ring-1 ring-linea hover:bg-concreto">
                    {s}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <ol className="space-y-4">
            {mensajes.map((m) =>
              m.rol === 'usuario' ? (
                <li key={m.id} className="flex justify-end">
                  <p className="max-w-[85%] whitespace-pre-wrap rounded-lg rounded-br-sm bg-petroleo px-4 py-2.5 text-white">{m.contenido}</p>
                </li>
              ) : (
                <li key={m.id} className="max-w-[95%]">
                  {m.error ? (
                    <p className="flex items-start gap-2 rounded-lg bg-vencido-fondo px-4 py-3 text-sm text-vencido">
                      <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                      {m.contenido}
                    </p>
                  ) : (
                    <div className="rounded-lg rounded-bl-sm bg-white px-4 py-3 ring-1 ring-linea">
                      <Markdown>{m.contenido}</Markdown>
                      <Consultas herramientas={m.herramientas} />
                    </div>
                  )}
                </li>
              ),
            )}
            {enviando && (
              <li className="flex items-center gap-3 text-sm text-acero" role="status">
                <span className="flex gap-1" aria-hidden>
                  {[0, 1, 2].map((i) => (
                    <span key={i} className="size-2 rounded-full bg-petroleo/60 motion-safe:animate-pulse" style={{ animationDelay: `${i * 180}ms` }} />
                  ))}
                </span>
                Consultando datos de la planta…
              </li>
            )}
          </ol>
        )}
        <div ref={finRef} />
      </div>

      <form
        className="border-t border-linea bg-white p-3"
        onSubmit={(e) => {
          e.preventDefault();
          mandar();
        }}
      >
        <div className="flex items-end gap-2">
          <label htmlFor="pregunta-ia" className="sr-only">
            Pregunta para el asistente
          </label>
          <textarea
            id="pregunta-ia"
            ref={entradaRef}
            rows={2}
            maxLength={2000}
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                mandar();
              }
            }}
            placeholder="Escriba su pregunta. Enter envía, Mayús + Enter agrega una línea."
            className="max-h-40 min-h-12 flex-1 resize-y rounded-md border border-linea px-3 py-2 focus:border-petroleo focus:outline-none focus:ring-2 focus:ring-petroleo/30"
          />
          <button
            type="submit"
            disabled={enviando || !texto.trim()}
            className="inline-flex size-12 items-center justify-center rounded-md bg-petroleo text-white hover:bg-petroleo-oscuro disabled:bg-petroleo/40"
            aria-label="Enviar pregunta"
          >
            <SendHorizontal className="size-5" />
          </button>
        </div>
        {mensajes.length > 0 && (
          <button type="button" onClick={limpiar} disabled={enviando} className="mt-2 inline-flex items-center gap-1 text-sm text-acero hover:text-tinta">
            <Trash2 className="size-3.5" aria-hidden /> Nueva conversación
          </button>
        )}
      </form>
    </div>
  );
}
