import { useState } from 'react';
import { CopyPlus, Plus, Power, PowerOff, Save, Trash2 } from 'lucide-react';
import { catalogoApi, recetasApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useCargar } from '../../hooks/useCargar';
import { useToast } from '../../context/ToastContext';
import Encabezado from '../../components/ui/Encabezado';
import Panel from '../../components/ui/Panel';
import Boton from '../../components/ui/Boton';
import Paginacion from '../../components/ui/Paginacion';
import PanelLateral from '../../components/ui/PanelLateral';
import Confirmar from '../../components/ui/Confirmar';
import { AreaTexto, Campo, Entrada, Selector } from '../../components/ui/Campo';
import { AvisoError, Cargando, Vacio } from '../../components/ui/Estados';
import { UNIDADES, formatoCantidad, formatoFechaHora } from '../../utils/format';

let claveLinea = 1;
const nuevaLinea = (insumo_id = '', cantidad_requerida = '') => ({ clave: claveLinea++, insumo_id: String(insumo_id), cantidad_requerida: String(cantidad_requerida) });

// ---------------------------------------------------------------------
//  Formulario de nueva versión (BOM)
// ---------------------------------------------------------------------

function FormularioVersion({ productos, insumos, base, alGuardar }) {
  const { notificar } = useToast();
  const [form, setForm] = useState(() => ({
    producto_id: String(base?.producto_id ?? ''),
    nombre_receta: base ? `${base.nombre_receta} (nueva versión)` : '',
    rendimiento_base: base ? String(Number(base.rendimiento_base)) : '',
    observaciones: '',
    activa: true,
  }));
  const [lineas, setLineas] = useState(() =>
    base?.formula?.length ? base.formula.map((l) => nuevaLinea(l.insumo_id, Number(l.cantidad_requerida))) : [nuevaLinea()],
  );
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);

  const producto = productos.find((p) => String(p.id) === form.producto_id);
  const insumoPorId = Object.fromEntries(insumos.map((i) => [String(i.id), i]));
  const cambiar = (campo) => (e) => setForm((f) => ({ ...f, [campo]: e.target.value }));
  const cambiarLinea = (clave, campo, valor) => setLineas((ls) => ls.map((l) => (l.clave === clave ? { ...l, [campo]: valor } : l)));

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setErrores({});

    try {
      const respuesta = await recetasApi.crearVersion({
        producto_id: form.producto_id ? Number(form.producto_id) : undefined,
        nombre_receta: form.nombre_receta.trim(),
        rendimiento_base: form.rendimiento_base,
        observaciones: form.observaciones.trim() || null,
        activa: form.activa,
        insumos: lineas
          .filter((l) => l.insumo_id || l.cantidad_requerida)
          .map((l) => ({ insumo_id: l.insumo_id ? Number(l.insumo_id) : undefined, cantidad_requerida: l.cantidad_requerida })),
      });
      notificar(respuesta.message);
      alGuardar();
    } catch (e) {
      setErrores(erroresDeCampo(e));
      notificar(mensajeError(e), 'error');
    } finally {
      setEnviando(false);
    }
  };

  return (
    <form onSubmit={enviar} noValidate className="space-y-4 p-5">
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo id="producto_id" etiqueta="Producto" requerido error={errores.producto_id}>
          <Selector id="producto_id" value={form.producto_id} onChange={cambiar('producto_id')} error={errores.producto_id}>
            <option value="">Seleccione…</option>
            {productos.map((p) => (
              <option key={p.id} value={p.id}>
                {p.nombre}
              </option>
            ))}
          </Selector>
        </Campo>
        <Campo
          id="rendimiento_base"
          etiqueta={`Rendimiento de la fórmula${producto ? ` (${UNIDADES[producto.unidad_medida]})` : ''}`}
          requerido
          ayuda="Cuánto producto sale con las cantidades de abajo"
          error={errores.rendimiento_base}
        >
          <Entrada id="rendimiento_base" type="number" inputMode="decimal" min="0" step="0.001" value={form.rendimiento_base} onChange={cambiar('rendimiento_base')} error={errores.rendimiento_base} />
        </Campo>
        <Campo id="nombre_receta" etiqueta="Nombre de la versión" requerido error={errores.nombre_receta} className="sm:col-span-2">
          <Entrada id="nombre_receta" value={form.nombre_receta} onChange={cambiar('nombre_receta')} error={errores.nombre_receta} placeholder="Ej. Pan de leche v2 (bajo en azúcar)" />
        </Campo>
      </div>

      <fieldset>
        <legend className="mb-2 font-medium">Fórmula</legend>
        {errores.insumos && <p className="mb-2 text-sm text-vencido">{errores.insumos}</p>}
        <div className="space-y-2">
          {lineas.map((linea, i) => {
            const insumo = insumoPorId[linea.insumo_id];
            const errorInsumo = errores[`insumos.${i}.insumo_id`];
            const errorCantidad = errores[`insumos.${i}.cantidad_requerida`];
            return (
              <div key={linea.clave} className="grid grid-cols-[1fr_8rem_auto] items-start gap-2">
                <div>
                  <label htmlFor={`insumo-${linea.clave}`} className="sr-only">
                    Insumo {i + 1}
                  </label>
                  <Selector
                    id={`insumo-${linea.clave}`}
                    value={linea.insumo_id}
                    onChange={(e) => cambiarLinea(linea.clave, 'insumo_id', e.target.value)}
                    error={errorInsumo}
                  >
                    <option value="">Insumo…</option>
                    {insumos.map((ins) => (
                      <option key={ins.id} value={ins.id}>
                        {ins.nombre}
                      </option>
                    ))}
                  </Selector>
                  {errorInsumo && <p className="mt-1 text-sm text-vencido">{errorInsumo}</p>}
                </div>
                <div>
                  <label htmlFor={`cantidad-${linea.clave}`} className="sr-only">
                    Cantidad del insumo {i + 1}
                  </label>
                  <div className="relative">
                    <Entrada
                      id={`cantidad-${linea.clave}`}
                      type="number"
                      inputMode="decimal"
                      min="0"
                      step="0.001"
                      placeholder="Cantidad"
                      value={linea.cantidad_requerida}
                      onChange={(e) => cambiarLinea(linea.clave, 'cantidad_requerida', e.target.value)}
                      error={errorCantidad}
                      className="pr-9"
                    />
                    {insumo && <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-acero">{UNIDADES[insumo.unidad_medida]}</span>}
                  </div>
                  {errorCantidad && <p className="mt-1 text-sm text-vencido">{errorCantidad}</p>}
                </div>
                <button
                  type="button"
                  onClick={() => setLineas((ls) => ls.filter((l) => l.clave !== linea.clave))}
                  disabled={lineas.length === 1}
                  className="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-acero hover:bg-vencido-fondo hover:text-vencido disabled:opacity-30"
                  aria-label={`Quitar insumo ${i + 1}`}
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            );
          })}
        </div>
        <Boton variante="sutil" icono={Plus} className="mt-2" onClick={() => setLineas((ls) => [...ls, nuevaLinea()])}>
          Agregar insumo
        </Boton>
      </fieldset>

      <Campo id="observaciones" etiqueta="Observaciones" error={errores.observaciones}>
        <AreaTexto id="observaciones" value={form.observaciones} onChange={cambiar('observaciones')} placeholder="Motivo del cambio de fórmula, temperatura de horneado…" />
      </Campo>

      <label className="flex items-start gap-3 rounded-md bg-concreto/60 p-3">
        <input type="checkbox" className="mt-1 size-4 accent-petroleo" checked={form.activa} onChange={(e) => setForm((f) => ({ ...f, activa: e.target.checked }))} />
        <span>
          <span className="font-medium">Usar esta versión en producción desde ahora</span>
          <span className="block text-sm text-acero">La versión activa anterior del producto se desactivará. Desmarque para guardarla como borrador.</span>
        </span>
      </label>

      <Boton type="submit" icono={Save} cargando={enviando}>
        Registrar versión
      </Boton>
    </form>
  );
}

