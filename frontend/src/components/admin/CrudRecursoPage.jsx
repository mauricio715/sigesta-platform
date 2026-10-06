import { useState } from 'react';
import { Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { adminApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useCargar } from '../../hooks/useCargar';
import { useToast } from '../../context/ToastContext';
import Encabezado from '../ui/Encabezado';
import Panel from '../ui/Panel';
import Boton from '../ui/Boton';
import Paginacion from '../ui/Paginacion';
import PanelLateral from '../ui/PanelLateral';
import Confirmar from '../ui/Confirmar';
import { claseControl } from '../ui/Campo';
import { AvisoError, Cargando, Vacio } from '../ui/Estados';
import FormularioRecurso from './FormularioRecurso';

/**
 * Pantalla de catálogo reutilizable (insumos, productos, proveedores, clientes).
 * Toda la diferencia entre recursos está en la configuración `config`.
 */
export default function CrudRecursoPage({ config }) {
  const { notificar } = useToast();
  const [busqueda, setBusqueda] = useState('');
  const [consulta, setConsulta] = useState({ buscar: '', pagina: 1 });
  const [edicion, setEdicion] = useState(null); // null | { item?: {...} }
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);
  const [aEliminar, setAEliminar] = useState(null);
  const [eliminando, setEliminando] = useState(false);

  const lista = useCargar(
    () => adminApi.listar(config.recurso, { buscar: consulta.buscar || undefined, page: consulta.pagina, per_page: 20 }),
    [config.recurso, consulta],
  );

  const abrir = (item = null) => {
    setErrores({});
    setEdicion({ item });
  };

  const guardar = async (payload) => {
    setEnviando(true);
    setErrores({});
    const item = edicion.item;

    try {
      const respuesta = item
        ? await adminApi.actualizar(config.recurso, item.id, payload)
        : await adminApi.crear(config.recurso, payload);
      notificar(respuesta.message);
      setEdicion(null);
      lista.recargar();
    } catch (e) {
      setErrores(erroresDeCampo(e));
      notificar(mensajeError(e), 'error');
    } finally {
      setEnviando(false);
    }
  };

  const eliminar = async () => {
    setEliminando(true);
    try {
      const respuesta = await adminApi.eliminar(config.recurso, aEliminar.id);
      notificar(respuesta.message);
      lista.recargar();
    } catch (e) {
      // 409: tiene lotes, recetas o historial asociado
      notificar(mensajeError(e), 'error');
    } finally {
      setEliminando(false);
      setAEliminar(null);
    }
  };

  const filas = lista.datos?.data ?? [];

  return (
    <>
      <Encabezado
        titulo={config.titulo}
        descripcion={config.descripcion}
        acciones={
          <Boton icono={Plus} onClick={() => abrir()}>
            {config.textoNuevo}
          </Boton>
        }
      />

      <Panel>
        <form
          className="flex flex-wrap gap-2 border-b border-linea p-4"
          onSubmit={(e) => {
            e.preventDefault();
            setConsulta({ buscar: busqueda.trim(), pagina: 1 });
          }}
        >
          <div className="relative w-full sm:w-80">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-acero" aria-hidden />
            <input
              type="search"
              className={`${claseControl} pl-9`}
              placeholder={config.placeholderBusqueda}
              aria-label={config.placeholderBusqueda}
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
            />
          </div>
          <Boton type="submit" variante="secundario">
            Buscar
          </Boton>
        </form>

        {lista.cargando && !lista.datos ? (
          <Cargando />
        ) : lista.error ? (
          <div className="p-4">
            <AvisoError mensaje={lista.error} alReintentar={lista.recargar} />
          </div>
        ) : filas.length === 0 ? (
          <Vacio titulo={consulta.buscar ? 'Sin resultados para esa búsqueda' : config.textoVacio}>
            {!consulta.buscar && (
              <Boton icono={Plus} className="mt-3" onClick={() => abrir()}>
                {config.textoNuevo}
              </Boton>
            )}
          </Vacio>
        ) : (
          <div className={`overflow-x-auto ${lista.cargando ? 'opacity-60' : ''}`}>
            <table className="w-full min-w-[48rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  {config.columnas.map((col) => (
                    <th key={col.titulo} scope="col" className={`px-4 py-3 font-medium ${col.derecha ? 'text-right' : ''}`}>
                      {col.titulo}
                    </th>
                  ))}
                  <th scope="col" className="px-4 py-3 text-right font-medium">
                    <span className="sr-only">Acciones</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {filas.map((item) => (
                  <tr key={item.id} className="align-top">
                    {config.columnas.map((col) => (
                      <td key={col.titulo} className={`px-4 py-3 ${col.derecha ? 'text-right' : ''}`}>
                        {col.celda(item)}
                      </td>
                    ))}
                    <td className="whitespace-nowrap px-4 py-2 text-right">
                      <button
                        type="button"
                        onClick={() => abrir(item)}
                        className="inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-petroleo hover:bg-petroleo-claro"
                      >
                        <Pencil className="size-4" aria-hidden /> Editar
                      </button>
                      {config.permiteEliminar && (
                        <button
                          type="button"
                          onClick={() => setAEliminar(item)}
                          className="inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-vencido hover:bg-vencido-fondo"
                          aria-label={`Eliminar ${config.nombreItem(item)}`}
                        >
                          <Trash2 className="size-4" aria-hidden />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Paginacion meta={lista.datos?.meta} alCambiar={(pagina) => setConsulta((c) => ({ ...c, pagina }))} />
      </Panel>

      <PanelLateral
        abierto={Boolean(edicion)}
        titulo={edicion?.item ? `Editar ${config.singular}` : config.textoNuevo}
        descripcion={edicion?.item ? config.nombreItem(edicion.item) : config.ayudaAlta}
        alCerrar={() => setEdicion(null)}
      >
        {edicion && (
          <FormularioRecurso
            key={edicion.item?.id ?? 'nuevo'}
            campos={config.campos}
            item={edicion.item}
            errores={errores}
            enviando={enviando}
            alEnviar={guardar}
            textoBoton={edicion.item ? 'Guardar cambios' : config.textoNuevo}
          />
        )}
      </PanelLateral>

      <Confirmar
        abierto={Boolean(aEliminar)}
        titulo={`Eliminar ${config.singular}`}
        mensaje={`Se eliminará "${aEliminar ? config.nombreItem(aEliminar) : ''}". Solo es posible si no tiene historial (lotes o recetas); si lo tiene, el sistema lo impedirá para no perder trazabilidad.`}
        textoConfirmar="Eliminar"
        cargando={eliminando}
        alConfirmar={eliminar}
        alCancelar={() => setAEliminar(null)}
      />
    </>
  );
}
