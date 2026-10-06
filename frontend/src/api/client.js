import axios from 'axios';
import { API_URL, IA_URL } from '../config';

// ---------------------------------------------------------------------
//  Sesión persistida (token Sanctum + expiración + usuario)
// ---------------------------------------------------------------------

const CLAVE_SESION = 'sigesta.sesion';

export function leerSesion() {
  try {
    const sesion = JSON.parse(localStorage.getItem(CLAVE_SESION));
    if (!sesion?.token) return null;

    if (sesion.expiresAt && new Date(sesion.expiresAt) <= new Date()) {
      localStorage.removeItem(CLAVE_SESION);
      return null;
    }

    return sesion;
  } catch {
    return null;
  }
}

export function guardarSesion(sesion) {
  localStorage.setItem(CLAVE_SESION, JSON.stringify(sesion));
}

export function borrarSesion() {
  localStorage.removeItem(CLAVE_SESION);
}

// ---------------------------------------------------------------------
//  Manejo global de sesión inválida (lo registra AuthProvider)
// ---------------------------------------------------------------------

let manejadorSesionInvalida = null;

export function alInvalidarSesion(manejador) {
  manejadorSesionInvalida = manejador;
}

function crearCliente(baseURL, timeout) {
  const cliente = axios.create({
    baseURL,
    timeout,
    headers: { Accept: 'application/json' },
  });

  // El token se lee en cada petición: siempre el vigente, y nunca uno expirado
  cliente.interceptors.request.use((config) => {
    const sesion = leerSesion();
    if (sesion?.token) {
      config.headers.Authorization = `Bearer ${sesion.token}`;
    }
    return config;
  });

  cliente.interceptors.response.use(
    (respuesta) => respuesta,
    (error) => {
      const status = error.response?.status;
      const mensaje = error.response?.data?.message ?? '';
      const omitir = error.config?.omitirManejoSesion; // p. ej. el propio login

      if (!omitir && manejadorSesionInvalida) {
        if (status === 401) manejadorSesionInvalida('expirada');
        if (status === 403 && /inactivo/i.test(mensaje)) manejadorSesionInvalida('inactivo');
      }

      return Promise.reject(error);
    },
  );

  return cliente;
}

/** API de SI-GESTA (Laravel) */
export const api = crearCliente(API_URL, 20_000);

/** Microservicio del asistente (FastAPI + Groq): respuestas más lentas */
export const iaApiClient = crearCliente(IA_URL, 90_000);

// ---------------------------------------------------------------------
//  Lectura de errores con el formato { status, message, errors }
// ---------------------------------------------------------------------

export function mensajeError(error, porDefecto = 'No se pudo completar la operación.') {
  if (error?.response?.data?.message) return error.response.data.message;
  if (error?.code === 'ECONNABORTED') return 'El servidor tardó demasiado en responder. Intente nuevamente.';
  if (error?.request && !error?.response) {
    return 'No hay conexión con el servidor. Verifique la red o que el servicio esté en ejecución.';
  }
  return porDefecto;
}

/** { campo: 'primer mensaje' } a partir de un 422 de validación */
export function erroresDeCampo(error) {
  const errores = error?.response?.data?.errors;
  if (!errores) return {};
  return Object.fromEntries(
    Object.entries(errores).map(([campo, mensajes]) => [campo, Array.isArray(mensajes) ? mensajes[0] : String(mensajes)]),
  );
}
