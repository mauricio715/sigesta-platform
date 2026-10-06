import { useSearchParams } from 'react-router-dom';
import { Factory, PackagePlus, Trash2, Truck } from 'lucide-react';
import Encabezado from '../components/ui/Encabezado';
import Panel from '../components/ui/Panel';
import IngresoCompraForm from '../components/operaciones/IngresoCompraForm';
import ProduccionForm from '../components/operaciones/ProduccionForm';
import DespachoForm from '../components/operaciones/DespachoForm';
import MermaForm from '../components/operaciones/MermaForm';

// Orden del flujo físico de la planta: recepción -> producción -> despacho; las bajas al final
const PESTANAS = [
  { clave: 'compra', texto: 'Recepción de compras', icono: PackagePlus },
  { clave: 'produccion', texto: 'Producción', icono: Factory },
  { clave: 'despacho', texto: 'Despacho a clientes', icono: Truck },
  { clave: 'mermas', texto: 'Mermas y bajas', icono: Trash2 },
];

export default function OperacionesPage() {
  const [params, setParams] = useSearchParams();
  const activa = PESTANAS.some((p) => p.clave === params.get('tab')) ? params.get('tab') : 'compra';

  const seleccionar = (clave) => setParams({ tab: clave }, { replace: true });

  const moverConTeclado = (evento, indice) => {
    if (!['ArrowRight', 'ArrowLeft'].includes(evento.key)) return;
    const siguiente = (indice + (evento.key === 'ArrowRight' ? 1 : -1) + PESTANAS.length) % PESTANAS.length;
    seleccionar(PESTANAS[siguiente].clave);
    document.getElementById(`pestana-${PESTANAS[siguiente].clave}`)?.focus();
  };

  return (
    <>
      <Encabezado
        titulo="Operaciones de planta"
        descripcion="Cada registro genera su movimiento en el Kardex con su usuario como responsable."
      />

      <Panel>
        <div role="tablist" aria-label="Operaciones" className="flex overflow-x-auto border-b border-linea">
          {PESTANAS.map(({ clave, texto, icono: Icono }, i) => {
            const seleccionada = activa === clave;
            return (
              <button
                key={clave}
                id={`pestana-${clave}`}
                type="button"
                role="tab"
                aria-selected={seleccionada}
                aria-controls={`panel-${clave}`}
                tabIndex={seleccionada ? 0 : -1}
                onClick={() => seleccionar(clave)}
                onKeyDown={(e) => moverConTeclado(e, i)}
                className={`-mb-px inline-flex min-h-12 shrink-0 items-center gap-2 border-b-[3px] px-4 font-medium ${
                  seleccionada ? 'border-petroleo text-petroleo' : 'border-transparent text-acero hover:text-tinta'
                }`}
              >
                <Icono className="size-4" aria-hidden />
                {texto}
              </button>
            );
          })}
        </div>

        <div role="tabpanel" id={`panel-${activa}`} aria-labelledby={`pestana-${activa}`}>
          {activa === 'compra' && <IngresoCompraForm insumoInicial={params.get('insumo') ?? ''} />}
          {activa === 'produccion' && <ProduccionForm />}
          {activa === 'despacho' && <DespachoForm />}
          {activa === 'mermas' && <MermaForm codigoInicial={params.get('lote') ?? ''} />}
        </div>
      </Panel>
    </>
  );
}
