import { useState } from 'react';
import { Pencil, Plus, Save, Search, UserCheck, UserX } from 'lucide-react';
import { adminApi } from '../../api/services';
import { erroresDeCampo, mensajeError } from '../../api/client';
import { useCargar } from '../../hooks/useCargar';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import Encabezado from '../../components/ui/Encabezado';
import Panel from '../../components/ui/Panel';
import Boton from '../../components/ui/Boton';
import Paginacion from '../../components/ui/Paginacion';
import PanelLateral from '../../components/ui/PanelLateral';
import Confirmar from '../../components/ui/Confirmar';
import { Campo, Entrada, Selector, claseControl } from '../../components/ui/Campo';
import { AvisoError, Cargando, Vacio } from '../../components/ui/Estados';
import { ROLES, formatoFecha } from '../../utils/format';

// Usuario técnico del asistente de IA (php artisan sigesta:token-ia)
const ES_SERVICIO = (u) => u.email?.endsWith('@sigesta.internal');

function FormularioUsuario({ usuario, esUsted, alGuardar }) {
  const { notificar } = useToast();
  const [form, setForm] = useState({
    name: usuario?.name ?? '',
    email: usuario?.email ?? '',
    role: usuario?.role ?? 'operador',
    status: usuario?.status ?? 'active',
    password: '',
    password_confirmation: '',
  });
  const [errores, setErrores] = useState({});
  const [enviando, setEnviando] = useState(false);
  const cambiar = (campo) => (e) => setForm((f) => ({ ...f, [campo]: e.target.value }));

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setErrores({});

    const payload = { name: form.name.trim(), email: form.email.trim(), role: form.role };
    if (usuario) payload.status = form.status;
    if (form.password) {
      payload.password = form.password;
      payload.password_confirmation = form.password_confirmation;
    }

    try {
      const respuesta = usuario ? await adminApi.actualizar('usuarios', usuario.id, payload) : await adminApi.crear('usuarios', payload);
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
    <form onSubmit={enviar} noValidate className="grid gap-4 p-5 sm:grid-cols-2">
      <Campo id="name" etiqueta="Nombre completo" requerido error={errores.name} className="sm:col-span-2">
        <Entrada id="name" value={form.name} onChange={cambiar('name')} error={errores.name} autoComplete="off" />
      </Campo>
      <Campo id="email" etiqueta="Correo electrónico" requerido error={errores.email} ayuda="Es el usuario para iniciar sesión" className="sm:col-span-2">
        <Entrada id="email" type="email" value={form.email} onChange={cambiar('email')} error={errores.email} autoComplete="off" />
      </Campo>
      <Campo
        id="role"
        etiqueta="Rol"
        requerido
        error={errores.role}
        ayuda={esUsted ? 'No puede quitarse su propio rol de administrador.' : 'Cambiar el rol cierra sus sesiones abiertas.'}
      >
        <Selector id="role" value={form.role} onChange={cambiar('role')} error={errores.role} disabled={esUsted}>
          <option value="operador">Operador</option>
          <option value="admin">Administrador</option>
        </Selector>
      </Campo>
      {usuario && (
        <Campo id="status" etiqueta="Acceso" error={errores.status} ayuda={esUsted ? 'No puede desactivar su propia cuenta.' : undefined}>
          <Selector id="status" value={form.status} onChange={cambiar('status')} error={errores.status} disabled={esUsted}>
            <option value="active">Activo</option>
            <option value="inactive">Desactivado</option>
          </Selector>
        </Campo>
      )}
      <Campo
        id="password"
        etiqueta={usuario ? 'Nueva contraseña' : 'Contraseña'}
        requerido={!usuario}
        error={errores.password}
        ayuda={usuario ? 'Déjela vacía para mantener la actual.' : 'Mínimo 8 caracteres, con letras y números.'}
      >
        <Entrada id="password" type="password" value={form.password} onChange={cambiar('password')} error={errores.password} autoComplete="new-password" />
      </Campo>
      <Campo id="password_confirmation" etiqueta="Repetir contraseña" requerido={!usuario || Boolean(form.password)}>
        <Entrada id="password_confirmation" type="password" value={form.password_confirmation} onChange={cambiar('password_confirmation')} autoComplete="new-password" />
      </Campo>
      <div className="sm:col-span-2">
        <Boton type="submit" icono={Save} cargando={enviando}>
          {usuario ? 'Guardar cambios' : 'Crear usuario'}
        </Boton>
      </div>
    </form>
  );
}