// ---------------------------------------------------------------------
//  Página
// ---------------------------------------------------------------------

export default function RecetasAdminPage() {
  const { notificar } = useToast();
  const [productoId, setProductoId] = useState('');
  const [pagina, setPagina] = useState(1);
  const [formulario, setFormulario] = useState(null); // null | { base?: receta }
  const [cambioEstado, setCambioEstado] = useState(null);
  const [cambiando, setCambiando] = useState(false);

  const catalogo = useCargar(async () => {
    const [productos, insumos] = await Promise.all([catalogoApi.productos(), catalogoApi.insumos()]);
    return { productos: productos.data, insumos: insumos.data };
  }, []);

  const recetas = useCargar(() => recetasApi.listar({ producto_id: productoId || undefined, page: pagina, per_page: 10 }), [productoId, pagina]);

  const confirmarCambioEstado = async () => {
    setCambiando(true);
    try {
      const respuesta = await recetasApi.cambiarEstado(cambioEstado.id, !cambioEstado.activa);
      notificar(respuesta.message);
      recetas.recargar();
    } catch (e) {
      notificar(mensajeError(e), 'error');
    } finally {
      setCambiando(false);
      setCambioEstado(null);
    }
  };

  const lista = recetas.datos?.data ?? [];

  return (
    <>
      <Encabezado
        titulo="Recetas (BOM)"
        descripcion="Las fórmulas no se editan: cada cambio se registra como una versión nueva, así las órdenes ya producidas conservan la fórmula exacta con la que se fabricaron."
        acciones={
          <Boton icono={Plus} onClick={() => setFormulario({})} disabled={!catalogo.datos}>
            Nueva versión
          </Boton>
        }
      />

      <div className="mb-4 max-w-sm">
        <Campo id="filtro-producto" etiqueta="Producto">
          <Selector
            id="filtro-producto"
            value={productoId}
            onChange={(e) => {
              setProductoId(e.target.value);
              setPagina(1);
            }}
          >
            <option value="">Todos los productos</option>
            {(catalogo.datos?.productos ?? []).map((p) => (
              <option key={p.id} value={p.id}>
                {p.nombre}
              </option>
            ))}
          </Selector>
        </Campo>
      </div>

      {recetas.cargando && !recetas.datos ? (
        <Cargando />
      ) : recetas.error ? (
        <AvisoError mensaje={recetas.error} alReintentar={recetas.recargar} />
      ) : lista.length === 0 ? (
        <Panel>
          <Vacio titulo="No hay recetas registradas">Un producto sin receta activa no puede fabricarse.</Vacio>
        </Panel>
      ) : (
        <ul className={`space-y-4 ${recetas.cargando ? 'opacity-60' : ''}`}>
          {lista.map((r) => (
            <li key={r.id}>
              <Panel>
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-linea px-4 py-3">
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="font-cond text-xl font-semibold">{r.nombre_receta}</h2>
                      <span
                        className={`rounded px-2 py-0.5 text-sm font-semibold ${
                          r.activa ? 'bg-vigente-fondo text-vigente' : 'bg-agotado-fondo text-acero'
                        }`}
                      >
                        {r.activa ? 'Activa en producción' : 'Inactiva'}
                      </span>
                    </div>
                    <p className="text-sm text-acero">
                      {r.producto?.nombre} · rinde {formatoCantidad(r.rendimiento_base, r.producto?.unidad_medida)} · registrada {formatoFechaHora(r.created_at)}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Boton variante="secundario" icono={CopyPlus} onClick={() => setFormulario({ base: r })}>
                      Nueva versión desde esta
                    </Boton>
                    <Boton variante={r.activa ? 'secundario' : 'primario'} icono={r.activa ? PowerOff : Power} onClick={() => setCambioEstado(r)}>
                      {r.activa ? 'Desactivar' : 'Activar'}
                    </Boton>
                  </div>
                </div>
                <table className="w-full text-left">
                  <caption className="sr-only">Fórmula de {r.nombre_receta}</caption>
                  <tbody className="divide-y divide-linea">
                    {r.formula.map((l) => (
                      <tr key={l.insumo_id}>
                        <td className="px-4 py-2">
                          {l.nombre} <span className="cifra text-sm text-acero">{l.codigo}</span>
                        </td>
                        <td className="cifra px-4 py-2 text-right font-semibold">{formatoCantidad(l.cantidad_requerida, l.unidad_medida)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {r.observaciones && <p className="border-t border-linea px-4 py-2 text-sm text-acero">{r.observaciones}</p>}
              </Panel>
            </li>
          ))}
        </ul>
      )}
      <Paginacion meta={recetas.datos?.meta} alCambiar={setPagina} />

      <PanelLateral
        abierto={Boolean(formulario)}
        titulo={formulario?.base ? 'Nueva versión de receta' : 'Nueva receta'}
        descripcion={formulario?.base ? `A partir de "${formulario.base.nombre_receta}"` : 'Define qué insumos y en qué cantidad se usan.'}
        alCerrar={() => setFormulario(null)}
        ancho="sm:w-[40rem]"
      >
        {formulario && catalogo.datos && (
          <FormularioVersion
            key={formulario.base?.id ?? 'nueva'}
            productos={catalogo.datos.productos}
            insumos={catalogo.datos.insumos}
            base={formulario.base}
            alGuardar={() => {
              setFormulario(null);
              recetas.recargar();
            }}
          />
        )}
      </PanelLateral>

      <Confirmar
        abierto={Boolean(cambioEstado)}
        peligro={Boolean(cambioEstado?.activa)}
        titulo={cambioEstado?.activa ? 'Desactivar receta' : 'Activar receta'}
        mensaje={
          cambioEstado?.activa
            ? `"${cambioEstado?.nombre_receta}" dejará de usarse. Si no activa otra versión, ${cambioEstado?.producto?.nombre} no podrá producirse.`
            : `"${cambioEstado?.nombre_receta}" pasará a ser la fórmula de ${cambioEstado?.producto?.nombre}. La versión activa actual se desactivará.`
        }
        textoConfirmar={cambioEstado?.activa ? 'Desactivar' : 'Activar'}
        cargando={cambiando}
        alConfirmar={confirmarCambioEstado}
        alCancelar={() => setCambioEstado(null)}
      />
    </>
  );
}
