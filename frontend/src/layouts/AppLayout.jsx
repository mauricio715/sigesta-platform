import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { BookOpenText, Bot, ClipboardCheck, FileText, Boxes, Building2, ClipboardList, Factory, GitFork, LayoutDashboard, LogOut, Menu, Package, Store, Truck, Users, Wheat, X } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { IAChatProvider } from '../context/IAChatContext';
import IAChatWidget from '../components/ia/IAChatWidget';
import { ROLES } from '../utils/format';

/** roles: undefined = todos los roles autenticados */
export const MODULOS = [
  { ruta: '/', texto: 'Panel de control', icono: LayoutDashboard, fin: true, grupo: 'operacion' },
  { ruta: '/inventario', texto: 'Inventario', icono: Boxes, grupo: 'operacion' },
  { ruta: '/kardex', texto: 'Kardex', icono: ClipboardList, grupo: 'operacion' },
  { ruta: '/operaciones', texto: 'Operaciones de planta', icono: Factory, grupo: 'operacion' },
  { ruta: '/ordenes-produccion', texto: 'Producción', icono: ClipboardCheck, grupo: 'operacion' },
  { ruta: '/despachos', texto: 'Despachos', icono: Truck, grupo: 'operacion' },
  { ruta: '/trazabilidad', texto: 'Trazabilidad', icono: GitFork, grupo: 'operacion' },
  { ruta: '/reportes', texto: 'Reportes', icono: FileText, grupo: 'operacion' },
  { ruta: '/asistente', texto: 'Asistente de auditoría', icono: Bot, grupo: 'operacion' },

  { ruta: '/admin/insumos', texto: 'Insumos', icono: Wheat, grupo: 'admin', roles: ['admin'] },
  { ruta: '/admin/productos', texto: 'Productos', icono: Package, grupo: 'admin', roles: ['admin'] },
  { ruta: '/admin/recetas', texto: 'Recetas', icono: BookOpenText, grupo: 'admin', roles: ['admin'] },
  { ruta: '/admin/proveedores', texto: 'Proveedores', icono: Building2, grupo: 'admin', roles: ['admin'] },
  { ruta: '/admin/clientes', texto: 'Clientes', icono: Store, grupo: 'admin', roles: ['admin'] },
  { ruta: '/admin/usuarios', texto: 'Usuarios', icono: Users, grupo: 'admin', roles: ['admin'] },
];

const GRUPOS = [
  { clave: 'operacion', titulo: null },
  { clave: 'admin', titulo: 'Administración' },
];

function horaDe(iso) {
  return iso ? new Date(iso).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' }) : null;
}

export default function AppLayout() {
  const { usuario, rol, expiraEn, logout } = useAuth();
  const [menuAbierto, setMenuAbierto] = useState(false);
  const [saliendo, setSaliendo] = useState(false);
  const ubicacion = useLocation();

  useEffect(() => setMenuAbierto(false), [ubicacion.pathname]);

  const modulos = MODULOS.filter((m) => !m.roles || m.roles.includes(rol));
  const actual = modulos.find((m) => (m.fin ? ubicacion.pathname === m.ruta : ubicacion.pathname.startsWith(m.ruta)));

  const salir = async () => {
    setSaliendo(true);
    await logout();
  };

  return (
    <IAChatProvider>
      <a href="#contenido" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">
        Saltar al contenido
      </a>

      <div className="min-h-dvh lg:grid lg:grid-cols-[16rem_1fr]">
        {/* ---------- Barra lateral ---------- */}
        {menuAbierto && <div className="fixed inset-0 z-30 bg-tinta/40 lg:hidden" onClick={() => setMenuAbierto(false)} aria-hidden />}

        <aside
          className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-petroleo-oscuro text-white transition-transform lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0 ${
            menuAbierto ? 'translate-x-0' : '-translate-x-full'
          }`}
          aria-label="Módulos"
        >
          <div className="flex h-16 items-center justify-between px-5">
            <div className="flex items-center gap-3">
              <span className="flex h-8 items-stretch overflow-hidden rounded-sm bg-white" aria-hidden>
                <span className="w-1.5 bg-vigente" />
                <span className="w-1.5 bg-proximo" />
                <span className="w-1.5 bg-vencido" />
              </span>
              <span className="font-cond text-2xl font-bold tracking-wide">SI-GESTA</span>
            </div>
            <button type="button" className="rounded p-2 hover:bg-white/10 lg:hidden" onClick={() => setMenuAbierto(false)} aria-label="Cerrar menú">
              <X className="size-5" />
            </button>
          </div>

          <nav className="min-h-0 flex-1 overflow-y-auto px-3 py-4">
            {GRUPOS.map(({ clave, titulo }) => {
              const enGrupo = modulos.filter((m) => m.grupo === clave);
              if (enGrupo.length === 0) return null;
              return (
                <div key={clave} className={titulo ? 'mt-6' : ''}>
                  {titulo && <p className="mb-2 px-3 text-sm font-medium text-white/55">{titulo}</p>}
                  <ul className="space-y-1">
                    {enGrupo.map(({ ruta, texto, icono: Icono, fin }) => (
                      <li key={ruta}>
                        <NavLink
                          to={ruta}
                          end={fin}
                          className={({ isActive }) =>
                            `flex min-h-11 items-center gap-3 rounded-md px-3 text-[0.95rem] ${
                              isActive ? 'bg-white font-semibold text-petroleo-oscuro' : 'text-white/85 hover:bg-white/10 hover:text-white'
                            }`
                          }
                        >
                          <Icono className="size-5 shrink-0" aria-hidden />
                          {texto}
                        </NavLink>
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </nav>

          <p className="px-5 pb-5 text-sm leading-snug text-white/60">
            Trazabilidad e inocuidad alimentaria
            <br />
            HACCP · ISO 22000 · SENASAG
          </p>
        </aside>

        {/* ---------- Contenido ---------- */}
        <div className="flex min-w-0 flex-col">
          <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-linea bg-white/95 px-4 backdrop-blur sm:px-6">
            <button type="button" className="rounded p-2 hover:bg-concreto lg:hidden" onClick={() => setMenuAbierto(true)} aria-label="Abrir menú">
              <Menu className="size-5" />
            </button>
            <p className="truncate font-cond text-lg font-semibold">{actual?.texto}</p>

            <div className="ml-auto flex items-center gap-3 sm:gap-4">
              <div className="hidden text-right leading-tight sm:block">
                <p className="font-medium">{usuario?.name}</p>
                <p className="text-sm text-acero">
                  {ROLES[rol] ?? rol}
                  {expiraEn && <> · sesión hasta las {horaDe(expiraEn)}</>}
                </p>
              </div>
              <button
                type="button"
                onClick={salir}
                disabled={saliendo}
                className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 ring-1 ring-linea hover:bg-concreto disabled:opacity-50"
              >
                <LogOut className="size-4" aria-hidden />
                <span className="hidden sm:inline">Cerrar sesión</span>
                <span className="sr-only sm:hidden">Cerrar sesión</span>
              </button>
            </div>
          </header>

          <main id="contenido" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:py-8">
            <Outlet />
          </main>
        </div>
      </div>

      {ubicacion.pathname !== '/asistente' && <IAChatWidget />}
    </IAChatProvider>
  );
}
