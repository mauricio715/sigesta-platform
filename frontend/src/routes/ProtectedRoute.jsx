import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { Cargando } from '../components/ui/Estados';
import { ROLES } from '../utils/format';

/**
 * - Sin sesión: redirige al login recordando a dónde quería ir.
 * - roles={['admin']}: además exige uno de esos roles.
 */
export default function ProtectedRoute({ roles, children }) {
  const { sesion, rol, verificando } = useAuth();
  const ubicacion = useLocation();

  if (!sesion) {
    return <Navigate to="/login" replace state={{ desde: ubicacion }} />;
  }

  if (verificando) {
    return (
      <div className="grid min-h-dvh place-items-center">
        <Cargando texto="Verificando sesión…" />
      </div>
    );
  }

  if (roles && !roles.includes(rol)) {
    return (
      <div className="mx-auto max-w-lg px-4 py-16 text-center">
        <ShieldAlert className="mx-auto size-10 text-vencido" aria-hidden />
        <h1 className="mt-3 font-cond text-2xl font-semibold">Acceso restringido</h1>
        <p className="mt-2 text-acero">
          Esta sección requiere el rol {roles.map((r) => ROLES[r] ?? r).join(' o ')}. Su rol actual es {ROLES[rol] ?? rol}.
        </p>
      </div>
    );
  }

  return children ?? <Outlet />;
}
