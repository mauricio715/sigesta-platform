import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { alInvalidarSesion, borrarSesion, guardarSesion, leerSesion } from '../api/client';
import { authApi } from '../api/services';

const AuthContext = createContext(null);

// setTimeout no admite más de ~24,8 días
const MAX_TIMEOUT = 2_147_483_647;

export function AuthProvider({ children }) {
  const navigate = useNavigate();
  const [sesion, setSesion] = useState(() => leerSesion());
  const [verificando, setVerificando] = useState(() => Boolean(leerSesion()));

  const cerrarLocalmente = useCallback(
    (motivo) => {
      borrarSesion();
      setSesion(null);
      setVerificando(false);
      navigate(motivo ? `/login?motivo=${motivo}` : '/login', { replace: true });
    },
    [navigate],
  );

  // Se registra durante el render (no en un efecto) para que las peticiones
  // que lanzan los componentes hijos al montarse ya tengan el manejador.
  alInvalidarSesion(cerrarLocalmente);

  // Al recargar la página: confirmar que el token sigue valiendo y refrescar el usuario
  useEffect(() => {
    if (!leerSesion()) {
      setVerificando(false);
      return undefined;
    }

    let vigente = true;
    authApi
      .me()
      .then((respuesta) => {
        if (!vigente) return;
        setSesion((previa) => {
          if (!previa) return previa;
          const actualizada = { ...previa, user: respuesta.data };
          guardarSesion(actualizada);
          return actualizada;
        });
      })
      .catch(() => {
        // 401/403 los resuelve el interceptor; un error de red no cierra la sesión
      })
      .finally(() => {
        if (vigente) setVerificando(false);
      });

    return () => {
      vigente = false;
    };
  }, []);

  // Cierre automático exactamente al expirar el token
  useEffect(() => {
    if (!sesion?.expiresAt) return undefined;
    const restante = new Date(sesion.expiresAt).getTime() - Date.now();
    const temporizador = setTimeout(() => cerrarLocalmente('expirada'), Math.min(Math.max(restante, 0), MAX_TIMEOUT));
    return () => clearTimeout(temporizador);
  }, [sesion?.expiresAt, cerrarLocalmente]);

  const login = useCallback(async ({ email, password }) => {
    const respuesta = await authApi.login({ email, password, device_name: 'sigesta-web' });
    const nueva = {
      token: respuesta.data.token,
      expiresAt: respuesta.data.expires_at,
      user: respuesta.data.user,
    };
    guardarSesion(nueva);
    setSesion(nueva);
    return nueva.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // Aunque falle la revocación remota, la sesión local se cierra igual
    }
    cerrarLocalmente();
  }, [cerrarLocalmente]);

  const valor = useMemo(
    () => ({
      sesion,
      usuario: sesion?.user ?? null,
      rol: sesion?.user?.role ?? null,
      esAdmin: sesion?.user?.role === 'admin',
      expiraEn: sesion?.expiresAt ?? null,
      verificando,
      login,
      logout,
    }),
    [sesion, verificando, login, logout],
  );

  return <AuthContext.Provider value={valor}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const contexto = useContext(AuthContext);
  if (!contexto) throw new Error('useAuth debe usarse dentro de <AuthProvider>.');
  return contexto;
}
