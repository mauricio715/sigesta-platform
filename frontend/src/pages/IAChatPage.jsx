import Encabezado from '../components/ui/Encabezado';
import IAChatPanel from '../components/ia/IAChatPanel';

export default function IAChatPage() {
  return (
    <>
      <Encabezado
        titulo="Asistente de auditoría"
        descripcion="Preguntas en lenguaje natural sobre inventario, vencimientos, Kardex y trazabilidad. Verifique siempre las cifras críticas en el módulo correspondiente."
      />
      <section className="overflow-hidden rounded-lg bg-concreto ring-1 ring-linea">
        <IAChatPanel alto="h-[calc(100dvh-17rem)] min-h-[28rem]" autoFoco />
      </section>
    </>
  );
}
