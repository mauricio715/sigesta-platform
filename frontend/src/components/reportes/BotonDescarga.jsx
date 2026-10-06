import { useState } from 'react';
import { FileDown, FileSpreadsheet } from 'lucide-react';
import { descargarReporte } from '../../api/descargas';
import { mensajeError } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import Boton from '../ui/Boton';

/** Botón que descarga un reporte y avisa su código de verificación */
export default function BotonDescarga({ ruta, params = {}, formato = 'pdf', children, variante = 'secundario', className = '', alDescargar }) {
  const { notificar } = useToast();
  const [descargando, setDescargando] = useState(false);

  const descargar = async () => {
    setDescargando(true);
    try {
      const { nombre, codigo } = await descargarReporte(ruta, { ...params, formato });
      notificar(codigo ? `${nombre} descargado. Código de verificación: ${codigo}` : `${nombre} descargado.`);
      alDescargar?.(codigo);
    } catch (e) {
      notificar(mensajeError(e, 'No se pudo generar el reporte.'), 'error');
    } finally {
      setDescargando(false);
    }
  };

  return (
    <Boton variante={variante} icono={formato === 'xlsx' ? FileSpreadsheet : FileDown} cargando={descargando} onClick={descargar} className={className}>
      {children ?? (formato === 'xlsx' ? 'Exportar Excel' : 'Descargar PDF')}
    </Boton>
  );
}
