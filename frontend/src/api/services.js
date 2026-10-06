import { api, iaApiClient } from './client';

const datos = (promesa) => promesa.then((respuesta) => respuesta.data);

export const authApi = {
  login: (credenciales) => datos(api.post('auth/login', credenciales, { omitirManejoSesion: true })),
  logout: () => datos(api.post('auth/logout', null, { omitirManejoSesion: true })),
  me: () => datos(api.get('auth/me')),
};

export const inventarioApi = {
  resumen: (params) => datos(api.get('inventario/resumen', { params })),
};

export const alertasApi = {
  listar: (params) => datos(api.get('alertas', { params })),
  marcarLeida: (id) => datos(api.patch(`alertas/${id}/leer`)),
};

export const movimientosApi = {
  listar: (params) => datos(api.get('movimientos', { params })),
};

export const lotesApi = {
  buscar: (params) => datos(api.get('lotes', { params })),
};

export const catalogoApi = {
  insumos: (params) => datos(api.get('insumos', { params: { per_page: 100, ...params } })),
  productos: (params) => datos(api.get('productos', { params: { per_page: 100, ...params } })),
  proveedores: (params) => datos(api.get('proveedores', { params: { per_page: 100, ...params } })),
  clientes: (params) => datos(api.get('clientes', { params: { per_page: 100, ...params } })),
  recetasActivas: (productoId) => datos(api.get(`productos/${productoId}/recetas`)),
};

export const operacionesApi = {
  ingresoCompra: (payload) => datos(api.post('insumos/ingreso', payload)),
  produccion: (payload) => datos(api.post('produccion', payload)),
  despacho: (payload) => datos(api.post('despachos', payload)),
  merma: (payload) => datos(api.post('mermas', payload)),
};

export const trazabilidadApi = {
  insumo: (loteId) => datos(api.get(`trazabilidad/insumo/${loteId}`)),
  producto: (loteId) => datos(api.get(`trazabilidad/producto/${loteId}`)),
};

export const iaApi = {
  chat: (payload) => datos(iaApiClient.post('chat', payload)),
};

// ---------------------------------------------------------------------
//  Administración (solo rol admin)
// ---------------------------------------------------------------------

/** CRUD genérico: recurso = 'insumos' | 'productos' | 'proveedores' | 'clientes' | 'usuarios' */
export const adminApi = {
  listar: (recurso, params) => datos(api.get(recurso, { params })),
  crear: (recurso, payload) => datos(api.post(recurso, payload)),
  actualizar: (recurso, id, payload) => datos(api.patch(`${recurso}/${id}`, payload)),
  eliminar: (recurso, id) => datos(api.delete(`${recurso}/${id}`)),
};

export const recetasApi = {
  listar: (params) => datos(api.get('recetas', { params })),
  crearVersion: (payload) => datos(api.post('recetas', payload)),
  cambiarEstado: (id, activa) => datos(api.patch(`recetas/${id}/estado`, { activa })),
};

export const historialApi = {
  despachos: (params) => datos(api.get('despachos', { params })),
  ordenes: (params) => datos(api.get('ordenes-produccion', { params })),
};

export const anulacionesApi = {
  despacho: (id, motivo) => datos(api.post(`despachos/${id}/anular`, { motivo })),
  ingresoCompra: (loteId, motivo) => datos(api.post(`lotes/${loteId}/anular`, { motivo })),
};
