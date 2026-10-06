import { useState } from 'react';
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { CircleAlert, Info, LogIn } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { erroresDeCampo, mensajeError } from '../api/client';
import Boton from '../components/ui/Boton';
import { Campo, Entrada } from '../components/ui/Campo';

const AVISOS = {
  expirada: 'Su sesión terminó (duran 8 horas o fue cerrada desde otro lugar). Inicie sesión nuevamente.',
  inactivo: 'Su usuario fue desactivado. Contacte al administrador de SI-GESTA.',
};

export default function LoginPage() {
  const { sesion, login } = useAuth();
  const navigate = useNavigate();
  const ubicacion = useLocation();
  const [params] = useSearchParams();

  const [form, setForm] = useState({ email: '', password: '' });
  const [errores, setErrores] = useState({});
  const [error, setError] = useState('');
  const [enviando, setEnviando] = useState(false);

  if (sesion) return <Navigate to="/" replace />;

  const aviso = AVISOS[params.get('motivo')];

  const enviar = async (evento) => {
    evento.preventDefault();
    setEnviando(true);
    setError('');
    setErrores({});

    try {
      await login(form);
      const destino = ubicacion.state?.desde?.pathname ?? '/';
      navigate(destino, { replace: true });
    } catch (e) {
      setErrores(erroresDeCampo(e));
      setError(e.response?.status === 422 ? 'Revise los datos ingresados.' : mensajeError(e, 'No se pudo iniciar sesión.'));
    } finally {
      setEnviando(false);
    }
  };

  const cambiar = (campo) => (e) => setForm((f) => ({ ...f, [campo]: e.target.value }));

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
      {/* Panel de marca: una "etiqueta de lote" ampliada */}
      <div className="relative hidden flex-col justify-between overflow-hidden bg-petroleo-oscuro p-12 text-white lg:flex">
        <span className="font-cond text-2xl font-bold tracking-wide">SI-GESTA</span>

        <div>
          <div className="mb-8 inline-flex items-stretch overflow-hidden rounded bg-white text-tinta shadow-xl">
            <span className="w-2.5 bg-vigente" aria-hidden />
            <div className="px-5 py-4">
              <p className="cifra text-3xl font-semibold tracking-wide">LEC-20261002-00042</p>
              <p className="mt-1 text-acero">Leche entera · vence el 12/10/2026</p>
            </div>
          </div>
          <h1 className="max-w-md font-cond text-5xl font-semibold leading-[1.05]">
            Cada lote, desde el proveedor hasta el cliente.
          </h1>
          <p className="mt-4 max-w-md text-lg text-white/75">
            Control de inventario con rotación FEFO, trazabilidad bidireccional y alertas de inocuidad para plantas de alimentos.
          </p>
        </div>

        <p className="text-sm text-white/55">Cochabamba, Bolivia · HACCP · ISO 22000 · SENASAG</p>
      </div>

      {/* Formulario */}
      <div className="flex items-center justify-center px-4 py-12 sm:px-8">
        <form onSubmit={enviar} className="w-full max-w-sm" noValidate>
          <p className="font-cond text-2xl font-bold tracking-wide text-petroleo lg:hidden">SI-GESTA</p>
          <h2 className="mt-2 font-cond text-3xl font-semibold">Iniciar sesión</h2>
          <p className="mt-1 text-acero">Use la cuenta que le asignó el administrador.</p>

          {aviso && (
            <div className="mt-6 flex gap-3 rounded-md bg-petroleo-claro px-4 py-3 text-petroleo-oscuro">
              <Info className="mt-0.5 size-5 shrink-0" aria-hidden />
              <p className="text-sm">{aviso}</p>
            </div>
          )}

          {error && (
            <div role="alert" className="mt-6 flex gap-3 rounded-md bg-vencido-fondo px-4 py-3 text-vencido">
              <CircleAlert className="mt-0.5 size-5 shrink-0" aria-hidden />
              <p className="text-sm">{error}</p>
            </div>
          )}

          <div className="mt-6 space-y-4">
            <Campo id="email" etiqueta="Correo electrónico" error={errores.email}>
              <Entrada
                id="email"
                type="email"
                autoComplete="username"
                inputMode="email"
                value={form.email}
                onChange={cambiar('email')}
                error={errores.email}
                required
                autoFocus
              />
            </Campo>
            <Campo id="password" etiqueta="Contraseña" error={errores.password}>
              <Entrada
                id="password"
                type="password"
                autoComplete="current-password"
                value={form.password}
                onChange={cambiar('password')}
                error={errores.password}
                required
              />
            </Campo>
          </div>

          <Boton type="submit" icono={LogIn} cargando={enviando} className="mt-6 w-full">
            Iniciar sesión
          </Boton>
        </form>
      </div>
    </div>
  );
}
