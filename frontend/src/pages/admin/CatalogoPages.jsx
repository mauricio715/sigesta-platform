import CrudRecursoPage from '../../components/admin/CrudRecursoPage';
import { CLIENTES, INSUMOS, PRODUCTOS, PROVEEDORES } from './catalogos';

// `key` fuerza un estado limpio al pasar de un catálogo a otro
export const InsumosAdminPage = () => <CrudRecursoPage key="insumos" config={INSUMOS} />;
export const ProductosAdminPage = () => <CrudRecursoPage key="productos" config={PRODUCTOS} />;
export const ProveedoresAdminPage = () => <CrudRecursoPage key="proveedores" config={PROVEEDORES} />;
export const ClientesAdminPage = () => <CrudRecursoPage key="clientes" config={CLIENTES} />;
