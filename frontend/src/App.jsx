import { lazy, Suspense } from 'react';
import { Link, Navigate, Outlet, Route, Routes } from 'react-router-dom';
import ProtectedRoute from './routes/ProtectedRoute';
import AppLayout from './layouts/AppLayout';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import InventarioPage from './pages/InventarioPage';
import KardexPage from './pages/KardexPage';
import OperacionesPage from './pages/OperacionesPage';
import TrazabilidadPage from './pages/TrazabilidadPage';
import IAChatPage from './pages/IAChatPage';
import DespachosPage from './pages/DespachosPage';
import ReportesPage from './pages/ReportesPage';
import OrdenesProduccionPage from './pages/OrdenesProduccionPage';
import { Cargando } from './components/ui/Estados';

// Las pantallas de administración se cargan aparte: un operador nunca descarga su código
const cargarCatalogos = () => import('./pages/admin/CatalogoPages');
const InsumosAdminPage = lazy(() => cargarCatalogos().then((m) => ({ default: m.InsumosAdminPage })));
const ProductosAdminPage = lazy(() => cargarCatalogos().then((m) => ({ default: m.ProductosAdminPage })));
const ProveedoresAdminPage = lazy(() => cargarCatalogos().then((m) => ({ default: m.ProveedoresAdminPage })));
const ClientesAdminPage = lazy(() => cargarCatalogos().then((m) => ({ default: m.ClientesAdminPage })));
const RecetasAdminPage = lazy(() => import('./pages/admin/RecetasAdminPage'));
const UsuariosAdminPage = lazy(() => import('./pages/admin/UsuariosAdminPage'));

function SeccionAdmin() {
  return (
    <ProtectedRoute roles={['admin']}>
      <Suspense fallback={<Cargando texto="Abriendo administración…" />}>
        <Outlet />
      </Suspense>
    </ProtectedRoute>
  );
}

function NoEncontrada() {
  return (
    <div className="mx-auto max-w-md px-4 py-20 text-center">
      <h1 className="font-cond text-3xl font-semibold">Página no encontrada</h1>
      <p className="mt-2 text-acero">La dirección no corresponde a ningún módulo de SI-GESTA.</p>
      <Link to="/" className="mt-6 inline-flex min-h-11 items-center rounded-md bg-petroleo px-4 font-medium text-white">
        Ir al panel de control
      </Link>
    </div>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route
        element={
          <ProtectedRoute>
            <AppLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<DashboardPage />} />
        <Route path="inventario" element={<InventarioPage />} />
        <Route path="kardex" element={<KardexPage />} />
        <Route path="operaciones" element={<OperacionesPage />} />
        <Route path="ordenes-produccion" element={<OrdenesProduccionPage />} />
        <Route path="despachos" element={<DespachosPage />} />
        <Route path="trazabilidad" element={<TrazabilidadPage />} />
        <Route path="reportes" element={<ReportesPage />} />
        <Route path="asistente" element={<IAChatPage />} />

        {/* Administración: solo rol admin (el backend también lo exige) */}
        <Route path="admin" element={<SeccionAdmin />}>
          <Route index element={<Navigate to="insumos" replace />} />
          <Route path="insumos" element={<InsumosAdminPage />} />
          <Route path="productos" element={<ProductosAdminPage />} />
          <Route path="recetas" element={<RecetasAdminPage />} />
          <Route path="proveedores" element={<ProveedoresAdminPage />} />
          <Route path="clientes" element={<ClientesAdminPage />} />
          <Route path="usuarios" element={<UsuariosAdminPage />} />
        </Route>

        <Route path="*" element={<NoEncontrada />} />
      </Route>
    </Routes>
  );
}
