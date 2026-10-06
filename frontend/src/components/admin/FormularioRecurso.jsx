import { useState } from 'react';
import { Save } from 'lucide-react';
import Boton from '../ui/Boton';
import { AreaTexto, Campo, Entrada, Selector } from '../ui/Campo';

/**
 * Formulario construido a partir de la configuración de campos:
 *   { nombre, etiqueta, tipo: 'texto'|'numero'|'email'|'selector'|'area', requerido,
 *     opciones, ayuda, paso, min, max, anulable, ancho: 'completo' }
 */
export function valoresIniciales(campos, item) {
  return Object.fromEntries(
    campos.map((c) => {
      const valor = item?.[c.nombre];
      if (valor === null || valor === undefined) return [c.nombre, c.porDefecto ?? ''];
      return [c.nombre, c.tipo === 'numero' ? String(Number(valor)) : String(valor)];
    }),
  );
}

/** Vacíos: null si el campo es anulable (permite borrar el dato); si no, se omite */
export function construirPayload(campos, valores) {
  const payload = {};
  campos.forEach((c) => {
    const valor = typeof valores[c.nombre] === 'string' ? valores[c.nombre].trim() : valores[c.nombre];
    if (valor === '' || valor === undefined) {
      if (c.anulable) payload[c.nombre] = null;
      return;
    }
    payload[c.nombre] = c.tipo === 'numero' ? Number(valor) : valor;
  });
  return payload;
}

export default function FormularioRecurso({ campos, item, errores = {}, enviando, alEnviar, textoBoton }) {
  const [valores, setValores] = useState(() => valoresIniciales(campos, item));

  const cambiar = (nombre) => (e) => setValores((v) => ({ ...v, [nombre]: e.target.value }));

  return (
    <form
      noValidate
      className="grid gap-4 p-5 sm:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault();
        alEnviar(construirPayload(campos, valores));
      }}
    >
      {campos.map((c) => {
        const id = `campo-${c.nombre}`;
        const comun = { id, value: valores[c.nombre], onChange: cambiar(c.nombre), error: errores[c.nombre], disabled: c.soloLectura?.(item) };
        return (
          <Campo
            key={c.nombre}
            id={id}
            etiqueta={c.etiqueta}
            requerido={c.requerido}
            ayuda={typeof c.ayuda === 'function' ? c.ayuda(item) : c.ayuda}
            error={errores[c.nombre]}
            className={c.ancho === 'completo' ? 'sm:col-span-2' : ''}
          >
            {c.tipo === 'selector' ? (
              <Selector {...comun}>
                <option value="">Seleccione…</option>
                {c.opciones.map(([valor, texto]) => (
                  <option key={valor} value={valor}>
                    {texto}
                  </option>
                ))}
              </Selector>
            ) : c.tipo === 'area' ? (
              <AreaTexto {...comun} />
            ) : (
              <Entrada
                {...comun}
                type={c.tipo === 'numero' ? 'number' : c.tipo === 'email' ? 'email' : 'text'}
                inputMode={c.tipo === 'numero' ? 'decimal' : undefined}
                step={c.paso}
                min={c.min}
                max={c.max}
                autoComplete="off"
              />
            )}
          </Campo>
        );
      })}

      <div className="sm:col-span-2">
        <Boton type="submit" icono={Save} cargando={enviando}>
          {textoBoton}
        </Boton>
      </div>
    </form>
  );
}