export default function UsuariosAdminPage() {
  const { usuario: actual } = useAuth();
  const { notificar } = useToast();
  const [busqueda, setBusqueda] = useState('');
  const [filtros, setFiltros] = useState({ buscar: '', role: '', status: '', pagina: 1 });
  const [edicion, setEdicion] = useState(null);
  const [cambioAcceso, setCambioAcceso] = useState(null);
  const [cambiando, setCambiando] = useState(false);

  const usuarios = useCargar(
    () =>
      adminApi.listar('usuarios', {
        buscar: filtros.buscar || undefined,
        role: filtros.role || undefined,
        status: filtros.status || undefined,
        page: filtros.pagina,
        per_page: 20,
      }),
    [filtros],
  );

  const confirmarAcceso = async () => {
    setCambiando(true);
    try {
      const nuevo = cambioAcceso.status === 'active' ? 'inactive' : 'active';
      const respuesta = await adminApi.actualizar('usuarios', cambioAcceso.id, { status: nuevo });
      notificar(respuesta.message);
      usuarios.recargar();
    } catch (e) {
      notificar(mensajeError(e), 'error');
    } finally {
      setCambiando(false);
      setCambioAcceso(null);
    }
  };

  const filas = usuarios.datos?.data ?? [];

  return (
    <>
      <Encabezado
        titulo="Usuarios"
        descripcion="Los usuarios no se eliminan porque figuran en el Kardex y en las órdenes: se desactivan, y pierden el acceso de inmediato."
        acciones={
          <Boton icono={Plus} onClick={() => setEdicion({})}>
            Nuevo usuario
          </Boton>
        }
      />

      <Panel>
        <form
          className="flex flex-wrap items-end gap-3 border-b border-linea p-4"
          onSubmit={(e) => {
            e.preventDefault();
            setFiltros((f) => ({ ...f, buscar: busqueda.trim(), pagina: 1 }));
          }}
        >
          <div className="relative w-full sm:w-72">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-acero" aria-hidden />
            <input
              type="search"
              className={`${claseControl} pl-9`}
              placeholder="Buscar por nombre o correo"
              aria-label="Buscar usuario"
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
            />
          </div>
          <select
            aria-label="Filtrar por rol"
            className={claseControl.replace('w-full', 'w-full sm:w-56')}
            value={filtros.role}
            onChange={(e) => setFiltros((f) => ({ ...f, role: e.target.value, pagina: 1 }))}
          >
            <option value="">Todos los roles</option>
            <option value="admin">Administradores</option>
            <option value="operador">Operadores</option>
          </select>
          <select
            aria-label="Filtrar por acceso"
            className={claseControl.replace('w-full', 'w-full sm:w-56')}
            value={filtros.status}
            onChange={(e) => setFiltros((f) => ({ ...f, status: e.target.value, pagina: 1 }))}
          >
            <option value="">Activos y desactivados</option>
            <option value="active">Solo activos</option>
            <option value="inactive">Solo desactivados</option>
          </select>
          <Boton type="submit" variante="secundario">
            Buscar
          </Boton>
        </form>

        {usuarios.cargando && !usuarios.datos ? (
          <Cargando />
        ) : usuarios.error ? (
          <div className="p-4">
            <AvisoError mensaje={usuarios.error} alReintentar={usuarios.recargar} />
          </div>
        ) : filas.length === 0 ? (
          <Vacio titulo="No hay usuarios con esos filtros" />
        ) : (
          <div className={`overflow-x-auto ${usuarios.cargando ? 'opacity-60' : ''}`}>
            <table className="w-full min-w-[44rem] text-left">
              <thead className="bg-concreto/60 text-sm text-acero">
                <tr>
                  <th scope="col" className="px-4 py-3 font-medium">Usuario</th>
                  <th scope="col" className="px-4 py-3 font-medium">Rol</th>
                  <th scope="col" className="px-4 py-3 font-medium">Acceso</th>
                  <th scope="col" className="px-4 py-3 font-medium">Alta</th>
                  <th scope="col" className="px-4 py-3">
                    <span className="sr-only">Acciones</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-linea">
                {filas.map((u) => {
                  const esUsted = u.id === actual?.id;
                  const servicio = ES_SERVICIO(u);
                  const activo = u.status === 'active';
                  return (
                    <tr key={u.id} className={activo ? '' : 'text-acero'}>
                      <td className="px-4 py-3">
                        <p className="font-medium">
                          {u.name}
                          {esUsted && <span className="ml-2 text-sm font-normal text-acero">(usted)</span>}
                        </p>
                        <p className="text-sm text-acero">{u.email}</p>
                        {servicio && <p className="text-sm text-petroleo">Cuenta técnica del asistente de IA (solo lectura)</p>}
                      </td>
                      <td className="px-4 py-3">{ROLES[u.role] ?? u.role}</td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex items-center gap-1.5 text-sm font-medium ${activo ? 'text-vigente' : 'text-vencido'}`}>
                          <span className={`size-2 rounded-full ${activo ? 'bg-vigente' : 'bg-vencido'}`} aria-hidden />
                          {activo ? 'Activo' : 'Desactivado'}
                        </span>
                      </td>
                      <td className="cifra px-4 py-3">{formatoFecha(u.created_at)}</td>
                      <td className="whitespace-nowrap px-4 py-2 text-right">
                        {!servicio && (
                          <button
                            type="button"
                            onClick={() => setEdicion({ usuario: u })}
                            className="inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-petroleo hover:bg-petroleo-claro"
                          >
                            <Pencil className="size-4" aria-hidden /> Editar
                          </button>
                        )}
                        {!esUsted && (
                          <button
                            type="button"
                            onClick={() => setCambioAcceso(u)}
                            className={`inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 text-sm font-medium ${
                              activo ? 'text-vencido hover:bg-vencido-fondo' : 'text-vigente hover:bg-vigente-fondo'
                            }`}
                          >
                            {activo ? <UserX className="size-4" aria-hidden /> : <UserCheck className="size-4" aria-hidden />}
                            {activo ? 'Desactivar' : 'Reactivar'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <Paginacion meta={usuarios.datos?.meta} alCambiar={(pagina) => setFiltros((f) => ({ ...f, pagina }))} />
      </Panel>

      <PanelLateral
        abierto={Boolean(edicion)}
        titulo={edicion?.usuario ? 'Editar usuario' : 'Nuevo usuario'}
        descripcion={edicion?.usuario?.email ?? 'Comparta la contraseña inicial en persona, no por mensaje.'}
        alCerrar={() => setEdicion(null)}
      >
        {edicion && (
          <FormularioUsuario
            key={edicion.usuario?.id ?? 'nuevo'}
            usuario={edicion.usuario}
            esUsted={edicion.usuario?.id === actual?.id}
            alGuardar={() => {
              setEdicion(null);
              usuarios.recargar();
            }}
          />
        )}
      </PanelLateral>

      <Confirmar
        abierto={Boolean(cambioAcceso)}
        peligro={cambioAcceso?.status === 'active'}
        titulo={cambioAcceso?.status === 'active' ? 'Desactivar usuario' : 'Reactivar usuario'}
        mensaje={
          cambioAcceso?.status === 'active'
            ? ES_SERVICIO(cambioAcceso)
              ? 'El asistente de IA dejará de funcionar hasta que reactive esta cuenta y emita un token nuevo con php artisan sigesta:token-ia.'
              : `${cambioAcceso?.name} perderá el acceso de inmediato y se cerrarán todas sus sesiones. Su historial en el Kardex se conserva.`
            : `${cambioAcceso?.name} podrá volver a iniciar sesión.`
        }
        textoConfirmar={cambioAcceso?.status === 'active' ? 'Desactivar' : 'Reactivar'}
        cargando={cambiando}
        alConfirmar={confirmarAcceso}
        alCancelar={() => setCambioAcceso(null)}
      />
    </>
  );
}
