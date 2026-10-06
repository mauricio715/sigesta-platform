import { api } from './client';

/**
 * Descarga un reporte autenticado (el token va en la cabecera, por eso no
 * sirve un <a href>). Devuelve el código de verificación del documento.
 */
export async function descargarReporte(ruta, params = {}) {
  try {
    const respuesta = await api.get(ruta, { params, responseType: 'blob', timeout: 120_000 });
    const disposicion = respuesta.headers['content-disposition'] ?? '';
    const nombre = /filename="?([^";]+)"?/.exec(disposicion)?.[1] ?? `reporte.${params.formato ?? 'pdf'}`;

    const url = URL.createObjectURL(respuesta.data);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = nombre;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);

    return { nombre, codigo: respuesta.headers['x-codigo-verificacion'] ?? null };
  } catch (error) {
    // Con responseType 'blob' los errores también llegan como Blob: se convierten a JSON
    if (error.response?.data instanceof Blob) {
      try {
        error.response.data = JSON.parse(await error.response.data.text());
      } catch {
        // respuesta no JSON: se deja como está
      }
    }
    throw error;
  }
}

export const verificarReporte = (codigo) => api.get(`reportes/verificar/${encodeURIComponent(codigo)}`).then((r) => r.data);
